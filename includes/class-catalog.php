<?php
/**
 * Every issue the plugin can report, in one place: what it is, why it
 * matters for AI search, and what to do. Findings, recommendations, the
 * dashboard and notifications all read from here, so the wording is
 * consistent and every message answers "what happened → why it matters →
 * what to do".
 *
 * kind:  observed — measured on this site (requests, responses, the page itself)
 *        inferred — suggested by an AI model; never presented as measured fact
 * scope: which process owns the finding (it opens and resolves it)
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Catalog {

	/** code => [severity, kind, scope, group] */
	const CODES = array(
		// Access (page)
		'robots_blocked'        => array( 'critical', 'observed', 'coverage', 'access' ),
		'noindex'               => array( 'critical', 'observed', 'content', 'access' ),
		'noai'                  => array( 'info', 'observed', 'content', 'access' ),
		'http_error'            => array( 'critical', 'observed', 'content', 'access' ),
		'redirects'             => array( 'info', 'observed', 'content', 'access' ),
		'canonical_elsewhere'   => array( 'warning', 'observed', 'content', 'access' ),
		'bot_errors'            => array( 'critical', 'observed', 'coverage', 'access' ),
		'slow_for_bots'         => array( 'warning', 'observed', 'coverage', 'access' ),
		// Discovery (page)
		'never_crawled'         => array( 'warning', 'observed', 'coverage', 'discovery' ),
		'stale_crawl'           => array( 'info', 'observed', 'coverage', 'discovery' ),
		'training_only'         => array( 'info', 'observed', 'coverage', 'discovery' ),
		'orphan'                => array( 'warning', 'observed', 'content', 'discovery' ),
		'few_inlinks'           => array( 'info', 'observed', 'content', 'discovery' ),
		// Content (page)
		'thin_content'          => array( 'warning', 'observed', 'content', 'content' ),
		'no_subheadings'        => array( 'warning', 'observed', 'content', 'content' ),
		'no_question_headings'  => array( 'info', 'observed', 'content', 'content' ),
		'no_direct_answer'      => array( 'info', 'observed', 'content', 'content' ),
		'no_faq'                => array( 'info', 'observed', 'content', 'content' ),
		'no_lists'              => array( 'info', 'observed', 'content', 'content' ),
		'no_structured_data'    => array( 'warning', 'observed', 'content', 'trust' ),
		'schema_mismatch'       => array( 'warning', 'observed', 'content', 'trust' ),
		'no_author'             => array( 'info', 'observed', 'content', 'trust' ),
		'outdated'              => array( 'info', 'observed', 'content', 'trust' ),
		'missing_alt'           => array( 'info', 'observed', 'content', 'content' ),
		'no_meta_description'   => array( 'info', 'observed', 'content', 'content' ),
		// Inferred by RankyFy Content AI (page)
		'content_gaps'          => array( 'warning', 'inferred', 'inferred', 'content' ),
		'keyword_gaps'          => array( 'info', 'inferred', 'inferred', 'content' ),
		'faq_opportunities'     => array( 'info', 'inferred', 'inferred', 'content' ),
		// Site-wide (page_id 0)
		'site_noindex'          => array( 'critical', 'observed', 'site', 'access' ),
		'robots_blocks_search'  => array( 'critical', 'observed', 'site', 'access' ),
		'robots_blocks_user'    => array( 'warning', 'observed', 'site', 'access' ),
		'robots_blocks_training' => array( 'info', 'observed', 'site', 'access' ),
		'edge_blocks_bot'       => array( 'critical', 'observed', 'site', 'access' ),
		'impersonation'         => array( 'info', 'observed', 'site', 'security' ),
		'verification_stale'    => array( 'warning', 'observed', 'site', 'system' ),
		'worker_stalled'        => array( 'critical', 'observed', 'site', 'system' ),
		'no_sitemap_in_robots'  => array( 'info', 'observed', 'site', 'discovery' ),
	);

	const SEVERITY_WEIGHT = array(
		'critical' => 3,
		'warning'  => 2,
		'info'     => 1,
	);

	public static function meta( $code ) {
		$m = self::CODES[ $code ] ?? array( 'info', 'observed', 'content', 'content' );
		return array(
			'severity' => $m[0],
			'kind'     => $m[1],
			'scope'    => $m[2],
			'group'    => $m[3],
		);
	}

	public static function codes_in_scope( $scope ) {
		$out = array();
		foreach ( self::CODES as $code => $m ) {
			if ( $m[2] === $scope ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * Human text for a finding.
	 *
	 * @param array $d finding data: bot, n, value, items…
	 * @return array{title:string,why:string,action:string}
	 */
	public static function text( $code, array $d = array() ) {
		$bot   = isset( $d['bot'] ) ? Registry::label( $d['bot'] ) : '';
		$n     = (int) ( $d['n'] ?? 0 );
		$value = isset( $d['value'] ) ? (string) $d['value'] : '';
		switch ( $code ) {
			case 'robots_blocked':
				return array(
					/* translators: %s: crawler name */
					'title'  => sprintf( __( 'robots.txt blocks %s from this page', 'rankyfy-ai-crawlers' ), $bot ),
					'why'    => __( 'A crawler that is not allowed to read a page cannot use it to answer questions or cite it as a source.', 'rankyfy-ai-crawlers' ),
					/* translators: %s: robots.txt rule */
					'action' => sprintf( __( 'If this page should appear in AI answers, remove or narrow the rule "%s" in robots.txt (or in the SEO or security plugin that writes it).', 'rankyfy-ai-crawlers' ), $value ),
				);
			case 'noindex':
				return array(
					'title'  => __( 'The page asks not to be indexed (noindex)', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'AI search in ChatGPT, Copilot, Google AI Overviews and others relies on search indexes. A noindex page is left out of them, so it is rarely found or cited.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'If the page should be found, remove the noindex setting in your SEO plugin (Advanced / Robots meta for this page).', 'rankyfy-ai-crawlers' ),
				);
			case 'noai':
				return array(
					'title'  => __( 'The page opts out of AI use (noai)', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'Some AI services honour "noai"/"noimageai" robots directives and will not use this page.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Keep it if that is intended. Otherwise remove the directive from the page\'s robots meta tag or X-Robots-Tag header.', 'rankyfy-ai-crawlers' ),
				);
			case 'http_error':
				return array(
					/* translators: %s: HTTP status */
					'title'  => sprintf( __( 'The page answers with an error (HTTP %s)', 'rankyfy-ai-crawlers' ), $value ),
					'why'    => __( 'Crawlers drop pages that return errors, and AI assistants cannot quote what they cannot load.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Open the page and fix the error, or redirect the URL to the right page if it moved.', 'rankyfy-ai-crawlers' ),
				);
			case 'redirects':
				return array(
					/* translators: %s: target URL */
					'title'  => sprintf( __( 'The page redirects to %s', 'rankyfy-ai-crawlers' ), $value ),
					'why'    => __( 'Links and sitemaps that point at a redirecting URL waste crawl visits.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Link to the final URL directly and keep the redirect only for old links.', 'rankyfy-ai-crawlers' ),
				);
			case 'canonical_elsewhere':
				return array(
					'title'  => __( 'The canonical URL points to a different page', 'rankyfy-ai-crawlers' ),
					/* translators: %s: canonical URL */
					'why'    => sprintf( __( 'Search engines and AI crawlers treat %s as the real page and may ignore this one.', 'rankyfy-ai-crawlers' ), $value ),
					'action' => __( 'If this page has its own content, set its canonical URL to itself in your SEO plugin.', 'rankyfy-ai-crawlers' ),
				);
			case 'bot_errors':
				return array(
					/* translators: 1: crawler, 2: count */
					'title'  => sprintf( __( '%1$s got %2$d error responses on this page in the last 14 days', 'rankyfy-ai-crawlers' ), $bot, $n ),
					'why'    => __( 'These are real requests from the crawler that failed. Repeated failures teach crawlers to visit less often.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Check the status codes in the page\'s crawl history; fix server errors (5xx) first, then broken URLs (404).', 'rankyfy-ai-crawlers' ),
				);
			case 'slow_for_bots':
				return array(
					/* translators: %s: milliseconds */
					'title'  => sprintf( __( 'Slow for AI crawlers (%s ms on average)', 'rankyfy-ai-crawlers' ), number_format_i18n( $n ) ),
					'why'    => __( 'AI assistants fetching a page during a conversation give up on slow responses and use another source.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Make sure crawler requests are served from your page cache, and reduce what the page loads on the server.', 'rankyfy-ai-crawlers' ),
				);
			case 'never_crawled':
				return array(
					'title'  => __( 'No AI crawler has visited this important page', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'AI crawlers are visiting your site but not this page, so its content is unlikely to be used in AI answers.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Link to it from your home page or menu and from related posts, make sure it is in your XML sitemap, and check that robots.txt allows it.', 'rankyfy-ai-crawlers' ),
				);
			case 'stale_crawl':
				return array(
					'title'  => __( 'Updated since AI crawlers last read it', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'AI answers may still be based on the older version of this page.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Make sure the sitemap shows the new modified date and link to the page from a recently updated page.', 'rankyfy-ai-crawlers' ),
				);
			case 'training_only':
				return array(
					'title'  => __( 'Only training crawlers have read this page', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'Training crawlers collect data for future models; search crawlers (OAI-SearchBot, Claude-SearchBot, PerplexityBot) are what make a page citable in answers today.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Check that robots.txt allows AI search crawlers and give the page more internal links.', 'rankyfy-ai-crawlers' ),
				);
			case 'orphan':
				return array(
					'title'  => __( 'No other page links here', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'Crawlers discover pages by following links. A page nothing links to is found late or never.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Add links to it from related pages — see the suggested links for this page.', 'rankyfy-ai-crawlers' ),
				);
			case 'few_inlinks':
				return array(
					/* translators: %d: count */
					'title'  => sprintf( _n( 'Only %d page links here', 'Only %d pages link here', $n, 'rankyfy-ai-crawlers' ), $n ),
					'why'    => __( 'Internal links tell crawlers which pages matter and help them find the page again.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Link to it from two or three closely related pages using descriptive anchor text.', 'rankyfy-ai-crawlers' ),
				);
			case 'thin_content':
				return array(
					/* translators: %d: words */
					'title'  => sprintf( __( 'Thin content (%d words)', 'rankyfy-ai-crawlers' ), $n ),
					'why'    => __( 'AI assistants cite pages that answer a question completely. Short pages rarely contain the specific facts they quote.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Expand the page with the details a buyer or reader would ask about: specifics, examples, numbers, steps.', 'rankyfy-ai-crawlers' ),
				);
			case 'no_subheadings':
				return array(
					'title'  => __( 'No subheadings', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'Answer engines pull passages that sit under a clear heading. One long block of text is hard to quote.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Split the page into sections with H2 headings that say what each section answers.', 'rankyfy-ai-crawlers' ),
				);
			case 'no_question_headings':
				return array(
					'title'  => __( 'No headings phrased as questions', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'People ask AI assistants questions. Headings that match those questions make the answer below them easy to find and cite.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Rephrase a few headings as the questions readers ask ("How long does … take?") and answer each in the first sentence below.', 'rankyfy-ai-crawlers' ),
				);
			case 'no_direct_answer':
				return array(
					'title'  => __( 'The page does not open with a direct answer', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'AI answers favour pages that state the key point in the first 40–60 words.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Start with a two- or three-sentence summary that answers the main question, then go into detail.', 'rankyfy-ai-crawlers' ),
				);
			case 'no_faq':
				return array(
					'title'  => __( 'No FAQ section', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'Short question-and-answer pairs map directly onto the questions people ask assistants.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Add 3–6 real customer questions with short answers, and mark them up with FAQ structured data.', 'rankyfy-ai-crawlers' ),
				);
			case 'no_lists':
				return array(
					'title'  => __( 'Long page without lists or tables', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'Steps, comparisons and specifications in lists and tables are easy for AI to extract accurately.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Turn steps, options or specifications into a list or a table.', 'rankyfy-ai-crawlers' ),
				);
			case 'no_structured_data':
				return array(
					'title'  => __( 'No structured data', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'Structured data (schema.org) states facts — product, price, author, organisation — unambiguously for search engines and AI systems.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Enable schema output in your SEO plugin, or add the schema type that fits this page (Article, Product, FAQPage, LocalBusiness…).', 'rankyfy-ai-crawlers' ),
				);
			case 'schema_mismatch':
				return array(
					/* translators: %s: schema type */
					'title'  => sprintf( __( 'Structured data is missing the %s type', 'rankyfy-ai-crawlers' ), $value ),
					'why'    => __( 'The page has structured data, but not the type that describes what it is.', 'rankyfy-ai-crawlers' ),
					/* translators: %s: schema type */
					'action' => sprintf( __( 'Add %s structured data (most SEO and shop plugins can output it).', 'rankyfy-ai-crawlers' ), $value ),
				);
			case 'no_author':
				return array(
					'title'  => __( 'No author information', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'AI systems weigh expertise and trust. A named author with a short bio is a strong signal.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Fill in the author\'s biographical info in their WordPress profile and make sure the theme shows it.', 'rankyfy-ai-crawlers' ),
				);
			case 'outdated':
				return array(
					/* translators: %d: days */
					'title'  => sprintf( __( 'Not updated for %d days', 'rankyfy-ai-crawlers' ), $n ),
					'why'    => __( 'AI search prefers current information, especially for prices, statistics and how-tos.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Review the facts, update what changed and show the updated date.', 'rankyfy-ai-crawlers' ),
				);
			case 'missing_alt':
				return array(
					/* translators: %d: count */
					'title'  => sprintf( _n( '%d image has no alt text', '%d images have no alt text', $n, 'rankyfy-ai-crawlers' ), $n ),
					'why'    => __( 'Alt text is how crawlers understand images; it also adds descriptive context to the page.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Describe each meaningful image in one short sentence.', 'rankyfy-ai-crawlers' ),
				);
			case 'no_meta_description':
				return array(
					'title'  => __( 'No meta description', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'A clear summary helps search engines and AI tools understand the page at a glance.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Write a 1–2 sentence description in your SEO plugin.', 'rankyfy-ai-crawlers' ),
				);
			case 'content_gaps':
				return array(
					/* translators: %d: count */
					'title'  => sprintf( _n( 'AI suggests %d topic this page does not cover', 'AI suggests %d topics this page does not cover', $n, 'rankyfy-ai-crawlers' ), $n ),
					'why'    => __( 'Suggested by RankyFy Content AI from the page and its topic — not measured. Covering what readers expect makes the page a more complete source.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Review the suggested topics and add the ones that are relevant to your readers.', 'rankyfy-ai-crawlers' ),
				);
			case 'keyword_gaps':
				return array(
					/* translators: %d: count */
					'title'  => sprintf( _n( '%d related term is missing', '%d related terms are missing', $n, 'rankyfy-ai-crawlers' ), $n ),
					'why'    => __( 'Suggested by RankyFy Content AI. Related terms and entities help AI connect the page to the questions people ask.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Use the relevant terms naturally where they fit.', 'rankyfy-ai-crawlers' ),
				);
			case 'faq_opportunities':
				return array(
					/* translators: %d: count */
					'title'  => sprintf( _n( '%d question the page could answer', '%d questions the page could answer', $n, 'rankyfy-ai-crawlers' ), $n ),
					'why'    => __( 'Suggested by RankyFy Content AI — likely questions, not questions people were observed asking.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Answer the relevant ones in an FAQ section or under question headings.', 'rankyfy-ai-crawlers' ),
				);
			case 'site_noindex':
				return array(
					'title'  => __( 'The whole site asks search engines not to index it', 'rankyfy-ai-crawlers' ),
					'why'    => __( '"Discourage search engines from indexing this site" is on. Search indexes — and the AI search built on them — will drop the site.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Turn it off in Settings → Reading unless the site is not meant to be public.', 'rankyfy-ai-crawlers' ),
				);
			case 'robots_blocks_search':
				return array(
					/* translators: %s: crawler */
					'title'  => sprintf( __( 'robots.txt blocks %s from the whole site', 'rankyfy-ai-crawlers' ), $bot ),
					'why'    => __( 'This crawler decides which pages can be shown and cited in an AI assistant\'s search answers. Blocking it removes the site from those answers.', 'rankyfy-ai-crawlers' ),
					/* translators: %s: rule */
					'action' => sprintf( __( 'Remove the rule (%s) unless you deliberately want to stay out of AI search.', 'rankyfy-ai-crawlers' ), $value ),
				);
			case 'robots_blocks_user':
				return array(
					/* translators: %s: crawler */
					'title'  => sprintf( __( 'robots.txt blocks %s', 'rankyfy-ai-crawlers' ), $bot ),
					'why'    => __( 'This agent fetches your page when a person asks the assistant about it. Blocking it means the assistant answers without your page.', 'rankyfy-ai-crawlers' ),
					/* translators: %s: rule */
					'action' => sprintf( __( 'Remove the rule (%s) if you want users\' questions to bring your content into the conversation.', 'rankyfy-ai-crawlers' ), $value ),
				);
			case 'robots_blocks_training':
				return array(
					/* translators: %s: crawler */
					'title'  => sprintf( __( '%s (AI training) is blocked', 'rankyfy-ai-crawlers' ), $bot ),
					'why'    => __( 'This only affects whether your content is used to train future models; it does not remove you from AI search answers. Many sites choose this deliberately.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'No action needed if this is your choice.', 'rankyfy-ai-crawlers' ),
				);
			case 'edge_blocks_bot':
				return array(
					/* translators: %s: crawler */
					'title'  => sprintf( __( 'Your server or CDN refuses requests that identify as %s', 'rankyfy-ai-crawlers' ), $bot ),
					/* translators: 1: status, 2: status */
					'why'    => sprintf( __( 'A test request with this crawler\'s user agent got HTTP %1$s while a browser got HTTP %2$s. A firewall, CDN bot setting or security plugin is likely blocking AI crawlers, regardless of robots.txt.', 'rankyfy-ai-crawlers' ), $d['bot_status'] ?? '?', $d['browser_status'] ?? '?' ),
					'action' => __( 'Check "block AI bots" or bot-fight settings in your CDN (e.g. Cloudflare), your firewall and security plugins, and allow this crawler if you want to appear in AI answers.', 'rankyfy-ai-crawlers' ),
				);
			case 'impersonation':
				return array(
					/* translators: 1: count, 2: crawler */
					'title'  => sprintf( __( '%1$d requests pretended to be %2$s', 'rankyfy-ai-crawlers' ), $n, $bot ),
					'why'    => __( 'They used the crawler\'s name but did not come from its published addresses. Scrapers often disguise themselves as well-known crawlers.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Nothing to change for AI visibility. If the volume is high, your firewall can block requests that claim this name but fail verification.', 'rankyfy-ai-crawlers' ),
				);
			case 'verification_stale':
				return array(
					'title'  => __( 'Crawler verification data is out of date', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'The published crawler address lists could not be refreshed, so new crawler addresses may show as unverified.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'Make sure your server can make outgoing HTTPS requests. The plugin retries automatically.', 'rankyfy-ai-crawlers' ),
				);
			case 'worker_stalled':
				return array(
					'title'  => __( 'Background processing is not running', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'Crawler visits are still recorded, but history, verification and alerts are not being updated.', 'rankyfy-ai-crawlers' ),
					'action' => __( 'WP-Cron may be disabled. Ask your host to run wp-cron.php every 5 minutes with a real cron job.', 'rankyfy-ai-crawlers' ),
				);
			case 'no_sitemap_in_robots':
				return array(
					'title'  => __( 'robots.txt does not list your sitemap', 'rankyfy-ai-crawlers' ),
					'why'    => __( 'Crawlers read robots.txt first; a Sitemap line there helps every crawler find all your pages.', 'rankyfy-ai-crawlers' ),
					/* translators: %s: sitemap URL */
					'action' => sprintf( __( 'Add "Sitemap: %s" to robots.txt (most SEO plugins have a setting for it).', 'rankyfy-ai-crawlers' ), $value ),
				);
		}
		return array(
			'title'  => $code,
			'why'    => '',
			'action' => '',
		);
	}
}
