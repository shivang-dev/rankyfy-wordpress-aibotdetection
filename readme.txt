=== RankyFy AI SEO ===
Contributors: rankyfy
Tags: seo, ai, ai search, llms.txt, ai crawler
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which AI crawlers read your WordPress site, track visits from ChatGPT and Perplexity, publish llms.txt, and fix what keeps you out of AI search.

== Description ==

**RankyFy AI SEO brings AI search optimization to WordPress.** See exactly which AI crawlers — GPTBot, ClaudeBot, OAI-SearchBot, PerplexityBot, Google-Extended and 30+ more — read your site, track the visitors that ChatGPT, Perplexity, Gemini, Claude and Copilot send you, publish a clean llms.txt, control AI bot access through robots.txt, and fix the issues that keep your content out of AI answers.

AI SEO is to assistants what classic SEO is to search engines: being readable, verifiable and worth quoting when ChatGPT or Perplexity answers a question in your niche. This plugin gives you the measurement and the controls for it.

Install, activate, done — monitoring starts immediately and runs entirely on your own site. No account is needed, and nothing is sent anywhere unless you explicitly enable it.

### AI crawler analytics, verified

Anyone can claim to be GPTBot. Every request that claims a known AI crawler is verified against the operator's published IP ranges, by forward-confirmed reverse DNS, or by cryptographic signature (Web Bot Auth) — and impersonations are counted separately, never as crawls.

* Every AI crawler request logged: bot, page, response, timing.
* Per-crawler detail: purpose (model training, AI search, or fetching a page for a real user), daily trends, crawl sessions, the pages it reads.
* The dashboard answers the first question — are AI crawlers reading my site at all, and which pages have they never seen?

### Track AI traffic: visits from ChatGPT, Perplexity and more

AI referral tracking shows every AI referral — the visits that arrive from ChatGPT, Perplexity, Gemini, Claude, Copilot and other assistants — by engine and by landing page, right beside the crawl data for the same page. *Crawled vs. AI visits* highlights pages AI reads but never sends anyone to: usually a content problem, not a crawling one.

### llms.txt generator — plus llms-full.txt and ai.txt

llms.txt is a proposed standard file that gives AI systems a curated map of your most important content. RankyFy AI SEO generates it from what AI crawlers actually read on your site, keeps it updated as content changes, and leaves out pages that are noindex, erroring or blocked. llms-full.txt (full text) and ai.txt (training policy) are included. Your manual edits survive rebuilds, and nothing is served until you switch it on.

### Control AI bots: allow or block each crawler, eyes open

The Access Manager lets you block AI bots selectively — allow or block every AI crawler individually, grouped by operator, with the consequence of each choice spelled out — so you can block AI model training (GPTBot, ClaudeBot, Google-Extended) without disappearing from ChatGPT or Perplexity answers. Presets, a preview of the exact robots.txt lines, and nothing written until you save.

### AI readiness score: optimize for AI search (GEO / AEO)

Generative engine optimization starts with what AI systems can actually use. The AI SEO readiness score runs 18 checks — access, discovery, structure, depth, trust and internal linking — and ranks the open issues by impact, each with a plain-language fix and the pages to start with.

### Opportunities from your own crawl data

What should you do next? The Opportunities screen answers from observed data: crawlers you block whose assistants already send you visitors, important pages no AI crawler has read, pages crawled often but not answer-ready, and pages that error or respond slowly to crawlers.

### Publish check, alerts, and the posts list

The editor warns before you publish a page that is noindex, blocked by robots.txt, canonicalised elsewhere or too thin for AI search. Alerts (email or webhook — Slack, Teams, Google Chat) fire when something changes: a new AI crawler, robots.txt suddenly blocking AI search, impersonation attempts, a drop in your readiness score. The posts list gains a sortable AI column.

### Honest data: observed vs. suggested

Everything is labelled with where it comes from. Real requests, real server responses and your own content are marked *Observed*. AI assistants do not tell websites what people asked them, and this plugin never presents a generated suggestion as a real query.

### Private and local by default

The plugin ships with a bundled crawler registry and works completely offline. Visitors are never tracked; crawler addresses are stored truncated. Optional remote updates and the optional RankyFy account are both opt-in — see External Services below.

### Works beside your SEO plugin

RankyFy AI SEO complements Yoast SEO, Rank Math, SEOPress and All in One SEO rather than replacing them: classic SEO plugins optimize for search engine rankings, this plugin covers the AI SEO side — crawler access, AI traffic, llms.txt and answer-readiness. The publish check even reads your SEO plugin's noindex settings to warn you before a page silently drops out of AI search.

== Development ==

The plugin is developed in the open at https://github.com/shivang-dev/rankyfy-wordpress-aibotdetection — issues and pull requests are welcome there.

Everything in this package ships exactly as written: the PHP, the JavaScript (assets/js/admin.js, guard.js, log-worker.js) and the CSS are the hand-written source files themselves. There is no build step, no bundler, no transpiler and no minification, so no separate "source" version exists — what you read is what runs.

== External Services ==

The plugin contacts no external service by default. Each service below is used only after the administrator enables it, and each can be switched off again at any time.

**1. RankyFy crawler registry** (optional, off by default)

* Service: `https://dashboard.rankyfy.com/` — operated by RankyFy (https://rankyfy.com/)
* Purpose: fetches an updated registry of AI crawler identities and a consolidated copy of the operators' published network ranges, so new crawlers are recognised without a plugin update.
* When: only when "Keep crawler data updated from the internet" is enabled in Settings → Capture, or when the administrator clicks "Update now" on the registry screen. Checked at most twice a day.
* Data sent: a standard HTTP request whose user-agent contains the plugin version and the site's address. No visitor data, no content.
* Privacy policy: https://rankyfy.com/privacy-policy/ — Terms: https://rankyfy.com/terms/

**2. Crawler operators' published files** (optional, off by default; same switch as above)

* Purpose: verifying that a request claiming to be a known crawler really came from that crawler, by comparing it against the address lists and signing keys the operators themselves publish.
* Data sent: a plain, anonymous HTTP GET to a fixed public file; the user-agent names the plugin. No site content, no visitor data, nothing about your WordPress installation.
* When: at most once a day per file, in the background, and only while the opt-in is enabled.
* The published IP-range files fetched, and each operator's privacy policy:
    * OpenAI — `openai.com` (https://openai.com/policies/privacy-policy/)
    * Anthropic — `claude.com` (https://www.anthropic.com/legal/privacy)
    * Perplexity — `www.perplexity.com` (https://www.perplexity.ai/hub/legal/privacy-policy)
    * Google — `developers.google.com` (https://policies.google.com/privacy)
    * Microsoft Bing — `www.bing.com` (https://www.microsoft.com/privacy/privacystatement)
    * Apple — `search.developer.apple.com` (https://www.apple.com/legal/privacy/)
    * Common Crawl — `index.commoncrawl.org` (https://commoncrawl.org/privacy-policy)
    * DuckDuckGo — `duckduckgo.com` (https://duckduckgo.com/privacy)
* Web Bot Auth key directories: when a crawler signs its requests (RFC 9421), its public keys are fetched from the `/.well-known/http-message-signatures-directory` path of the host the crawler itself names in its Signature-Agent header — always over HTTPS, never to private addresses, capped per hour.
* Cloudflare's public edge list — `www.cloudflare.com/ips-v4` and `/ips-v6` (https://www.cloudflare.com/privacypolicy/, terms: https://www.cloudflare.com/website-terms/): fetched weekly so that sites behind Cloudflare can trust its visitor-address header without manual setup. The request carries no data about your site.

**3. RankyFy account services** (optional, requires the separate RankyFy SEO plugin and a connected account)

* Service: `https://dashboard.rankyfy.com/` — operated by RankyFy (https://rankyfy.com/)
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

= What is AI SEO? =

AI SEO — also called GEO (generative engine optimization), AEO (answer engine optimization), LLM SEO or AI visibility — is making your content findable and usable by AI systems — ChatGPT, Perplexity, Gemini, Claude, Copilot and Google's AI Overviews — the way classic SEO does for search engines. In practice that means letting the right AI crawlers in, structuring content so an assistant can lift an answer from it, and measuring what AI actually reads and sends back. This plugin covers exactly that, with real data from your own site.

= How do I know if ChatGPT or other AI bots visit my WordPress site? =

Install and activate — the dashboard starts listing every AI crawler request within minutes: which bot, which pages, how often, and whether the request really came from that bot. User-triggered fetches (ChatGPT-User, Claude-User, Perplexity-User) are shown separately: each one is a real conversation in which an assistant opened your page.

= What is llms.txt, and do I need one? =

llms.txt is a proposed standard: a Markdown file at your site root that gives AI systems a curated list of your most important pages with short descriptions. It is low-effort and risk-free — this plugin generates it from the pages AI crawlers actually read, keeps it current, and serves it only after you switch it on. ai.txt (your training policy) and llms-full.txt are included.

= What is the difference between llms.txt, robots.txt and an XML sitemap? =

robots.txt says who may crawl, an XML sitemap says what exists, and llms.txt says what matters: a curated, readable list of your best content for AI systems. They complement each other. This plugin manages the AI side of all three — crawler rules in robots.txt through the Access Manager, and a generated llms.txt built from what AI crawlers actually read.

= Do AI crawlers respect robots.txt? =

The major operators (OpenAI, Anthropic, Google, Microsoft, Perplexity) document that their crawlers honor robots.txt, and the plugin shows each crawler's documented policy next to its real behavior on your site. What robots.txt cannot stop is impersonation — requests faking a crawler's name — which is why every request here is verified and failures are counted separately.

= How do I stop AI bots from scraping my WordPress site? =

Use the "Block everything" preset in the Access Manager to disallow every known AI crawler in robots.txt, or block only model-training crawlers and keep AI search working. Be aware of the honest limit: robots.txt is a rule the polite crawlers follow, not a wall. The plugin tells you who keeps requesting despite a block, so you can escalate those networks to your firewall or CDN with evidence.

= Should I block GPTBot and other AI crawlers? =

It depends on which crawler and what you want. Blocking training crawlers (GPTBot, ClaudeBot, Google-Extended, CCBot) stops your content being used to train models and costs you little visibility. Blocking AI search crawlers (OAI-SearchBot, Claude-SearchBot, PerplexityBot) removes you from those assistants' answers — usually not what you want. The Access Manager spells out this trade-off per crawler, with its real traffic next to it, before anything is written to robots.txt.

= Can I block AI model training without losing ChatGPT traffic? =

Yes — that is what the "Allow search, block training" preset does. Operators use separate crawlers for training and for answering: block GPTBot and your pages stop feeding model training, while OAI-SearchBot and ChatGPT-User keep working so ChatGPT can still cite and open your pages.

= Can it tell me whether ChatGPT cites my site? =

It shows what can actually be measured on your site: which AI crawlers read which pages, when an assistant fetched a page for a real user, and which visits arrived from AI assistants. AI assistants do not share what people asked them, and the plugin never pretends otherwise — anything generated is clearly labelled as a suggestion, never as data.

= How do I rank in ChatGPT, Perplexity or Google AI Overviews? =

Nobody can guarantee placement inside an AI answer, and you should distrust any tool that claims to. What is measurable are the prerequisites: the right crawlers can reach you, your important pages have actually been read, and the content is structured so an assistant can lift an answer from it (direct answers under question headings, enough depth, working pages). That is exactly what the readiness score, the coverage data and the Opportunities screen keep in front of you.

= Why doesn't Google Analytics show my ChatGPT traffic? =

Analytics suites lump assistant click-throughs into generic referral traffic, and some AI visits carry no referrer at all. This plugin recognises the referrer and UTM patterns of ChatGPT, Perplexity, Gemini, Claude, Copilot and other assistants and reports those visits by engine and landing page — next to the crawl data for the same pages.

= Which AI crawlers does it detect? =

Over 30 out of the box, with a registry that can update without a plugin release: OpenAI (GPTBot, OAI-SearchBot, ChatGPT-User), Anthropic (ClaudeBot, Claude-SearchBot, Claude-User), Perplexity (PerplexityBot, Perplexity-User), Google (Googlebot, Google-Extended, Google-CloudVertexBot), Microsoft Bingbot, Meta, Amazonbot, Applebot-Extended, ByteDance Bytespider, Common Crawl CCBot, DuckAssistBot, Mistral, Cohere, You.com and more — plus detection of unrecognised bots and your own custom entries.

= Does it work with WooCommerce and custom post types? =

Yes. Every public post type is monitored, scored and eligible for llms.txt — products, docs, courses, anything. Importance signals include your shop and cornerstone pages, so the readiness checks focus where revenue is.

= Does this replace my SEO plugin? =

No, it runs beside it. Yoast SEO, Rank Math, SEOPress and All in One SEO handle classic search engine optimization; this plugin handles the AI side — crawler verification, AI referral tracking, llms.txt, crawler access and answer-readiness. It also reads your SEO plugin's noindex settings so the publish check can warn you before a page drops out of AI search.

= How do you know a request is really GPTBot? =

Each request claiming a known crawler is verified against the operator's published IP ranges, by forward-confirmed reverse DNS, or by a cryptographic signature (Web Bot Auth). Requests that fail verification are counted as impersonations, never as crawls. Range and key fetching require the remote-updates opt-in; reverse DNS works out of the box.

= Does the plugin phone home? =

No. By default everything runs on your own site with the bundled crawler registry. Remote registry updates and the RankyFy account are separate opt-ins, documented under External Services.

= Does it slow my site down? =

No. Classification runs early and costs well under a millisecond for ordinary visitors; only bot requests are recorded. Dashboard reads come from pre-aggregated rollup tables.

= What data is stored, and does anything leave my site? =

See Data Storage and External Services above. Visitors are never tracked, crawler addresses are stored truncated, and nothing leaves your site unless you enable it.

= Do I need a RankyFy account? =

No. Monitoring, verification, the readiness score, the access manager, llms.txt, opportunities and alerts are all local and free. An account adds AI content analysis of your pages.

= Does it work with my cache or CDN? =

Yes, with one caveat: cached pages are answered before WordPress runs, so those crawler requests are missed by the hook. The plugin detects common caches and can import your server's access log to fill the gap. Cloudflare's visitor-address header is recognised automatically.

== Screenshots ==

1. Dashboard — AI crawler hits, pages read, visits from AI assistants and the readiness score.
2. AI Crawlers — every request, verified, with daily trends per bot.
3. A crawler's detail: GPTBot's requests, verification and the pages it reads.
4. AI Referrals — visits from ChatGPT, Perplexity, Claude and more, beside the crawl data.
5. Readiness Score — 18 checks ranked by impact, each with how to fix it.
6. Access Manager — allow or block each AI crawler, with the trade-off spelled out.
7. llms.txt — the generated file, what is included and which crawlers fetch it.
8. Opportunities — what your own crawl data says to do next.
9. Important pages no AI crawler has read.
10. Recommendations, ordered by severity and how important the page is.
11. Alerts — new crawlers, blocked crawlers, impersonation, score drops.
12. Settings — capture, verification, the remote-updates opt-in, data and privacy.

== Changelog ==

= 1.1.1 =
* Accessibility: the view transition now honors the prefers-reduced-motion setting.
* New screenshots captured from the released interface, with updated captions.
* Documentation: rewritten description and a much larger FAQ (what AI SEO is, whether to block GPTBot, how to keep ChatGPT traffic while blocking training, what llms.txt is).

= 1.1.0 =
* Changed: all remote fetching (live crawler registry, operators' published address lists and signature keys, Cloudflare's edge list) is now off by default and enabled by a single explicit opt-in in Settings → Capture. The bundled registry is used until then.
* Changed: sharing unrecognised bot user agents with RankyFy is now off by default.
* New: Opportunities section computed from your own crawl data — blocked crawlers whose assistants send visitors, important pages never read by AI, pages crawled often but not answer-ready, pages erroring or slow for crawlers.
* Changed: Opportunities is now a top-level section of the RankyFy AI SEO menu.
* Changed: plugin renamed to RankyFy AI SEO.

= 1.0.0 =
* First release: crawler monitoring and verification, AI referrals, readiness score, access manager, llms.txt, publish check, alerts.
