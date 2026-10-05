# RankyFy AI Crawler Monitor — development notes

For RankyFy engineers. Site owners: see [README.md](README.md).

## Architecture

```
 visitor / crawler ──► WordPress ──► Tracker (request path, PHP)
                                       │ one regex for everyone; crawlers only:
                                       │ 1 INSERT after the response
                                       ▼
                      events ──► Worker (WP-Cron, every 5 min, locked, time-budgeted)
                                  verify → aggregate → alerts → inventory → analysis
                                  hourly: robots.txt, coverage/score, periodic alerts, registry sync
                                  daily: retention, probes, links, unknown-agent sharing, snapshot
                                       ▼
                      rollups ──► Analytics (read side, cached) ──► REST rankyfy-aib/v1 ──► admin.js
                                       ▲
 RankyFy gateway /api/wp/v1 ───────────┘
   GET  /ai-crawlers/registry   public — live registry + operators' published ranges
   POST /ai-crawlers/classify   account — unrecognised bot user agents (text only)
   POST /content/assist         account, metered — Content AI page analysis (existing)
   GET  /ai-visibility/*        account — AI Visibility (existing)
        │
        ▼
 contentai (Rust) src/aibotdetection: registry · ranges refresher · classifier · discovery
```

- **Standalone first.** Detection, verification, robots.txt analysis, page analysis, findings, scores, alerts and history are local and work with no account and no network.
- **No second account system.** Account calls reuse the RankyFy SEO plugin's connection (`\Rankyfy\Auth::request`) when it is installed and connected. The plugin holds no keys. The gateway adds identity, ownership checks and the service key pair (see `rankyfy-seo/DEVELOPMENT.md`).
- **No second AI engine.** Keyword, intent, entity, FAQ, gap and link suggestions come from the existing Content AI `/content/assist`; prompts and mentions come from the existing AI Visibility module. This plugin adds the crawl data and the observed/inferred labelling.

## Where each piece runs, and why

The question was asked per component and answered with measurements.

| Component | Runs in | Reason |
|---|---|---|
| Recognising a crawler | PHP, in the request | It must happen where the request arrives. A network call per page view would cost ~1,000× the detection itself, and stock PHP cannot run WASM. Measured: **1.2 µs** per classification (one compiled regex of every token, from an autoloaded option). **0 extra DB queries** for ordinary visitors (32 → 32 per page view, A/B with the plugin on/off); +0.3–0.8 ms wall time, within noise. |
| Recording a crawler request | PHP, after the response | 1 INSERT (+1 for a queued verification). Runs as the last shutdown callback after `fastcgi_finish_request()` where available. Crawler request p50 39.6 ms vs. 38.2 ms for a visitor in the A/B run. |
| Address verification | PHP (request) + RankyFy (data) | The lookup is an array index into pre-bucketed ranges (/16 for IPv4, /32 for IPv6). Fetching and parsing the 17 operator files happens centrally in Rust, so sites download one consolidated file. Sites fall back to fetching from the operators when RankyFy is unreachable. |
| Reverse DNS, Web Bot Auth signatures | PHP background worker | Slow network work never runs in a request. Verdicts are cached per (address, bot) for a week. Ed25519 uses `sodium_*` (core or `sodium_compat`). |
| Aggregation | PHP + SQL, set-based | `INSERT … SELECT … GROUP BY … ON DUPLICATE KEY UPDATE` per 5,000-event chunk, one transaction per chunk. **1M events in 51–74 s (13–20K events/s)**; the worker folds ~100K+ events per 5-minute run. |
| Dashboard reads | PHP + SQL over rollups | With 1M events and 5,000 pages (750K `daily` rows): overview 0.3–0.7 s uncached, page lists 2–22 ms, crawler detail ~1.1 s, history (365 days) 25 ms. Cached for 2–5 minutes behind a version counter. |
| Access-log parsing | JavaScript Web Worker in the owner's browser | The file never leaves their machine; only crawler lines are posted. Throughput **121–128 MB/s** (1M lines, 219 MB). |
| Log parsing in Rust → WASM | **Not used** | Same parser and filter in Rust (`regex` crate, `wasm-pack --release`, LTO): **51–53 MB/s, 2.3–2.4× slower** than the JS worker, even in its best case (count only, no records crossing the boundary). Copying text into WASM memory costs more than V8's regex engine saves. The benchmark stays in `tools/bench/` and is not shipped. |
| Server-side import | PHP | Lines are re-validated and re-classified with the live code path, one transaction per 2,000-line batch: **4,000 lines/s** (80 lines/s before the transaction). |
| Content analysis (facts, key terms, links) | PHP background worker | A few ms per page (DOMDocument, TF-IDF over the site's own document frequencies, inverted term index for link suggestions — never all-pairs). Rust would not change the bottleneck, which is fetching pages. |
| Registry, ranges, cross-site discovery | Rust (`contentai/src/aibotdetection`) | Central by nature: one registry for every install, ranges fetched once, sightings of unknown bots aggregated across sites. |
| Keyword, intent, entity, FAQ, gap suggestions | Existing Content AI (Rust) | Already built and metered; reused, not duplicated. |

Reproduce:

```sh
# page-view overhead (A/B, interleaved) and query counts: see tools/bench/README.md
RFAIB_ALLOW_DESTRUCTIVE_TESTS=1 wp eval-file wp-content/plugins/aibotdetection/tools/bench/scale.php 1000000 5000
node tools/bench/logparse-bench.js /path/to/access.log   # after building tools/bench/wasm-logparse
```

## Detection model

`Detector::match()` returns a class and `Detector::verify()` returns a verification state. Both are stored on every event.

| cls | meaning |
|---|---|
| `ai` | registered AI crawler/assistant |
| `search` | registered classic search crawler |
| `known` | other registered client (SEO tool, link preview, monitor) |
| `potential` | unregistered bot whose user agent suggests AI (registry `heuristics.ai_hint`) |
| `unknown` | unregistered bot (`heuristics.bot`) |
| `spoofed` | claims a registered crawler, failed verification |
| `human` | browser — never stored |

| vstate | meaning |
|---|---|
| `verified` | published range, forward-confirmed reverse DNS, or valid RFC 9421 signature |
| `failed` | checked and wrong → `cls = spoofed` |
| `pending` | queued for the worker; aggregation waits up to 15 min for it |
| `none` | the operator publishes no way to verify (user agent only) |

Rules that prevent false results:

- A request is called an impersonation only against ranges fetched within 7 days. Stale ranges never prove anything.
- When an operator allows DNS proof, a request outside its ranges goes to DNS before it is judged.
- A complete browser user agent (not "compatible;", not headless) is a browser even if a device name contains "bot" (CUBOT phones).
- When several tokens match, AI tokens beat search tokens and the longest token wins at the same position.
- Agents that send a browser user agent (Google-Agent) are recognised by published address only. The autoloaded bucket map is skipped if it would ever exceed 3,000 prefixes.
- Signed agents (`Signature-Agent`) have their signature verified in the background. A bad signature means failed; a missing key means none, never spoofed.

Only `ai` and `spoofed`/`potential` (and `search`, if the owner enables it) get per-page rows. Everything else is one counter upsert per day. Addresses flooding more than 600 unverified requests in 10 minutes stop getting per-page rows for an hour.

Privacy: the stored address is the network (/24, /48, or nothing) plus a keyed hash. The full address lives only in `verify_queue` until its check completes, and checks expire after 2 days.

## Registry

`config/registry.json` is the single source of truth and is identical to `contentai/src/aibotdetection/registry.json` (`tools/sync-registry.sh` copies it). Every entry has:

- id, name, provider and category
- user-agent patterns, robots tokens, and the `robots_only`, `ip_only` and `signed_only` flags
- verification: range files, rDNS suffixes and signature hosts
- docs, confidence (UA uniqueness), source (`official` = checked against operator docs on `verified_on`; `community`), and a description

Layers, where later ones win per id: the bundled file, then the live file from RankyFy (accepted only if `version` ≥ bundled and ≥ 10 valid entries), then entries the owner adds. Remote input is untrusted:

- ids, patterns, URLs (https only), categories and heuristics are validated;
- a heuristic that fails to compile falls back to the bundled one;
- ranges are accepted only for files the registry names.

To add a crawler:

1. Edit the plugin's `config/registry.json` and bump `version`.
2. Run `tools/sync-registry.sh`.
3. Run `cargo test --lib aibotdetection` in contentai (`validate()` also runs at service start).
4. Deploy contentai.

Installed sites pick it up within 12 hours. No plugin release is needed.

## Data model

All tables are prefixed `{$wpdb->prefix}rfaib_`. See `class-installer.php` for columns and indexes.

| Table | Grain | Written by | Retention |
|---|---|---|---|
| events | one crawler request | Tracker / Importer | `retention_events` (30 d) |
| verify_queue | pending rDNS/signature check (full address) | Tracker | until checked, max 2 d |
| ip_verdicts | (address hash, bot) verdict | Verifier | 2 weeks |
| agents | unrecognised user agent | Tracker | 180 d after last seen |
| referrals | day × assistant × page (counts only) | Tracker / Importer | `retention_history` (400 d) |
| daily | day × bot × page | Aggregator | `retention_history` |
| daily_pages | day × page, AI crawlers | Aggregator | `retention_history` |
| daily_bots | day × bot (+ counters for unlogged bots) | Aggregator / Tracker | `retention_history` |
| page_bots | first/last crawl per page × bot | Aggregator | pruned when untouched for `retention_history` |
| bots_seen | first/last visit per bot | Aggregator | — |
| sessions | crawl sessions (30-min gap, 6-h cap) | Aggregator | `retention_sessions` (180 d) |
| pages, page_terms, terms, links | inventory, key terms, document frequencies, link graph | Inventory / Analyzer | follows the site |
| findings | issue × page × bot with open/resolved/ignored lifecycle | Coverage / Analyzer / Rankyfy | resolved: 1 year |
| alerts | notifications | Alerts | 180 d |
| snapshots | daily metrics for trends | Analytics | `retention_history` |

Aggregation moves a watermark by compare-and-swap inside the same transaction as the rollups. A chunk is therefore folded in exactly once, even when two workers overlap (tested with two processes), and a crash rolls back both. Events still waiting for a verdict hold the watermark back for at most 15 minutes.

Options read on every page view are autoloaded and always present: `rfaib_settings`, `rfaib_matcher`, `rfaib_throttle`, `rfaib_iponly`, `rfaib_db_version` and `rfaib_secret`. A missing option costs a query per page view. Everything else is non-autoloaded. Schema changes bump `RFAIB_DB_VERSION`; `Installer::migrate()` runs data migrations (v2 backfilled `daily_pages` from `daily`).

## Findings, scores, recommendations, alerts

- `Catalog` defines every issue: severity, kind (`observed`/`inferred`), owning scope, area, and the text (title, why it matters, what to do). Findings, recommendations, the UI, emails and webhooks all render from it.
- `Findings::sync()` restates everything a scope owns for one page. What is no longer reported becomes `resolved` with a date (this is the "fixed issues" history). `ignored` sticks. Inserts are upserts, so overlapping runs are safe.
- `Scorer` gives transparent points out of 100: access 30, discovery 15, structure 20, depth 15, trust 12, linking 8. The site score is importance-weighted, with a penalty when AI search crawlers are blocked site-wide. It is never presented as a ranking prediction.
- `Alerts` uses dedupe keys with cooldowns. Page-level problems found during the first analysis pass (the baseline) are not announced one by one. `Notifier` sends email (critical / all / digest / off, capped at 12 a day) and a Slack-compatible webhook via `wp_safe_remote_post`.

## Observed vs. inferred

This is a product rule, enforced in code and UI:

- **Observed:** events, rollups, robots.txt, probes, page facts, key terms, entity mentions, link graph, referrals, user-triggered fetches (`ai_user` category).
- **Measured** (real data from elsewhere): Content Assist keyword volumes with `measured: true`; FAQ questions with `from_search: true`.
- **Inferred:** everything else from Content Assist, and AI Visibility prompts. The answers in AI Visibility are observed; the prompts are generated.
- **Template:** `Analytics::query_patterns()` builds subjects from the page's own title. Template ideas are labelled as such and never stored as queries.

The REST payloads keep these in separate keys (`observed` / `inferred`). Every UI block carries a provenance badge.

## Security

- **REST.** `rankyfy-aib/v1` uses cookie auth plus the REST nonce. Every route checks the capability (`manage_options`, filter `rfaib_capability`) and is rate limited per user. Arguments are validated or allow-listed. No full address, token or secret is ever returned (tested).
- **Untrusted text.** User agents and paths are scrubbed of control characters and length-bounded before storage (no log forging). The CSV export neutralises spreadsheet formulas. The UI inserts server values with `textContent` only.
- **Proxy headers.** These are honoured only when the connection comes from a configured trusted proxy CIDR, taking the right-most untrusted hop.
- **Outbound requests.** Webhook URLs must be https with a public hostname; they are re-checked at send time by `wp_safe_remote_post`. Key directories must be https. Range files go through `wp_safe_remote_get` with size limits.
- **Self-probes.** These carry an HMAC header so they are never recorded as visits.
- **Remote registry.** Validated before use (see Registry).

## Admin UI

`assets/js/admin.js` is plain DOM with no build step, hash-routed and scoped to `.rfaib-app`, sharing the RankyFy SEO visual language. Every view has loading, empty and error states. Charts follow these rules:

- **Timeline:** stacked columns with four categorical hues (AI search / fetched for users / training / other), validated for colour-vision deficiency. A legend and a table view are always present, because two hues are below 3:1 on white.
- **Ratios:** meters.
- **Trends:** single-series lines.
- **Status:** colours always come with an icon and a label.

Layout uses container queries; there is no horizontal scroll at 390 px.

## Testing

Run these in a disposable WordPress with the plugin active. The suites truncate the plugin's tables.

```sh
RFAIB_ALLOW_DESTRUCTIVE_TESTS=1 wp eval-file wp-content/plugins/aibotdetection/tests/run.php [name-filter]
cd contentai && cargo test --lib aibotdetection
cd rankyfy-backend && venv/bin/python -m pytest test_wp_gateway_aicrawlers.py test_wp_gateway.py test_wp_gateway_config.py
```

PHP (47 tests):

- known / unknown / potential / spoofed user agents and false positives;
- range, rDNS and signature verification;
- trusted-proxy spoofing;
- registry validation, including a hostile remote registry;
- every storage path, dedup, log injection, path normalisation;
- exact and idempotent aggregation, plus two concurrent aggregator processes;
- throttling, retention, log import;
- robots.txt semantics;
- inventory lifecycle, analysis, the findings lifecycle, scoring, link suggestions;
- alerts dedupe and baseline, email and webhook delivery;
- REST capabilities, nonces, validation, secrecy and rate limits;
- backend outage and timeout, registry updates;
- activation, upgrade, autoload footprint, uninstall.

Rust (17 tests) covers registry validity, the classifier (including browser false positives), CIDR sets, range freshness, the handlers, ETag and limits. Gateway (9 new, plus the 34 existing) covers the public cached registry, ETag, outage fallback, auth, payload cleaning, and batch and rate limits.

Lint: `php -l` on PHP 7.4 and 8.2; `node --check` on both scripts.

## Known limitations

- Requests answered by a page cache or CDN never reach WordPress. Import access logs (Settings) to cover them. Live tracking sees only what PHP serves; static files never reach PHP, and neither does a physical `robots.txt`.
- Several operators (Meta, ByteDance, Mistral, Cohere…) publish no verification data; their visits are "user agent only".
- AI assistants do not share the questions people ask. User-triggered fetches show *that* a conversation involved a page, not *what* was asked.
- Content analysis of unfetched pages does not run shortcodes or dynamic blocks; important pages are read as served.
- Discovery sightings in the Rust service are in memory (bounded at 20,000) and reset on restart.
- Range freshness depends on the operators' files. If they stop updating, impersonation verdicts stop after 7 days and the Technical screen says why.
