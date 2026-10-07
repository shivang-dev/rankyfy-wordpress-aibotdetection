<?php
/**
 * AI Readiness: 18 site-level checks, a weighted score out of 100 and an
 * issue queue ordered by how many points each fix would recover.
 *
 * Every check is built from data the plugin already measured — robots.txt,
 * server probes, the page analysis and its findings, observed crawler
 * requests — so each result can be traced back to evidence. Page checks are
 * measured over the important pages (all pages when none is important yet),
 * excluding issues the owner ignored. A check with nothing to judge yet
 * (no crawl history, no posts with authors…) is "not applicable" and leaves
 * the score instead of pulling it down; the owner can also accept a result
 * as intended (for example blocking training crawlers), which takes it out
 * of the score and the queue.
 *
 *   Access     34  indexable, AI search/user crawlers allowed, server lets them in, pages open, canonicals
 *   Discovery  26  sitemap, llms.txt, AI crawl coverage, internal links, crawler errors
 *   Content    26  depth, answer structure, questions, structured data, summaries
 *   Trust      14  authorship, freshness
 *
 * Not a prediction of rankings or citations — a list of things that are
 * known to keep content out of AI answers, and how much of the site they
 * affect.
 *
 * Page checks use the important pages once there are at least
 * MIN_IMPORTANT of them; on smaller or newer sites, where importance has
 * little to go on, every page counts.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Readiness {

	const OPTION = 'rfaib_readiness';
	const STATE  = 'rfaib_readiness_state';

	/** id => [group, weight, effort, finding codes] in display order */
	const CHECKS = array(
		'site_indexable'    => array( 'access', 8, 'quick', array( 'site_noindex' ) ),
		'ai_search_access'  => array( 'access', 8, 'quick', array( 'robots_blocks_search' ) ),
		'ai_user_access'    => array( 'access', 4, 'quick', array( 'robots_blocks_user' ) ),
		'edge_access'       => array( 'access', 5, 'medium', array( 'edge_blocks_bot' ) ),
		'pages_accessible'  => array( 'access', 6, 'medium', array( 'robots_blocked', 'noindex', 'http_error' ) ),
		'canonicals'        => array( 'access', 3, 'quick', array( 'canonical_elsewhere', 'redirects' ) ),
		'sitemap'           => array( 'discovery', 4, 'quick', array( 'no_sitemap_in_robots' ) ),
		'llms_txt'          => array( 'discovery', 5, 'quick', array() ),
		'ai_crawl_coverage' => array( 'discovery', 8, 'larger', array( 'never_crawled' ) ),
		'internal_links'    => array( 'discovery', 5, 'medium', array( 'orphan' ) ),
		'crawler_health'    => array( 'discovery', 4, 'medium', array( 'bot_errors', 'slow_for_bots' ) ),
		'content_depth'     => array( 'content', 6, 'larger', array( 'thin_content' ) ),
		'answer_structure'  => array( 'content', 6, 'medium', array( 'no_subheadings', 'no_direct_answer' ) ),
		'question_coverage' => array( 'content', 4, 'medium', array( 'no_question_headings', 'no_faq' ) ),
		'structured_data'   => array( 'content', 6, 'medium', array( 'no_structured_data', 'schema_mismatch' ) ),
		'summaries'         => array( 'content', 4, 'quick', array( 'no_meta_description', 'missing_alt' ) ),
		'authorship'        => array( 'trust', 6, 'quick', array( 'no_author' ) ),
		'freshness'         => array( 'trust', 8, 'medium', array( 'outdated', 'stale_crawl' ) ),
	);

	const GROUPS = array( 'access', 'discovery', 'content', 'trust' );

	const MIN_IMPORTANT = 5;

	/** The last computed result (computed now if there is none). */
	public static function get() {
		$r = get_option( self::OPTION );
		return is_array( $r ) && isset( $r['checks'] ) ? $r : self::compute();
	}

	/** Small version for the overview. */
	public static function summary() {
		$r = self::get();
		return array(
			'score'       => $r['score'],
			'groups'      => $r['groups'],
			'counts'      => $r['counts'],
			'queue'       => array_slice( $r['queue'], 0, 3 ),
			'open'        => count( $r['queue'] ),
			'quick'       => count( array_filter( $r['queue'], static function ( $q ) {
				return 'quick' === $q['effort'];
			} ) ),
			'computed_at' => $r['computed_at'],
		);
	}

	// ── evaluation ─────────────────────────────────────────────────────────

	/** Run all checks, update the queue state and store the result. */
	public static function compute() {
		$state = self::state();
		$prev  = get_option( self::OPTION );
		$scope = self::scope();
		$now   = time();

		$checks = array();
		foreach ( self::CHECKS as $id => $def ) {
			$c          = self::evaluate( $id, $scope );
			$c['id']    = $id;
			$c['group'] = $def[0];
			$c['weight'] = $def[1];
			$c['effort'] = $def[2];
			$c['codes'] = $def[3];
			if ( 'na' !== $c['status'] ) {
				$c['status'] = $c['frac'] >= 0.9 ? 'pass' : ( $c['frac'] >= 0.6 ? 'warn' : 'fail' );
			}
			$c['accepted'] = isset( $state['accepted'][ $id ] );
			// When it started failing (kept across runs), and when it got fixed.
			if ( in_array( $c['status'], array( 'warn', 'fail' ), true ) ) {
				if ( empty( $state['since'][ $id ] ) ) {
					$state['since'][ $id ] = $now;
				}
			} elseif ( ! empty( $state['since'][ $id ] ) ) {
				if ( 'pass' === $c['status'] ) {
					array_unshift( $state['resolved'], array( 'id' => $id, 'at' => $now, 'since' => (int) $state['since'][ $id ] ) );
				}
				unset( $state['since'][ $id ] );
			}
			$c['since'] = isset( $state['since'][ $id ] ) ? (int) $state['since'][ $id ] : null;
			$text       = self::text( $id, $c );
			if ( 'na' === $c['status'] ) {
				$text['evidence'] = self::na_evidence( $id, $text['evidence'] );
			}
			$checks[] = $c + $text;
		}
		$state['resolved'] = array_slice( $state['resolved'], 0, 30 );
		update_option( self::STATE, $state, false );

		// Score: points earned over the points that apply.
		$total  = 0;
		$earned = 0;
		$groups = array();
		foreach ( self::GROUPS as $g ) {
			$groups[ $g ] = array( 'weight' => 0, 'earned' => 0, 'max' => 0, 'score' => null );
		}
		foreach ( $checks as $c ) {
			$groups[ $c['group'] ]['max'] += $c['weight'];
			if ( 'na' === $c['status'] || $c['accepted'] ) {
				continue;
			}
			$total                           += $c['weight'];
			$earned                          += $c['weight'] * $c['frac'];
			$groups[ $c['group'] ]['weight'] += $c['weight'];
			$groups[ $c['group'] ]['earned'] += $c['weight'] * $c['frac'];
		}
		foreach ( $groups as $g => $v ) {
			$groups[ $g ]['score']  = $v['weight'] ? (int) round( 100 * $v['earned'] / $v['weight'] ) : null;
			$groups[ $g ]['earned'] = round( $v['earned'], 1 );
		}
		$score = $total ? (int) round( 100 * $earned / $total ) : null;

		// The queue: what to fix, biggest gain first, critical problems ahead of everything.
		$queue = array();
		foreach ( $checks as $c ) {
			if ( ! in_array( $c['status'], array( 'warn', 'fail' ), true ) || $c['accepted'] ) {
				continue;
			}
			$gain     = $total ? round( 100 * $c['weight'] * ( 1 - $c['frac'] ) / $total, 1 ) : 0;
			// Critical is kept for access problems: they keep content out of AI search entirely.
			if ( 'fail' === $c['status'] ) {
				$severity = 'access' === $c['group'] ? 'critical' : 'warning';
			} else {
				$severity = $gain >= 3 ? 'warning' : 'info';
			}
			$queue[]  = array(
				'id'       => $c['id'],
				'title'    => $c['issue'],
				'group'    => $c['group'],
				'status'   => $c['status'],
				'severity' => $severity,
				'gain'     => $gain,
				'effort'   => $c['effort'],
				'evidence' => $c['evidence'],
				'why'      => $c['why'],
				'action'   => $c['action'],
				'affected' => $c['n'],
				'of'       => $c['total'],
				'pages'    => $c['pages'],
				'codes'    => $c['codes'],
				'route'    => $c['route'],
				'since'    => $c['since'],
			);
		}
		$rank = array( 'critical' => 0, 'warning' => 1, 'info' => 2 );
		usort( $queue, static function ( $a, $b ) use ( $rank ) {
			return ( $rank[ $a['severity'] ] <=> $rank[ $b['severity'] ] ) ?: ( $b['gain'] <=> $a['gain'] );
		} );

		$counts = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'na' => 0, 'accepted' => 0 );
		foreach ( $checks as $c ) {
			$counts[ $c['accepted'] ? 'accepted' : $c['status'] ]++;
		}
		$result = array(
			'score'       => $score,
			'groups'      => $groups,
			'checks'      => $checks,
			'queue'       => $queue,
			'counts'      => $counts,
			'scope'       => array( 'basis' => $scope['basis'], 'important' => $scope['important'], 'pages' => $scope['pages'], 'content_pages' => $scope['content'] ),
			'resolved'    => array_map( static function ( $r ) {
				return $r + array( 'title' => self::text( $r['id'], array( 'n' => 0, 'total' => 0, 'value' => '' ) )['title'] );
			}, array_slice( $state['resolved'], 0, 10 ) ),
			'computed_at' => $now,
		);
		update_option( self::OPTION, $result, false );

		if ( is_array( $prev ) && null !== ( $prev['score'] ?? null ) && null !== $score && $prev['score'] - $score >= 10 ) {
			Alerts::raise(
				'readiness_drop',
				wp_date( 'Y-m-d' ),
				'warning',
				/* translators: 1: old score, 2: new score */
				sprintf( __( 'AI readiness fell from %1$d to %2$d', 'rankyfy-ai-crawlers' ), (int) $prev['score'], $score ),
				$queue ? $queue[0]['title'] : __( 'Several checks got worse at once.', 'rankyfy-ai-crawlers' ),
				__( 'A sudden drop usually comes from a site-wide change: robots.txt, a noindex setting, a CDN or security rule, a theme or plugin update.', 'rankyfy-ai-crawlers' ),
				array(),
				__( 'Open AI Readiness and start at the top of the issue queue.', 'rankyfy-ai-crawlers' ),
				'#/readiness',
				DAY_IN_SECONDS
			);
		}
		return $result;
	}

	private static function state() {
		$s = get_option( self::STATE );
		$s = is_array( $s ) ? $s : array();
		return array(
			'accepted' => (array) ( $s['accepted'] ?? array() ),
			'since'    => (array) ( $s['since'] ?? array() ),
			'resolved' => (array) ( $s['resolved'] ?? array() ),
		);
	}

	/** Accept a check's result as intended (out of the score and queue), or reopen it. */
	public static function set_status( $id, $status ) {
		if ( ! isset( self::CHECKS[ $id ] ) || ! in_array( $status, array( 'accepted', 'open' ), true ) ) {
			return false;
		}
		$s = self::state();
		if ( 'accepted' === $status ) {
			$s['accepted'][ $id ] = time();
		} else {
			unset( $s['accepted'][ $id ] );
		}
		update_option( self::STATE, $s, false );
		return true;
	}

	/** Which pages the page checks are measured over. */
	private static function scope() {
		global $wpdb;
		$t   = Installer::table( 'pages' );
		$min = (int) Settings::get( 'importance_min' );
		$imp = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE deleted = 0 AND pinned >= 0 AND (pinned > 0 OR importance >= %d)", $min ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE deleted = 0 AND pinned >= 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$basis = $imp >= self::MIN_IMPORTANT ? 'important' : 'all';
		$where = 'important' === $basis ? $wpdb->prepare( 'p.deleted = 0 AND p.pinned >= 0 AND (p.pinned > 0 OR p.importance >= %d)', $min ) : 'p.deleted = 0 AND p.pinned >= 0';
		// Content checks judge analysed posts, pages and products, not archives or the home page.
		$content_where = $where . " AND p.object_type = 'post' AND p.analyzed_at > 0 AND p.imp_reasons NOT LIKE '%home%'";
		$content       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} p WHERE {$content_where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'important' => $imp,
			'basis'     => $basis,
			'pages'     => 'important' === $basis ? $imp : $all,
			'content'   => $content,
			'where'     => $where,
			'content_where' => $content_where,
		);
	}

	/** Pages in scope with at least one open finding among $codes: count and the most important few. */
	private static function affected( array $codes, $where, $extra = '' ) {
		global $wpdb;
		$p  = Installer::table( 'pages' );
		$f  = Installer::table( 'findings' );
		$in = implode( ',', array_map( static function ( $c ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $c );
		}, $codes ) );
		$base = "FROM {$p} p WHERE {$where} {$extra} AND EXISTS (SELECT 1 FROM {$f} f WHERE f.page_id = p.id AND f.status = 'open' AND f.code IN ({$in}))";
		$n    = (int) $wpdb->get_var( "SELECT COUNT(*) {$base}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $n ? $wpdb->get_results( "SELECT p.id, p.path, p.title, p.importance {$base} ORDER BY p.pinned DESC, p.importance DESC LIMIT 10", ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			$n,
			array_map( static function ( $r ) {
				return array( 'page_id' => (int) $r['id'], 'path' => $r['path'], 'title' => $r['title'], 'importance' => (int) $r['importance'] );
			}, (array) $rows ),
		);
	}

	private static function page_check( array $codes, array $scope, $content = false, $extra = '' ) {
		global $wpdb;
		$where = $content ? $scope['content_where'] : $scope['where'];
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'pages' ) . " p WHERE {$where} {$extra}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $total ) {
			return array( 'status' => 'na', 'frac' => 1.0, 'n' => 0, 'total' => 0, 'pages' => array() );
		}
		list( $n, $pages ) = self::affected( $codes, $where, $extra );
		return array( 'status' => 'scored', 'frac' => 1 - $n / $total, 'n' => $n, 'total' => $total, 'pages' => $pages );
	}

	private static function binary( $ok, $value = '' ) {
		return array( 'status' => 'scored', 'frac' => $ok ? 1.0 : 0.0, 'n' => $ok ? 0 : 1, 'total' => 1, 'pages' => array(), 'value' => $value );
	}

	private static function na( $value = '' ) {
		return array( 'status' => 'na', 'frac' => 1.0, 'n' => 0, 'total' => 0, 'pages' => array(), 'value' => $value );
	}

	/** Crawlers in robots.txt allowed site-wide, among these categories. */
	private static function robots_share( array $cats, $priority_only ) {
		$m       = Robots::matrix();
		$total   = 0;
		$blocked = array();
		foreach ( (array) ( $m['bots'] ?? array() ) as $id => $x ) {
			$b = Registry::get( $id );
			if ( ! $b || ! in_array( $b['category'], $cats, true ) || ( $priority_only && ! Scorer::is_priority( $id ) ) ) {
				continue;
			}
			$total++;
			if ( ! $x['site_allowed'] ) {
				$blocked[] = $b['name'];
			}
		}
		if ( ! $total ) {
			return self::na();
		}
		return array( 'status' => 'scored', 'frac' => 1 - count( $blocked ) / $total, 'n' => count( $blocked ), 'total' => $total, 'pages' => array(), 'value' => implode( ', ', $blocked ) );
	}

	private static function evaluate( $id, array $scope ) {
		global $wpdb;
		$codes = self::CHECKS[ $id ][3];
		switch ( $id ) {
			case 'site_indexable':
				return self::binary( '0' !== (string) get_option( 'blog_public' ) );

			case 'ai_search_access':
				// Every AI search crawler, plus the classic search engines the owner marked as priority.
				$a = self::robots_share( array( 'ai_search' ), false );
				$b = self::robots_share( array( 'search' ), true );
				if ( 'na' === $a['status'] && 'na' === $b['status'] ) {
					return self::na();
				}
				$total = $a['total'] + $b['total'];
				$n     = $a['n'] + $b['n'];
				return array( 'status' => 'scored', 'frac' => 1 - $n / $total, 'n' => $n, 'total' => $total, 'pages' => array(), 'value' => trim( $a['value'] . ( $a['value'] && $b['value'] ? ', ' : '' ) . $b['value'] ) );

			case 'ai_user_access':
				return self::robots_share( array( 'ai_user', 'ai_agent' ), false );

			case 'edge_access':
				$probe = Probe::results();
				$tried = count( (array) ( $probe['results'] ?? array() ) );
				if ( false === ( $probe['available'] ?? null ) || ! $tried ) {
					return self::na();
				}
				$rows = $wpdb->get_col( "SELECT bot FROM " . Installer::table( 'findings' ) . " WHERE page_id = 0 AND code = 'edge_blocks_bot' AND status = 'open'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$n    = count( (array) $rows );
				return array( 'status' => 'scored', 'frac' => 1 - min( 1, $n / $tried ), 'n' => $n, 'total' => $tried, 'pages' => array(), 'value' => implode( ', ', array_map( array( Registry::class, 'label' ), (array) $rows ) ) );

			case 'sitemap':
				$m = Robots::matrix();
				if ( ! empty( $m['sitemaps'] ) ) {
					return self::binary( true, implode( ', ', array_slice( (array) $m['sitemaps'], 0, 3 ) ) );
				}
				$has = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' )
					|| ( function_exists( 'wp_sitemaps_get_server' ) && apply_filters( 'wp_sitemaps_enabled', '0' !== (string) get_option( 'blog_public' ) ) );
				// A sitemap that exists but is not announced in robots.txt is half the job.
				return array( 'status' => 'scored', 'frac' => $has ? 0.6 : 0.0, 'n' => 1, 'total' => 1, 'pages' => array(), 'value' => $has ? 'unlisted' : 'none' );

			case 'llms_txt':
				$live = Llms::llms_live();
				if ( null === $live ) {
					// Not checked yet: count it once it has been requested.
					return Settings::get( 'llms_enabled' ) ? array( 'status' => 'scored', 'frac' => 0.6, 'n' => 0, 'total' => 1, 'pages' => array(), 'value' => 'pending' ) : self::binary( false, 'off' );
				}
				return self::binary( $live, $live ? 'live' : ( Settings::get( 'llms_enabled' ) ? 'unreachable' : 'off' ) );

			case 'ai_crawl_coverage':
				$cov = Analytics::coverage( 30 );
				if ( ! empty( $cov['learning'] ) || ! (int) $cov['important'] ) {
					return self::na( 'learning' );
				}
				list( $n, $pages ) = self::affected( $codes, $scope['where'] );
				$total = (int) $cov['important'];
				return array( 'status' => 'scored', 'frac' => min( 1, (int) $cov['important_crawled'] / $total ), 'n' => $total - (int) $cov['important_crawled'], 'total' => $total, 'pages' => $pages );

			case 'internal_links':
				return self::page_check( $codes, $scope, false, "AND p.object_type <> 'home' AND p.imp_reasons NOT LIKE '%home%'" );

			case 'crawler_health':
				$ai = array();
				foreach ( Registry::bots() as $bid => $b ) {
					if ( $b['ai'] ) {
						$ai[] = $wpdb->prepare( '%s', $bid );
					}
				}
				if ( ! $ai ) {
					return self::na();
				}
				$r = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(hits) h, SUM(errors) e FROM ' . Installer::table( 'daily_bots' ) . ' WHERE day >= %s AND bot IN (' . implode( ',', $ai ) . ')', wp_date( 'Y-m-d', time() - 14 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$h = (int) ( $r['h'] ?? 0 );
				if ( $h < 20 ) {
					return self::na( 'little traffic' );
				}
				$rate = (int) $r['e'] / $h;
				$frac = $rate <= 0.02 ? 1.0 : ( $rate <= 0.05 ? 0.7 : ( $rate <= 0.10 ? 0.4 : 0.0 ) );
				list( $n, $pages ) = self::affected( $codes, $scope['where'] );
				if ( $n && $frac > 0.5 ) {
					$frac -= 0.2; // pages where AI crawlers keep failing or waiting
				}
				return array( 'status' => 'scored', 'frac' => $frac, 'n' => $n, 'total' => $scope['pages'], 'pages' => $pages, 'value' => round( 100 * $rate, 1 ) . '%' );

			case 'pages_accessible':
			case 'canonicals':
				return self::page_check( $codes, $scope );

			case 'authorship':
				return self::page_check( $codes, $scope, true, "AND p.subtype = 'post'" );

			case 'content_depth':
			case 'answer_structure':
			case 'question_coverage':
			case 'structured_data':
			case 'summaries':
			case 'freshness':
				return self::page_check( $codes, $scope, true );
		}
		return self::na();
	}

	// ── wording ────────────────────────────────────────────────────────────

	/** Why a check has nothing to judge. */
	private static function na_evidence( $id, $evidence ) {
		switch ( $id ) {
			case 'ai_crawl_coverage':
			case 'crawler_health':
				return $evidence; // already says what it is waiting for
			case 'edge_access':
				return __( 'Not tested yet, or the server cannot request its own pages (loopback requests are blocked).', 'rankyfy-ai-crawlers' );
			case 'ai_search_access':
			case 'ai_user_access':
				return __( 'No crawlers of this kind are in the registry.', 'rankyfy-ai-crawlers' );
			case 'authorship':
				return __( 'No analysed blog posts to judge yet.', 'rankyfy-ai-crawlers' );
		}
		return __( 'Nothing to judge yet: no pages of this kind have been analysed.', 'rankyfy-ai-crawlers' );
	}

	/**
	 * @param array $c result with n, total, value
	 * @return array{title:string,issue:string,evidence:string,why:string,action:string,route:string}
	 */
	public static function text( $id, array $c ) {
		$n     = (int) ( $c['n'] ?? 0 );
		$total = (int) ( $c['total'] ?? 0 );
		$value = (string) ( $c['value'] ?? '' );
		/* translators: 1: affected pages, 2: pages checked */
		$of = sprintf( __( '%1$s of %2$s pages', 'rankyfy-ai-crawlers' ), number_format_i18n( $n ), number_format_i18n( $total ) );
		switch ( $id ) {
			case 'site_indexable':
				return array(
					'title'    => __( 'Site is open to search indexing', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'The whole site asks search engines not to index it', 'rankyfy-ai-crawlers' ),
					'evidence' => $n ? __( '"Discourage search engines from indexing this site" is on.', 'rankyfy-ai-crawlers' ) : __( 'Search engines may index the site.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'ChatGPT search, Copilot, Google AI Overviews and Perplexity build on search indexes. A site that asks not to be indexed drops out of them.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Turn it off in Settings → Reading unless the site is not meant to be public.', 'rankyfy-ai-crawlers' ),
					'route'    => '',
				);
			case 'ai_search_access':
				return array(
					'title'    => __( 'AI search crawlers are allowed', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'robots.txt blocks AI search crawlers', 'rankyfy-ai-crawlers' ),
					/* translators: 1: blocked, 2: total, 3: names */
					'evidence' => $n ? sprintf( __( '%1$d of %2$d search crawlers are blocked site-wide: %3$s.', 'rankyfy-ai-crawlers' ), $n, $total, $value ) : __( 'Every AI search crawler may read the site.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'These crawlers decide which pages can be shown and cited in AI search answers. Blocking them removes the site from those answers.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Remove the Disallow rules for these crawlers in robots.txt (or in the SEO or security plugin that writes it), unless you deliberately want to stay out of AI search.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/technical',
				);
			case 'ai_user_access':
				return array(
					'title'    => __( 'Assistants may fetch pages for their users', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'robots.txt blocks assistants fetching pages for users', 'rankyfy-ai-crawlers' ),
					/* translators: %s: names */
					'evidence' => $n ? sprintf( __( 'Blocked: %s.', 'rankyfy-ai-crawlers' ), $value ) : __( 'ChatGPT-User, Claude-User, Perplexity-User and similar agents may fetch pages.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'These agents fetch a page when a person asks the assistant about it. Blocked, the assistant answers without your page.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Remove the robots.txt rules for these agents if you want users\' questions to bring your content into the conversation.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/technical',
				);
			case 'edge_access':
				return array(
					'title'    => __( 'Server and CDN let AI crawlers in', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Your server or CDN refuses AI crawlers', 'rankyfy-ai-crawlers' ),
					/* translators: 1: refused, 2: tested, 3: names */
					'evidence' => $n ? sprintf( __( '%1$d of %2$d crawlers tested were refused while a browser got through: %3$s.', 'rankyfy-ai-crawlers' ), $n, $total, $value ) : __( 'Test requests as AI crawlers got the same answer as a browser.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'A firewall, CDN bot setting or security plugin can block AI crawlers regardless of robots.txt.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Check "block AI bots" or bot-fight settings in your CDN (e.g. Cloudflare), firewall and security plugins, and allow the crawlers you want.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/technical',
				);
			case 'pages_accessible':
				return array(
					'title'    => __( 'Pages can be read and indexed', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Pages are blocked, noindex or erroring', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( '%s are blocked by robots.txt, set to noindex or answer with an error.', 'rankyfy-ai-crawlers' ), $of ) : __( 'No page checked is blocked, noindex or erroring.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'A page AI crawlers may not read, or that asks not to be indexed, cannot be used or cited.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Open each page and fix the cause shown: the robots.txt rule, the noindex setting in your SEO plugin, or the server error.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?severity=critical',
				);
			case 'canonicals':
				return array(
					'title'    => __( 'Pages are their own canonical URL', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Pages point their canonical elsewhere or redirect', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( '%s declare another page as canonical or redirect.', 'rankyfy-ai-crawlers' ), $of ) : __( 'Every page checked is its own canonical URL.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'Crawlers treat the canonical URL as the real page and may ignore this one.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Set the canonical URL to the page itself in your SEO plugin, and link to final URLs instead of redirecting ones.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=canonical_elsewhere',
				);
			case 'sitemap':
				return array(
					'title'    => __( 'XML sitemap is announced in robots.txt', 'rankyfy-ai-crawlers' ),
					'issue'    => 'none' === $value ? __( 'No XML sitemap', 'rankyfy-ai-crawlers' ) : __( 'robots.txt does not list the sitemap', 'rankyfy-ai-crawlers' ),
					'evidence' => $n ? ( 'none' === $value ? __( 'No sitemap was found: WordPress sitemaps are off and no SEO plugin provides one.', 'rankyfy-ai-crawlers' ) : __( 'A sitemap exists but robots.txt has no Sitemap line.', 'rankyfy-ai-crawlers' ) ) : sprintf( /* translators: %s: URLs */ __( 'Listed: %s', 'rankyfy-ai-crawlers' ), $value ),
					'why'      => __( 'Crawlers read robots.txt first; a Sitemap line there helps every crawler find all your pages.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Enable the sitemap in your SEO plugin and add a "Sitemap:" line to robots.txt (most SEO plugins have a setting).', 'rankyfy-ai-crawlers' ),
					'route'    => '#/technical',
				);
			case 'llms_txt':
				return array(
					'title'    => __( 'llms.txt guides language models', 'rankyfy-ai-crawlers' ),
					'issue'    => 'unreachable' === $value ? __( 'llms.txt is switched on but cannot be reached', 'rankyfy-ai-crawlers' ) : __( 'No llms.txt', 'rankyfy-ai-crawlers' ),
					'evidence' => 'live' === $value ? __( 'llms.txt answers at the root of the site.', 'rankyfy-ai-crawlers' ) : ( 'pending' === $value ? __( 'Switched on; it is checked within a day (or use "Check again").', 'rankyfy-ai-crawlers' ) : ( 'unreachable' === $value ? __( 'A request for /llms.txt did not get the file. Pretty permalinks may be off, or the server does not pass .txt requests to WordPress.', 'rankyfy-ai-crawlers' ) : __( 'The site has no /llms.txt.', 'rankyfy-ai-crawlers' ) ) ),
					'why'      => __( 'llms.txt is a short Markdown index of your most useful pages for AI assistants and agents. It is cheap to provide and points them at the right pages.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Switch it on in AI files, review the preview, then check that it answers.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/ai-files',
				);
			case 'ai_crawl_coverage':
				return array(
					'title'    => __( 'AI crawlers reach the important pages', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'AI crawlers are not reaching important pages', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => 'learning' === $value ? __( 'Needs 14 days of monitoring and some AI crawler visits first.', 'rankyfy-ai-crawlers' ) : ( $n ? sprintf( __( '%s were not requested by any AI crawler in the last 30 days.', 'rankyfy-ai-crawlers' ), $of ) : __( 'Every important page was crawled by AI in the last 30 days.', 'rankyfy-ai-crawlers' ) ),
					'why'      => __( 'Observed: content no AI crawler has read cannot be used in AI answers.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Link to these pages from the home page, menus and related posts, keep them in the sitemap and list them in llms.txt.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/pages?filter=uncrawled',
				);
			case 'internal_links':
				return array(
					'title'    => __( 'Pages are linked internally', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Pages have no internal links', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( 'No other page links to %s.', 'rankyfy-ai-crawlers' ), $of ) : __( 'Every page checked has internal links.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'Crawlers discover pages by following links. A page nothing links to is found late or never.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Add links from related pages — each page\'s detail view suggests where.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=orphan',
				);
			case 'crawler_health':
				return array(
					'title'    => __( 'AI crawlers get fast, error-free answers', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'AI crawlers are getting errors or slow responses', 'rankyfy-ai-crawlers' ),
					/* translators: 1: error rate, 2: pages */
					'evidence' => '' === $value || 'little traffic' === $value ? __( 'Not enough AI crawler requests in the last 14 days to judge.', 'rankyfy-ai-crawlers' ) : sprintf( __( '%1$s of AI crawler requests failed in the last 14 days; %2$s pages are slow or erroring for them.', 'rankyfy-ai-crawlers' ), $value, number_format_i18n( $n ) ),
					'why'      => __( 'Observed: repeated failures teach crawlers to visit less often, and assistants give up on slow pages during a conversation.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Fix server errors (5xx) first, then broken URLs; make sure crawler requests are served from the page cache.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=bot_errors',
				);
			case 'content_depth':
				return array(
					'title'    => __( 'Pages have substantial content', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Pages have thin content', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( '%s are thin for their type.', 'rankyfy-ai-crawlers' ), $of ) : __( 'No page checked is thin.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'AI assistants cite pages that answer a question completely; short pages rarely hold the facts they quote.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Expand these pages with specifics, examples, numbers and steps.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=thin_content',
				);
			case 'answer_structure':
				return array(
					'title'    => __( 'Pages are easy to quote', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Pages lack subheadings or a direct answer up top', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( '%s have no subheadings or do not open with a direct answer.', 'rankyfy-ai-crawlers' ), $of ) : __( 'Pages are split into sections and open with a summary.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'Answer engines pull passages that sit under a clear heading, and favour pages that state the key point in the first 40–60 words.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Start with a two- or three-sentence answer, then split the page with H2 headings that say what each section answers.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=no_direct_answer',
				);
			case 'question_coverage':
				return array(
					'title'    => __( 'Pages answer the questions people ask', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Pages have no question headings or FAQ', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( '%s have neither question headings nor an FAQ section.', 'rankyfy-ai-crawlers' ), $of ) : __( 'Pages use question headings or FAQ sections where it fits.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'People ask assistants questions; headings that match them make the answer below easy to find and cite.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Rephrase a few headings as real questions and add a short FAQ to long pages.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=no_faq',
				);
			case 'structured_data':
				return array(
					'title'    => __( 'Pages carry the right structured data', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Structured data is missing or the wrong type', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( '%s have no structured data or lack the type that describes them.', 'rankyfy-ai-crawlers' ), $of ) : __( 'Pages carry structured data of the right type.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'Structured data states facts — product, price, author, organisation — unambiguously for search engines and AI systems.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Enable schema output in your SEO plugin and add the type that fits (Article, Product, FAQPage, LocalBusiness…).', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=no_structured_data',
				);
			case 'summaries':
				return array(
					'title'    => __( 'Pages have descriptions and image alt text', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Meta descriptions or image alt text are missing', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( '%s have no meta description or many images without alt text.', 'rankyfy-ai-crawlers' ), $of ) : __( 'Pages have descriptions and described images.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'A clear summary and described images help AI tools understand a page at a glance; descriptions also feed llms.txt.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Write a 1–2 sentence description in your SEO plugin and describe each meaningful image.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=no_meta_description',
				);
			case 'authorship':
				return array(
					'title'    => __( 'Articles show who wrote them', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Articles have no author information', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( '%s have an author without a biography.', 'rankyfy-ai-crawlers' ), $of ) : __( 'Articles name an author with a biography.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'AI systems weigh expertise and trust. A named author with a short bio is a strong signal.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Fill in Biographical Info in each author\'s profile and make sure the theme shows it.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=no_author',
				);
			case 'freshness':
				return array(
					'title'    => __( 'Content is current, and AI has the current version', 'rankyfy-ai-crawlers' ),
					'issue'    => __( 'Pages are outdated or changed since AI last read them', 'rankyfy-ai-crawlers' ),
					/* translators: %s: "n of m pages" */
					'evidence' => $n ? sprintf( __( '%s were not updated for over a year, or changed after their last AI crawl.', 'rankyfy-ai-crawlers' ), $of ) : __( 'Pages are current and AI crawlers have read the latest versions.', 'rankyfy-ai-crawlers' ),
					'why'      => __( 'AI search prefers current information, and answers may still be based on an older version of a page.', 'rankyfy-ai-crawlers' ),
					'action'   => __( 'Review and update old pages; after updating, link to them from a fresh page and keep the sitemap\'s modified dates accurate.', 'rankyfy-ai-crawlers' ),
					'route'    => '#/recommendations?code=outdated',
				);
		}
		return array( 'title' => $id, 'issue' => $id, 'evidence' => '', 'why' => '', 'action' => '', 'route' => '' );
	}
}
