# RankyFy AI SEO for WordPress

See which AI crawlers and assistants visit your site, what they read and what they skip, which pages are blocked from them, and what to change so AI search can find and cite your content.

```
Install → Activate → Done
```

AI crawler monitoring works locally immediately, with the bundled crawler registry — nothing is contacted. Optional remote crawler-data updates (the live registry and the operators' published address lists) can be enabled in Settings → Capture. A RankyFy account is optional: it adds AI analysis of pages.

## Requirements

- WordPress 6.5 or newer, PHP 7.4 or newer
- WP-Cron running (the WordPress default), or a server cron calling `wp-cron.php`
- Optional: the RankyFy SEO plugin, connected to a RankyFy account, for AI analysis

## What you see

Open **RankyFy** in the admin menu (just below Settings).

| Screen | What it answers |
|---|---|
| **Dashboard** | AI crawler hits, pages ever read (and how many never were), visits from AI assistants, the share of verified hits; daily hits per crawler; the pages AI crawlers read most, with their top crawler and AI visits; your readiness score; visits by AI engine. In the first days, before any AI crawler arrives, it confirms capture is working, can email you on the first hit, runs a test request and lists what is worth doing meanwhile |
| **AI Crawlers** | Every AI crawler request, verified against published addresses. By bot: purpose (training vs. search), access, hits, pages, 30-day trend — click a row to filter. By page: requests per crawler and the last answer they got, with **Add redirect** on addresses that answer "not found". Unverified requests are counted separately. Click any address for its detail panel. Also links to unrecognised bots and the crawler registry |
| **AI Referrals** | Visits that arrived from ChatGPT, Perplexity, Gemini, Claude, Copilot…, by engine and landing page, beside the crawler hits for the same page; *crawled vs. AI visits* shows pages that are read by AI but never receive an AI visit |
| **Readiness Score** | 18 checks, a score out of 100 and the open issues ranked by impact; *How to fix* explains each one with the pages to start with; accept a deliberate choice so it stops counting; history of the score and fixed checks |
| **Access Manager** | Allow or block each AI crawler, grouped by operator, with its traffic and what blocking it costs. Presets (allow search, block training…), a preview of the exact robots.txt lines, nothing written until you save. RankyFy adds its own fenced block to robots.txt and touches nothing else |
| **llms.txt** | The generated `/llms.txt` (your pages AI crawlers read most, with descriptions), `/llms-full.txt` and `/ai.txt`: what's included, a minimum length, manual edits that survive rebuilds, whether each file answers and which crawlers fetched it |
| **Opportunities** | What the crawl data says to do next: crawlers you block whose assistants already send visitors, important pages no AI crawler has read, pages crawled often but not answer-ready, pages that error or respond slowly to crawlers — plus content suggestions, clearly labelled as AI-suggested |
| **Settings** | General (notifications, publish check, important pages), Capture (how requests are caught, log import, storage, verification), Compatibility (which plugin handles what), Data & privacy (what is stored and sent, exports, status) |

Deeper screens open from these: a crawler's detail, the page list and page detail, all recommendations, the technical robots.txt/server report, history and alerts.

The posts list gets one narrow **AI** column: requests from AI crawlers in the last 30 days and the page's readiness, sortable.

### Publish check (in the editor)

When you publish or update a page, the editor shows an **AI crawler check**:

- **noindex** from Yoast SEO, Rank Math, SEOPress or All in One SEO (for the page or its content type), or *Discourage search engines* in Settings → Reading
- **robots.txt** rules that block your priority AI crawlers from the page's address
- a **canonical URL** that points to another page
- an **address change** on a published page, with how often AI crawlers read the old address
- **thin content**, or an important page that lost most of its text

By default it warns. In **Settings → Publish check** you can make it ask for confirmation before publishing a page with a problem that keeps it out of AI search. Critical problems on important pages also raise an alert, whichever editor or tool published the page. The classic editor shows the same check in a side box.

When a published page's address changes (its slug or its parent page), the old address redirects permanently (301) to the new one, and so do the addresses of the pages below it. The redirect is used only when the old address would otherwise be *not found*, so a page you later publish at that address always wins. You can see and remove redirects under **Technical**, or turn them off in Settings.

### llms.txt and ai.txt

Nothing is served until you switch it on in **AI files**. `llms.txt` lists your most important pages first, with descriptions; it leaves out pages that are noindex, erroring, canonicalised elsewhere or closed to AI search crawlers. It updates when your content changes. A real `llms.txt` file in your site's root folder is served by the web server first and always wins; the screen tells you when that happens, and when another SEO plugin also generates one. `ai.txt` only states your training policy; **robots.txt still decides which crawlers may read the site.**

### Observed vs. suggested

Everything is labelled with where it comes from:

- **Observed** — measured on your site: real requests from crawlers, real server responses, your own content.
- **Measured** — real data from a measurement source, such as monthly search volume.
- **AI-suggested** — generated by RankyFy Content AI. Not a search or prompt anyone was observed making.
- **Template idea** — built from your page's own title with a fixed pattern. Not an observed search.

AI assistants do not tell websites what people asked them. The plugin never presents a suggestion as a real query. What it can show for real is that an assistant *fetched your page for a user* (ChatGPT-User, Claude-User, Perplexity-User…) and that people *clicked through* from an assistant.

### How sure is "GPTBot"?

Anyone can claim to be GPTBot. The plugin checks:

- **Verified** — the request came from the operator's published addresses, passed forward-confirmed reverse DNS, or carried a valid cryptographic signature (Web Bot Auth).
- **User agent only** — the operator publishes no way to verify it (for example Meta, ByteDance), or verification was not possible.
- **Impersonation** — it claimed a crawler's name but did not come from that crawler. These are counted separately and never treated as crawls.

## Notifications

By default you get an email for critical alerts (for example *robots.txt now blocks AI search crawlers*). In Settings you can choose every alert, a daily digest or none, and add a webhook (Slack, Teams, Google Chat or any HTTPS endpoint).

Alerts include: a new AI crawler, an important page crawled for the first time, important pages never crawled, pages no longer crawled, a crawler that stopped visiting, sharp rises or falls in activity, pages AI assistants keep fetching for their users, important pages that became blocked or return errors, robots.txt changes, your server/CDN refusing AI crawlers, impersonation, a drop in the readiness score, new possible AI crawlers and new AI-suggested content opportunities.

## Pages served from a cache or CDN

If a page cache or CDN serves a page, WordPress never sees the request, so the crawler visit is missed. Import your server or CDN access log in **Settings → Import a server access log** (Apache/Nginx combined format or JSON lines; `.gz` is fine). The file is read in your browser; only crawler lines are sent to your site, and they are checked again there. AI crawler requests are added page by page. Requests already recorded, or a file imported twice, are not counted again.

## Privacy

- Ordinary visitors are never recorded. For each visit the plugin does one quick text check of the browser's user agent and stops.
- For crawler requests it stores time, page, response, time taken, user agent and the **network** (address with the last part removed). Full addresses are kept only until verification completes.
- Visits from AI assistants are a daily count per page — nothing about the visitor.
- Retention is configurable (defaults: 30 days of individual requests, 400 days of daily history).
- With RankyFy connected, the plugin sends unrecognised bot user agents (text only) to help identify new AI crawlers — you can turn this off — and, only when you ask for an AI analysis, that page's text.
- Suggested privacy-policy text is added under **Settings → Privacy**.

## Troubleshooting

- **Numbers do not move / "Background processing has not run"** — WP-Cron is not running. Ask your host to call `wp-cron.php` every 5 minutes.
- **Few or no crawler visits although crawlers come** — a page cache or CDN answers them before WordPress. Import an access log, or check the **Technical** screen for blocks.
- **llms.txt shows "Not reachable"** — the server does not pass `.txt` requests to WordPress. Turn on pretty permalinks (Settings → Permalinks), or on Nginx make sure unknown files fall through to `index.php`. If WordPress runs in a subfolder, the file is served there, not at the domain root.
- **"Your server or CDN refuses requests that identify as …"** — check bot-protection settings in your CDN (for example Cloudflare's AI bot blocking), firewall and security plugins.
- **"Your site is behind a proxy or CDN that is not set up here"** — crawlers cannot be verified through an unknown proxy, so they show as "user agent only". Set the visitor address header and the proxy's address ranges in Settings. Cloudflare is recognised automatically.

Developers and RankyFy engineers: see [DEVELOPMENT.md](DEVELOPMENT.md).
