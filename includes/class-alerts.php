<?php
/**
 * Alert rules. Each alert answers: what happened → why it matters → which
 * pages → what to do. Alerts are deduplicated (a key plus a cooldown), and
 * page-level problems found while the plugin is still building its first
 * picture of the site (the baseline) are shown on the dashboard but not
 * announced one by one.
 *
 * Stream rules run every worker pass (new crawler, first crawl of an
 * important page); periodic rules run hourly (stopped crawlers, activity
 * changes, pages no longer crawled, repeated user-triggered fetches,
 * possible new AI agents).
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Alerts {

	const BASELINE = 'rfaib_baseline_done';

	/**
	 * Create an alert unless the same key fired within $cooldown seconds.
	 *
	 * @return int alert id or 0
	 */
	public static function raise( $rule, $key, $severity, $title, $what, $why, array $affected, $action, $route = '', $cooldown = WEEK_IN_SECONDS ) {
		global $wpdb;
		$t      = Installer::table( 'alerts' );
		$dedupe = substr( $rule . ':' . $key, 0, 190 );
		$recent = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE dedupe = %s AND created_at > %d LIMIT 1", $dedupe, time() - $cooldown ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $recent ) {
			return 0;
		}
		$wpdb->insert(
			$t,
			array(
				'rule'       => $rule,
				'dedupe'     => $dedupe,
				'severity'   => $severity,
				'title'      => Util::clean( $title, 255 ),
				'what'       => Util::clean( $what, 2000 ),
				'why'        => Util::clean( $why, 2000 ),
				'affected'   => wp_json_encode( array_slice( $affected, 0, 25 ) ),
				'action'     => Util::clean( $action, 2000 ),
				'link'       => substr( $route, 0, 255 ),
				'status'     => 'unread',
				'created_at' => time(),
			)
		);
		delete_transient( 'rfaib_unread' );
		Analytics::bust();
		return (int) $wpdb->insert_id;
	}

	private static function baseline_done() {
		if ( get_option( self::BASELINE ) ) {
			return true;
		}
		global $wpdb;
		$inv = Inventory::status();
		if ( ! empty( $inv['running'] ) || empty( $inv['finished'] ) ) {
			return false;
		}
		$dirty = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'pages' ) . ' WHERE deleted = 0 AND dirty = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 0 === $dirty ) {
			update_option( self::BASELINE, time(), false );
			return true;
		}
		return false;
	}

	private static function page_ref( array $p ) {
		return array(
			'label'   => (string) ( $p['title'] ?: $p['path'] ),
			'path'    => (string) $p['path'],
			'page_id' => (int) $p['id'],
		);
	}

	private static function pages_by_hash( array $hashes ) {
		global $wpdb;
		if ( ! $hashes ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, url_hash, path, title, importance, pinned FROM ' . Installer::table( 'pages' ) . " WHERE url_hash IN ({$in}) AND deleted = 0", $hashes ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['url_hash'] ] = $r;
		}
		return $out;
	}

	private static function is_important( array $p ) {
		return (int) $p['pinned'] > 0 || ( (int) $p['importance'] >= (int) Settings::get( 'importance_min' ) && (int) $p['pinned'] >= 0 );
	}

	// ── stream rules ───────────────────────────────────────────────────────

	public static function stream( array $new_bots, array $first_crawls ) {
		foreach ( array_keys( $new_bots ) as $id ) {
			$b = Registry::get( $id );
			if ( ! $b ) {
				continue;
			}
			$cat = Registry::data()['categories'][ $b['category'] ] ?? '';
			if ( 'ai_training' === $b['category'] ) {
				$action = __( 'Nothing is required. If you do not want your content used to train AI models, you can block this crawler in robots.txt — that does not remove you from AI search answers.', 'rankyfy-ai-crawlers' );
			} elseif ( in_array( $b['category'], array( 'ai_user', 'ai_agent' ), true ) ) {
				$action = __( 'Each of these visits is a real conversation that involved your site. Check which pages it fetched and make sure they answer the question directly.', 'rankyfy-ai-crawlers' );
			} else {
				$action = __( 'Make sure your most important pages are linked from the pages it read, and that robots.txt allows it.', 'rankyfy-ai-crawlers' );
			}
			self::raise(
				'new_bot',
				$id,
				'info',
				/* translators: 1: crawler, 2: company */
				sprintf( __( 'New AI crawler: %1$s (%2$s) visited your site', 'rankyfy-ai-crawlers' ), $b['name'], $b['provider'] ),
				/* translators: %s: crawler */
				sprintf( __( '%s made its first recorded visit to your site.', 'rankyfy-ai-crawlers' ), $b['name'] ),
				$cat . ' ' . $b['description'],
				array(),
				$action,
				'#/crawlers/' . $id,
				YEAR_IN_SECONDS
			);
		}

		if ( ! $first_crawls || ! self::baseline_done() ) {
			return;
		}
		$pages = self::pages_by_hash( array_values( array_unique( array_column( $first_crawls, 'url_hash' ) ) ) );
		$hits  = array();
		foreach ( $first_crawls as $f ) {
			$p = $pages[ $f['url_hash'] ] ?? null;
			if ( $p && self::is_important( $p ) ) {
				$hits[ $p['id'] ]['page']   = $p;
				$hits[ $p['id'] ]['bots'][] = Registry::label( $f['bot'] );
			}
		}
		if ( ! $hits ) {
			return;
		}
		$affected = array();
		foreach ( $hits as $h ) {
			$ref           = self::page_ref( $h['page'] );
			$ref['detail'] = implode( ', ', array_unique( $h['bots'] ) );
			$affected[]    = $ref;
		}
		$one = 1 === count( $hits ) ? reset( $hits ) : null;
		self::raise(
			'first_crawl',
			$one ? 'p' . $one['page']['id'] : 'batch' . md5( wp_json_encode( array_keys( $hits ) ) ),
			'info',
			$one
				/* translators: 1: page, 2: crawlers */
				? sprintf( __( '"%1$s" was read by %2$s for the first time', 'rankyfy-ai-crawlers' ), $one['page']['title'], implode( ', ', array_unique( $one['bots'] ) ) )
				/* translators: %d: count */
				: sprintf( __( '%d important pages were read by AI crawlers for the first time', 'rankyfy-ai-crawlers' ), count( $hits ) ),
			__( 'AI crawlers requested these important pages for the first time since monitoring began.', 'rankyfy-ai-crawlers' ),
			__( 'A page has to be read before an AI assistant can use or cite it. This is the first step towards appearing in AI answers.', 'rankyfy-ai-crawlers' ),
			$affected,
			__( 'Check the AEO recommendations for these pages so what the crawler read is easy to quote: a direct answer up top, clear headings, an FAQ.', 'rankyfy-ai-crawlers' ),
			$one ? '#/pages/' . $one['page']['id'] : '#/pages?filter=important',
			$one ? YEAR_IN_SECONDS : DAY_IN_SECONDS
		);
	}

	// ── called by Coverage / Robots ────────────────────────────────────────

	/** Important page got a serious new finding (after the baseline). */
	public static function page_findings_opened( array $p, array $opened ) {
		if ( ! self::baseline_done() ) {
			return;
		}
		$announce = array( 'robots_blocked', 'noindex', 'http_error', 'bot_errors' );
		foreach ( $opened as $o ) {
			list( $code, $bot ) = $o;
			if ( ! in_array( $code, $announce, true ) ) {
				continue;
			}
			$text = Catalog::text( $code, array( 'bot' => $bot, 'n' => 0, 'value' => '' ) );
			self::raise(
				'page_' . $code,
				$p['id'] . ':' . $bot,
				'critical',
				/* translators: 1: issue, 2: page */
				sprintf( __( '%1$s: %2$s', 'rankyfy-ai-crawlers' ), $p['title'] ?: $p['path'], $text['title'] ),
				/* translators: %s: page */
				sprintf( __( 'An important page (%s) changed in a way that keeps AI crawlers from using it.', 'rankyfy-ai-crawlers' ), $p['path'] ),
				$text['why'],
				array( self::page_ref( $p ) ),
				$text['action'],
				'#/pages/' . $p['id'],
				3 * DAY_IN_SECONDS
			);
		}
	}

	public static function site_findings_opened( array $opened ) {
		$announce = array( 'site_noindex', 'robots_blocks_search', 'robots_blocks_user', 'edge_blocks_bot', 'impersonation' );
		foreach ( Findings::site() as $f ) {
			$key = array( $f['code'], $f['bot'] );
			if ( ! in_array( $key, $opened, true ) || ! in_array( $f['code'], $announce, true ) ) {
				continue;
			}
			self::raise(
				'site_' . $f['code'],
				$f['bot'],
				$f['severity'],
				$f['title'],
				$f['title'],
				$f['why'],
				array(),
				$f['action'],
				'#/technical',
				3 * DAY_IN_SECONDS
			);
		}
	}

	public static function robots_changed( array $prev, array $now ) {
		$newly = array();
		$freed = array();
		foreach ( $now['bots'] as $id => $m ) {
			$was = $prev['bots'][ $id ]['site_allowed'] ?? true;
			if ( $was && ! $m['site_allowed'] ) {
				$newly[ $id ] = $m;
			} elseif ( ! $was && $m['site_allowed'] ) {
				$freed[ $id ] = $m;
			}
		}
		if ( $newly ) {
			$search  = array();
			$names   = array();
			foreach ( $newly as $id => $m ) {
				$b       = Registry::get( $id );
				$names[] = Registry::label( $id ) . ' (' . $m['rule'] . ')';
				if ( $b && in_array( $b['category'], array( 'ai_search', 'ai_user', 'search' ), true ) ) {
					$search[] = Registry::label( $id );
				}
			}
			self::raise(
				'robots_blocked',
				md5( implode( ',', array_keys( $newly ) ) ),
				$search ? 'critical' : 'warning',
				$search
					/* translators: %s: crawlers */
					? sprintf( __( 'robots.txt now blocks AI search crawlers: %s', 'rankyfy-ai-crawlers' ), implode( ', ', $search ) )
					: __( 'robots.txt now blocks more AI crawlers', 'rankyfy-ai-crawlers' ),
				/* translators: %s: list */
				sprintf( __( 'Your robots.txt changed. Newly blocked from the whole site: %s.', 'rankyfy-ai-crawlers' ), implode( '; ', $names ) ),
				$search
					? __( 'Blocked search crawlers cannot index your pages for AI answers, so your site will gradually disappear from ChatGPT search, Perplexity and similar answers.', 'rankyfy-ai-crawlers' )
					: __( 'Blocking training crawlers keeps your content out of future AI models but does not affect AI search.', 'rankyfy-ai-crawlers' ),
				array(),
				__( 'If this was not intended, check which plugin or CDN setting changed robots.txt (SEO plugins, security plugins and CDN "AI bot" settings can all write to it).', 'rankyfy-ai-crawlers' ),
				'#/technical',
				DAY_IN_SECONDS
			);
		}
		if ( $freed ) {
			self::raise(
				'robots_allowed',
				md5( implode( ',', array_keys( $freed ) ) ),
				'info',
				__( 'robots.txt now allows more AI crawlers', 'rankyfy-ai-crawlers' ),
				/* translators: %s: crawlers */
				sprintf( __( 'No longer blocked: %s.', 'rankyfy-ai-crawlers' ), implode( ', ', array_map( array( Registry::class, 'label' ), array_keys( $freed ) ) ) ),
				__( 'These crawlers can now read your site again.', 'rankyfy-ai-crawlers' ),
				array(),
				__( 'No action needed. Their visits should resume within days.', 'rankyfy-ai-crawlers' ),
				'#/technical',
				DAY_IN_SECONDS
			);
		}
	}

	// ── periodic rules ─────────────────────────────────────────────────────

	public static function periodic() {
		self::stopped_and_changed();
		self::repeated_user_fetches();
		self::pages_no_longer_crawled();
		self::never_crawled_summary();
		self::potential_agents();
	}

	/** Bots that stopped, and significant rises or falls in activity. */
	private static function stopped_and_changed() {
		global $wpdb;
		$d7  = wp_date( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
		$d35 = wp_date( 'Y-m-d', time() - 35 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT bot, SUM(IF(day >= %s, hits, 0)) recent, SUM(IF(day < %s, hits, 0)) before_ FROM ' . Installer::table( 'daily_bots' ) . ' WHERE day >= %s GROUP BY bot',
				$d7,
				$d7,
				$d35
			),
			ARRAY_A
		);
		$monitor_days = ( time() - (int) get_option( 'rfaib_monitoring_since', time() ) ) / DAY_IN_SECONDS;
		if ( $monitor_days < 21 ) {
			return; // not enough history for a fair comparison
		}
		foreach ( (array) $rows as $r ) {
			$b = Registry::get( $r['bot'] );
			if ( ! $b || ! $b['ai'] ) {
				continue;
			}
			$recent  = (int) $r['recent'];
			$weekly  = (int) $r['before_'] / 4;
			if ( $weekly >= 5 && 0 === $recent ) {
				self::raise(
					'bot_stopped',
					$b['id'],
					in_array( $b['category'], array( 'ai_search', 'ai_user' ), true ) ? 'warning' : 'info',
					/* translators: %s: crawler */
					sprintf( __( '%s stopped visiting your site', 'rankyfy-ai-crawlers' ), $b['name'] ),
					/* translators: 1: crawler, 2: weekly average */
					sprintf( __( '%1$s averaged %2$s requests a week over the previous month and has made none in the last 7 days.', 'rankyfy-ai-crawlers' ), $b['name'], number_format_i18n( $weekly ) ),
					__( 'A crawler that stops visiting usually hit a block: a robots.txt change, a firewall or CDN rule, or errors on your server.', 'rankyfy-ai-crawlers' ),
					array(),
					__( 'Check the Technical screen for robots.txt and firewall blocks, and the crawler\'s last requests for error responses.', 'rankyfy-ai-crawlers' ),
					'#/crawlers/' . $b['id'],
					2 * WEEK_IN_SECONDS
				);
				continue;
			}
			if ( $weekly >= 20 && $recent >= 2 * $weekly && $recent >= 50 ) {
				self::raise(
					'activity_up',
					$b['id'],
					'info',
					/* translators: %s: crawler */
					sprintf( __( '%s activity more than doubled', 'rankyfy-ai-crawlers' ), $b['name'] ),
					/* translators: 1: requests, 2: weekly average */
					sprintf( __( '%1$s requests in the last 7 days, against a weekly average of %2$s.', 'rankyfy-ai-crawlers' ), number_format_i18n( $recent ), number_format_i18n( $weekly ) ),
					'ai_user' === $b['category']
						? __( 'User-triggered fetches rising means your pages are coming up in more conversations.', 'rankyfy-ai-crawlers' )
						: __( 'More crawling usually follows new or updated content, or a crawler re-indexing the site.', 'rankyfy-ai-crawlers' ),
					array(),
					__( 'See which pages it is reading most — they are the ones to keep accurate and up to date.', 'rankyfy-ai-crawlers' ),
					'#/crawlers/' . $b['id'],
					WEEK_IN_SECONDS
				);
			} elseif ( $weekly >= 20 && $recent <= 0.4 * $weekly ) {
				self::raise(
					'activity_down',
					$b['id'],
					'warning',
					/* translators: %s: crawler */
					sprintf( __( '%s activity fell sharply', 'rankyfy-ai-crawlers' ), $b['name'] ),
					/* translators: 1: requests, 2: weekly average */
					sprintf( __( '%1$s requests in the last 7 days, against a weekly average of %2$s.', 'rankyfy-ai-crawlers' ), number_format_i18n( $recent ), number_format_i18n( $weekly ) ),
					__( 'Falling crawl activity can mean the crawler is meeting errors, slow responses or new blocks.', 'rankyfy-ai-crawlers' ),
					array(),
					__( 'Look for error responses and slow pages in its recent requests, and check robots.txt and firewall settings.', 'rankyfy-ai-crawlers' ),
					'#/crawlers/' . $b['id'],
					WEEK_IN_SECONDS
				);
			}
		}
	}

	/** Important pages fetched again and again by assistants on behalf of users (observed). */
	private static function repeated_user_fetches() {
		global $wpdb;
		$user_bots = array();
		foreach ( Registry::bots() as $id => $b ) {
			if ( 'ai_user' === $b['category'] ) {
				$user_bots[] = $id;
			}
		}
		if ( ! $user_bots ) {
			return;
		}
		$in   = implode( ',', array_fill( 0, count( $user_bots ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT d.url_hash, SUM(d.hits) h, GROUP_CONCAT(DISTINCT d.bot) bots FROM ' . Installer::table( 'daily' ) . " d WHERE d.day >= %s AND d.bot IN ({$in}) GROUP BY d.url_hash HAVING h >= 10 ORDER BY h DESC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ), $user_bots )
			),
			ARRAY_A
		);
		$pages = self::pages_by_hash( array_column( (array) $rows, 'url_hash' ) );
		foreach ( (array) $rows as $r ) {
			$p = $pages[ $r['url_hash'] ] ?? null;
			if ( ! $p ) {
				continue;
			}
			$bots = implode( ', ', array_map( array( Registry::class, 'label' ), explode( ',', $r['bots'] ) ) );
			self::raise(
				'hot_page',
				'p' . $p['id'],
				'info',
				/* translators: 1: page, 2: count */
				sprintf( __( 'AI assistants fetched "%1$s" %2$d times for their users', 'rankyfy-ai-crawlers' ), $p['title'] ?: $p['path'], (int) $r['h'] ),
				/* translators: %s: crawlers */
				sprintf( __( 'In the last two days %s fetched this page while answering people\'s questions.', 'rankyfy-ai-crawlers' ), $bots ),
				__( 'These are user-triggered fetches: real conversations in which an assistant looked at this page. It is one of your most valuable pages for AI visibility right now. (Which questions were asked is not shared with your site.)', 'rankyfy-ai-crawlers' ),
				array( self::page_ref( $p ) ),
				__( 'Keep this page accurate and current, put the key answer in the first paragraph, and link from it to related pages you also want cited.', 'rankyfy-ai-crawlers' ),
				'#/pages/' . $p['id'],
				WEEK_IN_SECONDS
			);
		}
	}

	/** Important pages that used to be crawled and no longer are. */
	private static function pages_no_longer_crawled() {
		global $wpdb;
		if ( ! self::baseline_done() ) {
			return;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.id, p.path, p.title, p.importance, p.pinned, MAX(pb.last_seen) last_seen FROM ' . Installer::table( 'pages' ) . ' p JOIN ' . Installer::table( 'page_bots' ) . ' pb ON pb.url_hash = p.url_hash
				 WHERE p.deleted = 0 AND (p.importance >= %d OR p.pinned > 0) AND pb.hits >= 3
				 GROUP BY p.id HAVING last_seen < %d AND last_seen > %d ORDER BY p.importance DESC LIMIT 25',
				(int) Settings::get( 'importance_min' ),
				time() - 30 * DAY_IN_SECONDS,
				time() - 120 * DAY_IN_SECONDS
			),
			ARRAY_A
		);
		$rows = array_filter( (array) $rows, array( __CLASS__, 'is_important' ) );
		if ( ! $rows ) {
			return;
		}
		self::raise(
			'page_dropped',
			md5( implode( ',', array_column( $rows, 'id' ) ) ),
			'warning',
			/* translators: %d: count */
			sprintf( _n( '%d important page is no longer being crawled', '%d important pages are no longer being crawled', count( $rows ), 'rankyfy-ai-crawlers' ), count( $rows ) ),
			__( 'AI crawlers used to visit these pages repeatedly but have not requested them in the last 30 days.', 'rankyfy-ai-crawlers' ),
			__( 'Pages that drop out of crawling go stale in AI indexes and are less likely to be cited.', 'rankyfy-ai-crawlers' ),
			array_map( array( __CLASS__, 'page_ref' ), array_values( $rows ) ),
			__( 'Check that the pages still return 200, are not blocked or noindexed, and are linked from pages crawlers still visit. Updating them with fresh information helps too.', 'rankyfy-ai-crawlers' ),
			'#/pages?filter=dropped',
			WEEK_IN_SECONDS
		);
	}

	private static function never_crawled_summary() {
		global $wpdb;
		if ( ! self::baseline_done() ) {
			return;
		}
		$rows = $wpdb->get_results(
			'SELECT p.id, p.path, p.title, p.importance, p.pinned FROM ' . Installer::table( 'findings' ) . ' f JOIN ' . Installer::table( 'pages' ) . " p ON p.id = f.page_id
			 WHERE f.code = 'never_crawled' AND f.status = 'open' AND p.deleted = 0 ORDER BY p.importance DESC LIMIT 25", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		if ( ! $rows ) {
			return;
		}
		$text = Catalog::text( 'never_crawled' );
		self::raise(
			'never_crawled',
			'weekly',
			'warning',
			/* translators: %d: count */
			sprintf( _n( '%d important page has never been visited by an AI crawler', '%d important pages have never been visited by an AI crawler', count( $rows ), 'rankyfy-ai-crawlers' ), count( $rows ) ),
			__( 'AI crawlers are active on your site, but these important pages have not been requested once since monitoring began.', 'rankyfy-ai-crawlers' ),
			$text['why'],
			array_map( array( __CLASS__, 'page_ref' ), $rows ),
			$text['action'],
			'#/pages?filter=never',
			WEEK_IN_SECONDS
		);
	}

	/** Unregistered bots whose user agent suggests AI, seen for the first time. */
	private static function potential_agents() {
		global $wpdb;
		$since = (int) get_option( 'rfaib_potential_checked', time() - HOUR_IN_SECONDS );
		update_option( 'rfaib_potential_checked', time(), false );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ua, hits FROM ' . Installer::table( 'agents' ) . " WHERE cls = 'potential' AND state = 'new' AND first_seen > %d ORDER BY hits DESC LIMIT 5", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			self::raise(
				'potential_bot',
				md5( $r['ua'] ),
				'info',
				__( 'Possible new AI crawler detected', 'rankyfy-ai-crawlers' ),
				/* translators: %s: user agent */
				sprintf( __( 'An unregistered automated client identifying as "%s" visited your site.', 'rankyfy-ai-crawlers' ), $r['ua'] ),
				__( 'Its user agent suggests an AI service, but it is not in the crawler registry, so it cannot be verified. New AI crawlers appear regularly.', 'rankyfy-ai-crawlers' ),
				array(),
				__( 'Review it on the Crawlers screen: mark it as an AI crawler to track its visits page by page, or ignore it.', 'rankyfy-ai-crawlers' ),
				'#/crawlers?tab=unknown',
				YEAR_IN_SECONDS
			);
		}
	}

	/** Called after the daily snapshot: score drops and new opportunities. */
	public static function after_snapshot( array $today, $yesterday ) {
		if ( ! is_array( $yesterday ) ) {
			return;
		}
		$a = $yesterday['aeo_score'] ?? null;
		$b = $today['aeo_score'] ?? null;
		if ( null !== $a && null !== $b && $a - $b >= 8 ) {
			self::raise(
				'score_drop',
				wp_date( 'Y-W' ),
				'warning',
				/* translators: 1: old, 2: new */
				sprintf( __( 'AI search readiness fell from %1$d to %2$d', 'rankyfy-ai-crawlers' ), $a, $b ),
				__( 'Your site\'s AEO score dropped by more than 8 points since yesterday.', 'rankyfy-ai-crawlers' ),
				__( 'A sudden drop usually comes from a site-wide change: robots.txt, a noindex setting, a theme or plugin update, or server errors.', 'rankyfy-ai-crawlers' ),
				array(),
				__( 'Open Recommendations and start with the critical items.', 'rankyfy-ai-crawlers' ),
				'#/recommendations',
				3 * DAY_IN_SECONDS
			);
		}
		$ca = (int) ( $yesterday['findings']['critical'] ?? 0 );
		$cb = (int) ( $today['findings']['critical'] ?? 0 );
		if ( $cb > $ca && $cb - $ca >= 3 && self::baseline_done() ) {
			self::raise(
				'critical_up',
				wp_date( 'Y-m-d' ),
				'critical',
				/* translators: %d: count */
				sprintf( __( '%d new critical AI-visibility issues', 'rankyfy-ai-crawlers' ), $cb - $ca ),
				/* translators: 1: before, 2: now */
				sprintf( __( 'Critical issues went from %1$d to %2$d since yesterday.', 'rankyfy-ai-crawlers' ), $ca, $cb ),
				__( 'Critical issues keep crawlers out of pages entirely (blocks, noindex, errors).', 'rankyfy-ai-crawlers' ),
				array(),
				__( 'Open Recommendations, filter by Critical, and fix them first.', 'rankyfy-ai-crawlers' ),
				'#/recommendations?severity=critical',
				DAY_IN_SECONDS
			);
		}
	}

	/** New AI-suggested opportunities for an important page (clearly labelled as inferred). */
	public static function opportunity( array $p, $n_gaps, $n_questions ) {
		if ( $n_gaps + $n_questions < 3 ) {
			return;
		}
		self::raise(
			'opportunity',
			'p' . $p['id'],
			'info',
			/* translators: %s: page */
			sprintf( __( 'New content opportunities for "%s"', 'rankyfy-ai-crawlers' ), $p['title'] ?: $p['path'] ),
			/* translators: 1: topics, 2: questions */
			sprintf( __( 'RankyFy Content AI suggests %1$d topics and %2$d questions this page could cover.', 'rankyfy-ai-crawlers' ), $n_gaps, $n_questions ),
			__( 'These are AI suggestions based on the page and its topic — not searches or prompts that were observed. They point at what a complete answer usually covers.', 'rankyfy-ai-crawlers' ),
			array( self::page_ref( $p ) ),
			__( 'Review the suggestions on the page\'s detail view and add what is relevant for your readers.', 'rankyfy-ai-crawlers' ),
			'#/pages/' . $p['id'],
			2 * WEEK_IN_SECONDS
		);
	}

	// ── reading ────────────────────────────────────────────────────────────

	public static function list( $status = '', $limit = 50, $offset = 0 ) {
		global $wpdb;
		$t     = Installer::table( 'alerts' );
		$where = $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : "WHERE status <> 'dismissed'";
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'present' ), (array) $rows );
	}

	public static function present( array $r ) {
		$aff = json_decode( (string) $r['affected'], true );
		return array(
			'id'         => (int) $r['id'],
			'rule'       => $r['rule'],
			'severity'   => $r['severity'],
			'title'      => $r['title'],
			'what'       => $r['what'],
			'why'        => $r['why'],
			'affected'   => is_array( $aff ) ? $aff : array(),
			'action'     => $r['action'],
			'route'      => $r['link'],
			'status'     => $r['status'],
			'created_at' => (int) $r['created_at'],
		);
	}

	public static function unread_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'alerts' ) . " WHERE status = 'unread'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, array( 'read', 'unread', 'dismissed' ), true ) ) {
			return false;
		}
		$t = Installer::table( 'alerts' );
		delete_transient( 'rfaib_unread' );
		if ( 'all' === $id ) {
			return false !== $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = %s WHERE status = 'unread'", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return false !== $wpdb->update( $t, array( 'status' => $status ), array( 'id' => (int) $id ) );
	}
}
