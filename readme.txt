=== RankyFy AI SEO ===
Contributors: rankyfy
Tags: seo, ai, ai search, llms.txt, ai crawler
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which AI crawlers and assistants read your site, where AI traffic lands, and what keeps your content out of AI search — beside your SEO plugin.

== Description ==

Install, activate, done — monitoring starts immediately and runs entirely on your own site. No account is needed, and nothing is sent anywhere unless you explicitly enable it.

**What you get:**

* **Dashboard** — AI crawler hits, pages ever read (and how many never were), visits from AI assistants, the share of verified hits, daily hits per crawler and your readiness score.
* **AI Crawlers** — every AI crawler request, verified. Anyone can claim to be GPTBot; requests are checked by published IP range, forward-confirmed reverse DNS or cryptographic signature, and impersonations are counted separately.
* **AI Referrals** — visits that arrived from ChatGPT, Perplexity, Gemini, Claude, Copilot and more, by engine and landing page. *Crawled vs. AI visits* shows pages that are read by AI but never receive an AI visit.
* **Readiness Score** — 18 checks, a score out of 100 and the open issues ranked by impact, each with how to fix it and the pages to start with.
* **Access Manager** — allow or block each AI crawler, grouped by operator, with its traffic and what blocking it costs spelled out. A preview of the exact robots.txt lines; nothing is written until you save.
* **llms.txt** — a generated `/llms.txt` listing the pages AI crawlers read most, plus `/llms-full.txt` and `/ai.txt`. Manual edits survive rebuilds. Nothing is served until you switch it on.
* **Opportunities** — what your own crawl data says to do next: crawlers you block whose assistants already send visitors, important pages no AI crawler has read, pages crawled often but not answer-ready, and pages that error or respond slowly to crawlers.
* **Publish check** — the editor warns before you publish a page that is noindex, blocked by robots.txt, canonicalised elsewhere or too thin for AI search.
* **Alerts** — email or webhook (Slack, Teams, Google Chat) when something changes: a new AI crawler, robots.txt now blocking AI search, impersonation, a drop in the readiness score and more.

**Observed vs. suggested.** Everything is labelled with where it comes from. Real requests, real server responses and your own content are marked *Observed*. AI assistants do not tell websites what people asked them, and this plugin never presents a generated suggestion as a real query.

**Local by default.** The plugin ships with a bundled crawler registry and works completely offline. Optional remote updates (the live registry and the operators' published address lists) and the optional RankyFy account are both opt-in — see External Services below.

== External Services ==

The plugin contacts no external service by default. Each service below is used only after the administrator enables it, and each can be switched off again at any time.

**1. RankyFy crawler registry** (optional, off by default)

* Service: `https://api.rankyfy.com/` — operated by RankyFy (https://rankyfy.com/)
* Purpose: fetches an updated registry of AI crawler identities and a consolidated copy of the operators' published network ranges, so new crawlers are recognised without a plugin update.
* When: only when "Keep crawler data updated from the internet" is enabled in Settings → Capture, or when the administrator clicks "Update now" on the registry screen. Checked at most twice a day.
* Data sent: a standard HTTP request whose user-agent contains the plugin version and the site's address. No visitor data, no content.
* Privacy policy: https://rankyfy.com/privacy-policy/ — Terms: https://rankyfy.com/terms/

**2. Crawler operators' published files** (optional, off by default; same switch as above)

* Services: the IP-range files and Web Bot Auth key directories that crawler operators publish on their own sites (for example `openai.com`, `anthropic.com`, `perplexity.ai`), and Cloudflare's public edge list (`cloudflare.com/ips-v4`, `/ips-v6`).
* Purpose: verifying that a request claiming to be a known crawler really came from that crawler.
* Data sent: a plain HTTP GET; the user-agent names the plugin. No visitor data, no content.

**3. RankyFy account services** (optional, requires the separate RankyFy SEO plugin and a connected account)

* Service: `https://api.rankyfy.com/` — operated by RankyFy (https://rankyfy.com/)
* Purpose and data sent: (a) if "Help identify new AI crawlers" is enabled, the user-agent text of unrecognised bots — nothing else; (b) when the administrator explicitly requests AI analysis of a page, that page's content, title and address.
* When: never without a connected account, and (b) only on an explicit per-page request or when automatic analysis is switched on.
* Privacy policy: https://rankyfy.com/privacy-policy/ — Terms: https://rankyfy.com/terms/

**4. Webhooks** (optional)

Alert notifications can be sent to one HTTPS endpoint the administrator configures (for example Slack or Microsoft Teams). Only the alert title and text are sent.

The plugin also makes one daily request to the site's own address to test how the server answers crawlers; that request never leaves the site's host.

== Data Storage ==

Stored locally, in the plugin's own database tables: for each crawler request the time, path, response code, response time, user agent and the network the request came from (the address with its last part removed, for example 203.0.113.0/24). Full addresses are kept only for a few minutes while a crawler's identity is verified, then discarded. Ordinary visitors are never recorded; a visit arriving from an AI assistant is kept only as a daily count per page, with no information about the visitor. Retention periods are configurable in Settings (raw requests default to 30 days) and everything is removed on uninstall.

The plugin adds suggested text for your site's privacy policy under Settings → Privacy.

== Installation ==

1. Install and activate the plugin.
2. Open **RankyFy AI SEO** in the admin menu (just below Settings). Monitoring is already running, fully locally.
3. Optional: enable remote crawler-data updates in Settings → Capture, switch on `llms.txt`, and review crawler access under **Access Manager**.

Requires WP-Cron running (the WordPress default), or a server cron calling `wp-cron.php`.

== Frequently Asked Questions ==

= Does the plugin phone home? =

No. By default everything runs on your own site with the bundled crawler registry. Remote registry updates and the RankyFy account are separate opt-ins, documented under External Services.

= Does it slow my site down? =

No. Classification runs early and costs well under a millisecond for ordinary visitors; only bot requests are recorded. Dashboard reads come from pre-aggregated rollup tables.

= How do you know a request is really GPTBot? =

Each request claiming a known crawler is verified against the operator's published IP ranges, by forward-confirmed reverse DNS, or by a cryptographic signature (Web Bot Auth). Requests that fail verification are counted as impersonations, never as crawls. Range and key fetching require the remote-updates opt-in; reverse DNS works out of the box.

= Can it tell me whether ChatGPT cites my site? =

It shows what can actually be measured on your site: which AI crawlers read which pages, when an assistant fetched a page for a user, and which visits arrived from AI assistants. AI assistants do not share what people asked them, and the plugin never pretends otherwise.

= What data is stored, and does anything leave my site? =

See Data Storage and External Services above. Nothing leaves your site unless you enable it.

= Do I need a RankyFy account? =

No. Monitoring, verification, the readiness score, the access manager, llms.txt, opportunities and alerts are all local. An account adds AI content analysis of your pages.

= Does it work with my cache or CDN? =

Cached pages are answered before WordPress runs, so those crawler requests are missed by the hook. The plugin detects common caches and can import your server's access log to fill the gap.

== Screenshots ==

1. Dashboard — AI crawler hits, pages read, visits from AI assistants and the readiness score.
2. AI Crawlers — every request, verified, by bot and by page.
3. A user-triggered fetch: ChatGPT opening a page for a real user.
4. A crawler's detail: GPTBot's requests, verification and the pages it reads.
5. Google's AI crawlers and agent fetches.
6. Googlebot's detail with its verification state.
7. Important pages no AI crawler has read.
8. Pages not crawled in the last 30 days.
9. A page's detail with its crawl history and findings.
10. Recommendations, ordered by severity and how important the page is.
11. Alerts — new crawlers, blocked crawlers, impersonation, score drops.
12. The technical robots.txt and server report.

== Changelog ==

= 1.1.0 =
* Changed: all remote fetching (live crawler registry, operators' published address lists and signature keys, Cloudflare's edge list) is now off by default and enabled by a single explicit opt-in in Settings → Capture. The bundled registry is used until then.
* Changed: sharing unrecognised bot user agents with RankyFy is now off by default.
* New: Opportunities section computed from your own crawl data — blocked crawlers whose assistants send visitors, important pages never read by AI, pages crawled often but not answer-ready, pages erroring or slow for crawlers.
* Changed: Opportunities is now a top-level section of the RankyFy AI SEO menu.
* Changed: plugin renamed to RankyFy AI SEO.

= 1.0.0 =
* First release: crawler monitoring and verification, AI referrals, readiness score, access manager, llms.txt, publish check, alerts.
