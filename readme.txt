=== RankyFy AI SEO ===
Contributors: rankyfy
Tags: seo, ai, rank tracking, llms.txt, search console
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which AI crawlers read your site, whether ChatGPT and Google AI cite you, and fix what holds you back — beside your SEO plugin.

== Description ==

Install, activate, done — monitoring starts immediately. Nothing to configure, no account needed.

**What you get:**

* **Dashboard** — AI crawler hits, pages ever read (and how many never were), visits from AI assistants, the share of verified hits, daily hits per crawler and your readiness score.
* **AI Crawlers** — every AI crawler request, verified against the operators' published addresses. Anyone can claim to be GPTBot; requests are checked by IP range, forward-confirmed reverse DNS or cryptographic signature, and impersonations are counted separately.
* **AI Referrals** — visits that arrived from ChatGPT, Perplexity, Gemini, Claude, Copilot and more, by engine and landing page. *Crawled vs. cited* shows pages that are read but never send visitors.
* **Readiness Score** — 18 checks, a score out of 100 and the open issues ranked by impact, each with how to fix it and the pages to start with.
* **Access Manager** — allow or block each AI crawler, grouped by operator, with its traffic and what blocking it costs spelled out. A preview of the exact robots.txt lines; nothing is written until you save.
* **llms.txt** — a generated `/llms.txt` listing the pages AI crawlers read most, plus `/llms-full.txt` and `/ai.txt`. Manual edits survive rebuilds.
* **Opportunities** — what your own crawl data says to do next: crawlers you block whose assistants already send visitors, important pages no AI crawler has read, pages crawled often but not answer-ready, and pages that error or respond slowly to crawlers.
* **Publish check** — the editor warns before you publish a page that is noindex, blocked by robots.txt, canonicalised elsewhere or too thin to be cited.
* **Alerts** — email or webhook (Slack, Teams, Google Chat) when something changes: a new AI crawler, robots.txt now blocking AI search, impersonation, a drop in the readiness score and more.

**Observed vs. suggested.** Everything is labelled with where it comes from. Real requests, real server responses and your own content are marked *Observed*. AI assistants do not tell websites what people asked them, and this plugin never presents a generated suggestion as a real query.

**Optional account.** A RankyFy account (through the RankyFy SEO plugin) adds AI content analysis of your pages. Everything above works without it, locally.

== Installation ==

1. Install and activate the plugin.
2. Open **RankyFy** in the admin menu (just below Settings). Monitoring is already running.
3. Optional: switch on `llms.txt` under **llms.txt**, and review crawler access under **Access Manager**.

Requires WP-Cron running (the WordPress default), or a server cron calling `wp-cron.php`.

== Frequently Asked Questions ==

= Does it slow my site down? =

No. Classification runs early and costs well under a millisecond for ordinary visitors; only bot requests are recorded. Reads for the dashboard come from pre-aggregated rollup tables.

= How do you know a request is really GPTBot? =

Each request claiming a known crawler is verified against the operator's published IP ranges, by forward-confirmed reverse DNS, or by a cryptographic signature (Web Bot Auth). Requests that fail verification are counted as impersonations, never as crawls.

= What data is stored, and does anything leave my site? =

For crawler requests: time, page, response, user agent and the network the request came from (the address with its last part removed). Full addresses are kept only a few minutes during verification. Ordinary visitors are not recorded. Nothing leaves your site unless you connect a RankyFy account, and then only what the Data & privacy screen lists.

= Do I need a RankyFy account? =

No. Monitoring, verification, the readiness score, the access manager, llms.txt, opportunities and alerts are all local. An account adds AI content analysis of your pages.

= Does it work with my cache or CDN? =

Cached pages are answered before WordPress runs, so those crawler requests are missed by the hook. The plugin detects common caches and can import your server's access log to fill the gap.

== Changelog ==

= 1.1.0 =
* New: Opportunities section computed from your own crawl data — blocked crawlers whose assistants send visitors, important pages never read by AI, pages crawled often but not answer-ready, pages erroring or slow for crawlers.
* Changed: Opportunities is now a top-level section of the RankyFy menu.
* Changed: plugin renamed to RankyFy AI SEO.

= 1.0.0 =
* First release: crawler monitoring and verification, AI referrals, readiness score, access manager, llms.txt, publish check, alerts.
