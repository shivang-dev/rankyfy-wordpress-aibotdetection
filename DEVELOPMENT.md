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
- Signed agents have their signature verified in the background. `Signature-Agent` counts only together with `Signature` and `Signature-Input`. A bad signature means failed; a missing or rotated key means none, never spoofed. Both header forms are handled: plain `"https://…"` and the dictionary `sig1="https://…"` with `;key=` component parameters.
- **Proxies and CDNs.**
  - Cloudflare's `CF-Connecting-IP` is used automatically, but only when the connection comes from Cloudflare's published ranges (refreshed weekly, with a bundled fallback).
  - Other proxies need the header and trusted ranges set in Settings.
  - If forwarding headers are present but not configured, live verification is skipped (`none`), so real crawlers are never called impostors. A site finding then explains the setup.
- **Reverse DNS** uses a PTR query (`dns_get_record`), so a lookup failure is retried rather than read as "no PTR". Failures are cached for 1 day, passes for 7.

Only `ai` and `spoofed`/`potential` (and `search`, if the owner enables it) get per-page rows. Everything else is one counter upsert per day. Addresses flooding more than 600 unverified requests in 10 minutes stop getting per-page rows for an hour.

Privacy: the stored address is the network (/24, /48, or nothing) plus a keyed hash. The full address lives only in `verify_queue` until its check completes, and checks expire after 2 days.

Imported logs add only per-page rows, which can be de-duplicated. They never add counters (search/SEO/unknown bots, user-agent hit counts). Each 2,000-line batch carries a key derived from the file (name, size, date) and the batch number. A batch already imported is skipped, so re-running an import changes nothing. Lines without a user agent are refused with a message, because Common Log Format cannot identify crawlers.

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
| snapshots | daily metrics for trends (incl. `readiness`) | Analytics | `retention_history` |
| redirects | old URL → post id, when a published URL changes | Guard | until removed, or the URL is published again |

Aggregation moves a watermark by compare-and-swap inside the same transaction as the rollups. Every statement is checked; `wpdb` never throws, so any error raises and rolls the chunk back. A chunk is therefore folded in exactly once, even when two workers overlap (tested with two processes). A failed statement or a crash rolls back both the rollups and the watermark.

Events still waiting for a verdict hold the watermark back, live or imported, until the verifier settles them or their check expires after 2 days. Pending events whose check is no longer queued are repaired.

Page analysis writes a page's terms, links and facts in one transaction. A deadlock with a concurrent re-check leaves the page queued for the next run.

Options read on every page view are autoloaded and always present: `rfaib_settings`, `rfaib_matcher`, `rfaib_throttle`, `rfaib_iponly`, `rfaib_db_version` and `rfaib_secret`. A missing option costs a query per page view. Everything else is non-autoloaded. Schema changes bump `RFAIB_DB_VERSION`; `Installer::migrate()` runs data migrations (v2 backfilled `daily_pages` from `daily`; v3 added the Cloudflare ranges option).

## Findings, scores, recommendations, alerts

- `Catalog` defines every issue: severity, kind (`observed`/`inferred`), owning scope, area, and the text (title, why it matters, what to do). Findings, recommendations, the UI, emails and webhooks all render from it.
- `Findings::sync()` restates everything a scope owns for one page. What is no longer reported becomes `resolved` with a date (this is the "fixed issues" history). `ignored` sticks. Inserts are upserts, so overlapping runs are safe.
- `Scorer` gives transparent points out of 100: access 30, discovery 15, structure 20, depth 15, trust 12, linking 8. The site score is importance-weighted, with a penalty when AI search crawlers are blocked site-wide. It is never presented as a ranking prediction.
- `Alerts` uses dedupe keys with cooldowns. Page-level problems found during the first analysis pass (the baseline) are not announced one by one. `Notifier` sends email (critical / all / digest / off, capped at 12 a day) and a Slack-compatible webhook via `wp_safe_remote_post`.

## AI readiness, publish guard, llms.txt

- **`Readiness`** — 18 site-level checks in `Readiness::CHECKS` (group, weight, effort, finding codes). Weights total 100: access 34, discovery 26, content 26, trust 14. Each check is built from existing evidence (robots matrix, probe results, open findings, `Analytics::coverage`, `daily_bots`, the llms.txt probe). There is no second analysis pass. Page checks count pages with an open finding (ignored findings do not count) over the important pages, or over all pages while fewer than `MIN_IMPORTANT` (5) are important. `na` (nothing to judge) and owner-*accepted* checks leave the denominator. The issue queue holds warn/fail checks. *Critical* is reserved for failing access checks; the rest are sorted by `gain` (points recovered on the 100 scale). The worker recomputes hourly into `rfaib_readiness`. `rfaib_readiness_state` keeps acceptances, the time each check started failing, and resolutions. A drop of ≥ 10 points raises `readiness_drop`. The site-level score supersedes `Scorer::site()` in the UI; per-page AEO scores are unchanged.
- **`Guard`** — `check()` runs five checks on a post, using unsaved editor values where given (title, content, slug, parent → predicted URL via a cloned post and `wp_unique_post_slug`). The checks are indexing, robots, canonical, url_change and content. SEO-plugin robots/canonical settings are read by `Guard::seo_meta()`, which `Analyzer` also uses for unfetched pages. Editor: `assets/js/guard.js` (pre-publish panel, document panel, post-update notice). In `confirm` mode, `lockPostSaving('rfaib-guard')` applies only while the pre-publish panel is mounted with an unacknowledged failure. REST `POST /guard/{id}` is allowed for anyone who can `edit_post` that post. `wp_after_insert_post` re-checks every publish after meta is saved and alerts (`publish_guard`) on failures for important pages or first publishes. Redirects: `post_updated` compares `get_permalink($before)` with `get_permalink($after)` and stores both the post and its hierarchical descendants. `template_redirect` (priority 9) reads the table only when `is_404()`. The target is resolved from the post id at serve time, so there are no chains. A loop deletes the row.
- **`Llms`** — serves `llms.txt`, `llms-full.txt` and `ai.txt` on `wp_loaded` when switched on. A request costs one `strpos` unless it is for one of them. Bodies are cached in `rfaib_llms_cache` (keyed by the relevant settings, 12 h max). `save_post`, deletions and name/tagline changes drop the cache. Responses carry `X-RankyFy-Generated` and an ETag. The comparison tolerates `W/` and Apache's `-gzip` suffix; `$_SERVER` values are `wp_unslash`ed because WordPress slashes them. `Llms::probe()` (daily and on demand) requests each address and records `ours` / `other` / `missing`. The readiness check passes for any reachable llms.txt.

## Observed vs. inferred

This is a product rule, enforced in code and UI:

- **Observed:** events, rollups, robots.txt, probes, page facts, key terms, entity mentions, link graph, referrals, user-triggered fetches (`ai_user` category).
- **Measured** (real data from elsewhere): Content Assist keyword volumes with `measured: true`; FAQ questions with `from_search: true`.
- **Inferred:** everything else from Content Assist, and AI Visibility prompts. The answers in AI Visibility are observed; the prompts are generated.
- **Template:** `Analytics::query_patterns()` builds subjects from the page's own title. Template ideas are labelled as such and never stored as queries.

The REST payloads keep these in separate keys (`observed` / `inferred`). Every UI block carries a provenance badge.

## Security

- **Backend.** The `aibotdetection` routes in contentai require the service's exact key pair, compared in constant time. **The service-wide API-key layer (`middleware/cors_origin.rs`) lets any pair of key headers through, which exposes every other contentai route. That needs fixing separately; the gateway never relies on it.**
- **REST.** `rankyfy-aib/v1` uses cookie auth plus the REST nonce. The UI renews an expired nonce once (`rest-nonce`) and retries. Every route checks the capability (`manage_options`, filter `rfaib_capability`) and is rate limited per user. Arguments are validated or allow-listed. No full address, token or secret is ever returned (tested).
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

PHP (55 tests, including regressions from review: Cloudflare and unknown-proxy handling, failed-verdict expiry, signature key rotation and the dictionary form, a failed statement rolling back a whole chunk, idempotent import, held imported events, and two concurrent page analyses):

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

Rust (19 tests) covers registry validity, the classifier (including browser false positives), CIDR sets, range freshness, the handlers, ETag, limits, the service-key guard and per-batch deduplication. Gateway (11 new, plus the 34 existing) covers:

- the public cached registry and ETag;
- outage fallback without hammering a failing upstream;
- auth, payload cleaning and non-finite numbers;
- batch and rate limits.

Lint: `php -l` on PHP 7.4 and 8.2; `node --check` on both scripts.

## Known limitations

- Behind a proxy other than Cloudflare, crawlers stay "user agent only" until the proxy header and trusted ranges are set in Settings.
- Requests answered by a page cache or CDN never reach WordPress. Import access logs (Settings) to cover them; imports add AI crawler requests (per page), not search/SEO bot counters. Live tracking sees only what PHP serves; static files never reach PHP, and neither does a physical `robots.txt`.
- Several operators (Meta, ByteDance, Mistral, Cohere…) publish no verification data; their visits are "user agent only".
- AI assistants do not share the questions people ask. User-triggered fetches show *that* a conversation involved a page, not *what* was asked.
- Content analysis of unfetched pages does not run shortcodes or dynamic blocks; important pages are read as served.
- Discovery sightings in the Rust service are in memory (bounded at 20,000) and reset on restart.
- Range freshness depends on the operators' files. If they stop updating, impersonation verdicts stop after 7 days and the Technical screen says why.
