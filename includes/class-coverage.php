<?php
/**
 * Joins what each page contains (Analyzer facts) with what crawlers did
 * (observed requests) to keep importance, the AEO score and findings current.
 * Runs for the pages just analysed and hourly for important pages, plus a
 * rolling slice of the rest, so a site with thousands of pages is covered
 * without a long job.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Coverage {

	const CURSOR = 'rfaib_coverage_cursor';

	public static function run( $budget = 20 ) {
		global $wpdb;
		$deadline = microtime( true ) + $budget;
		$t   = Installer::table( 'pages' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE deleted = 0 AND (importance >= %d OR pinned > 0) ORDER BY importance DESC LIMIT 2000", (int) Settings::get( 'importance_min' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cur = (int) get_option( self::CURSOR, 0 );
		$more = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE deleted = 0 AND id > %d ORDER BY id LIMIT 500", $cur ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( self::CURSOR, $more ? (int) end( $more ) : 0, false );
		$all = array_values( array_unique( array_map( 'intval', array_merge( (array) $ids, (array) $more ) ) ) );
		foreach ( array_chunk( $all, 200 ) as $chunk ) {
			if ( microtime( true ) > $deadline ) {
				break; // the rest is refreshed next hour (important pages come first)
			}
			self::refresh_pages( $chunk );
		}
		self::site();
		Analytics::bust();
	}

	/** Recompute importance, score and findings for these pages (one transaction). */
	public static function refresh_pages( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$wpdb->query( 'START TRANSACTION' );
		try {
			self::refresh_pages_tx( $ids );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	private static function refresh_pages_tx( array $ids ) {
		global $wpdb;
		$t    = Installer::table( 'pages' );
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE id IN ({$in}) AND deleted = 0", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return;
		}
		$hashes = array_column( $rows, 'url_hash' );
		$hin    = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// Observed crawl data for these pages.
		$crawls = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT url_hash, bot, first_seen, last_seen, hits, last_status FROM ' . Installer::table( 'page_bots' ) . " WHERE url_hash IN ({$hin})", $hashes ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$crawls[ $r['url_hash'] ][ $r['bot'] ] = $r;
		}
		$recent = array();
		$since  = wp_date( 'Y-m-d', time() - 14 * DAY_IN_SECONDS );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT url_hash, bot, SUM(hits) h, SUM(errors) e, SUM(ms_total) ms, MAX(last_status) st FROM ' . Installer::table( 'daily' ) . " WHERE url_hash IN ({$hin}) AND day >= %s GROUP BY url_hash, bot", array_merge( $hashes, array( $since ) ) ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$recent[ $r['url_hash'] ][ $r['bot'] ] = $r;
		}
		$refs = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT url_hash, SUM(hits) h FROM ' . Installer::table( 'referrals' ) . " WHERE url_hash IN ({$hin}) AND day >= %s GROUP BY url_hash", array_merge( $hashes, array( wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ) ) ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$refs[ $r['url_hash'] ] = (int) $r['h'];
		}

		$monitor_days = ( time() - (int) get_option( 'rfaib_monitoring_since', time() ) ) / DAY_IN_SECONDS;
		$ai_active    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'bots_seen' ) . ' WHERE last_seen > %d', time() - 14 * DAY_IN_SECONDS ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$learning     = $monitor_days < 14 || ! $ai_active;
		$min_imp      = (int) Settings::get( 'importance_min' );
		$priority     = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'priority_bots' ) ) ) );

		foreach ( $rows as $p ) {
			$h = $p['url_hash'];
			list( $imp, $why ) = Inventory::importance( $p, array( 'referrals' => $refs[ $h ] ?? 0 ) );
			$p['importance']   = $imp;
			$p['imp_reasons']  = implode( ',', $why );
			$important         = $imp >= $min_imp;
			$facts             = json_decode( (string) $p['facts'], true );
			$facts             = is_array( $facts ) ? $facts : null;

			// What crawled it.
			$ai_any    = false;
			$ai_search = false;
			$last_ai   = 0;
			$training  = false;
			foreach ( $crawls[ $h ] ?? array() as $bot => $c ) {
				$b = Registry::get( $bot );
				if ( ! $b || ! $b['ai'] ) {
					continue;
				}
				$ai_any  = true;
				$last_ai = max( $last_ai, (int) $c['last_seen'] );
				if ( in_array( $b['category'], array( 'ai_search', 'ai_user', 'ai_agent' ), true ) ) {
					$ai_search = true;
				}
				if ( 'ai_training' === $b['category'] ) {
					$training = true;
				}
			}

			// Coverage findings (observed requests and robots.txt).
			$cov     = array();
			$blocked = array();
			foreach ( $priority as $bot ) {
				$al = Robots::allows( $bot, $p['path'] );
				if ( ! $al['allowed'] ) {
					$blocked[] = $bot;
					$cov[]     = array( 'robots_blocked', array( 'value' => $al['rule'], '_severity' => $important ? 'critical' : 'warning' ), $bot );
				}
			}
			$ms_sum = 0;
			$ms_n   = 0;
			foreach ( $recent[ $h ] ?? array() as $bot => $r ) {
				$b = Registry::get( $bot );
				if ( ! $b || ! $b['ai'] ) {
					continue;
				}
				if ( (int) $r['e'] >= 2 || ( (int) $r['e'] >= 1 && (int) $r['st'] >= 500 ) ) {
					$cov[] = array( 'bot_errors', array( 'n' => (int) $r['e'], 'status' => (int) $r['st'], '_severity' => $important ? 'critical' : 'warning' ), $bot );
				}
				$ms_sum += (int) $r['ms'];
				$ms_n   += (int) $r['h'];
			}
			if ( $ms_n >= 3 && $ms_sum / $ms_n >= 3000 ) {
				$cov[] = array( 'slow_for_bots', array( 'n' => (int) round( $ms_sum / $ms_n ) ), '' );
			}
			if ( $important && ! $ai_any && ! $learning && ! $blocked && empty( $facts['noindex'] ) ) {
				$cov[] = array( 'never_crawled', array(), '' );
			}
			if ( $important && $last_ai && $p['modified'] && strtotime( $p['modified'] . ' UTC' ) > $last_ai && strtotime( $p['modified'] . ' UTC' ) < time() - 7 * DAY_IN_SECONDS ) {
				$cov[] = array( 'stale_crawl', array( 'last_crawl' => $last_ai ), '' );
			}
			if ( $important && $training && ! $ai_search ) {
				$cov[] = array( 'training_only', array(), '' );
			}

			$content = $facts ? self::content_findings( $p, $facts, $important ) : null;

			$score = null;
			$parts = null;
			if ( $facts ) {
				$sc    = Scorer::page( $p, $facts, array( 'ai_search' => $ai_search, 'ai_any' => $ai_any, 'learning' => $learning ), $blocked );
				$score = $sc['score'];
				$parts = $sc['parts'];
			}
			$wpdb->update(
				$t,
				array(
					'importance'  => $imp,
					'imp_reasons' => substr( $p['imp_reasons'], 0, 255 ),
					'aeo_score'   => $score,
					'aeo'         => $parts ? wp_json_encode( $parts ) : null,
				),
				array( 'id' => $p['id'] )
			);
			$opened = Findings::sync( (int) $p['id'], 'coverage', $cov );
			if ( null !== $content ) {
				$opened = array_merge( $opened, Findings::sync( (int) $p['id'], 'content', $content ) );
			}
			if ( $opened && $important ) {
				Alerts::page_findings_opened( $p, $opened );
			}
		}
	}

	/** Findings from what the page contains. */
	private static function content_findings( array $p, array $f, $important ) {
		$out    = array();
		$words  = (int) ( $f['words'] ?? 0 );
		$intent = $f['intent']['intent'] ?? 'informational';
		$post   = 'post' === $p['object_type'];
		$down   = static function ( $sev ) use ( $important ) {
			return $important ? $sev : ( 'critical' === $sev ? 'warning' : $sev );
		};
		if ( ! empty( $f['noindex'] ) ) {
			$out[] = array( 'noindex', array( '_severity' => $down( 'critical' ) ) );
		}
		if ( ! empty( $f['noai'] ) ) {
			$out[] = array( 'noai', array() );
		}
		if ( ! empty( $f['status'] ) && $f['status'] >= 400 ) {
			$out[] = array( 'http_error', array( 'value' => (string) $f['status'], '_severity' => $down( 'critical' ) ) );
		} elseif ( ! empty( $f['status'] ) && $f['status'] >= 300 ) {
			$out[] = array( 'redirects', array( 'value' => (string) ( $f['location'] ?? '' ) ) );
		}
		if ( ! empty( $f['canonical_elsewhere'] ) ) {
			$out[] = array( 'canonical_elsewhere', array( 'value' => (string) $f['canonical'] ) );
		}
		$is_home = 'home' === $p['object_type'] || false !== strpos( (string) $p['imp_reasons'], 'home' );
		if ( ! $is_home && 0 === (int) $p['inlinks'] && Analyzer::analyzed_count() > 5 ) {
			$out[] = array( 'orphan', array( '_severity' => $important ? 'warning' : 'info' ) );
		} elseif ( $important && ! $is_home && (int) $p['inlinks'] < 3 && (int) $p['inlinks'] > 0 ) {
			$out[] = array( 'few_inlinks', array( 'n' => (int) $p['inlinks'] ) );
		}
		if ( 'term' === $p['object_type'] || $is_home ) {
			return $out; // archives and the home page are not judged as articles
		}
		$thin = 'product' === $p['subtype'] ? 120 : 300;
		if ( $words < $thin ) {
			$out[] = array( 'thin_content', array( 'n' => $words, '_severity' => $important ? 'warning' : 'info' ) );
		}
		if ( $words >= 400 && 0 === ( (int) $f['h2'] + (int) $f['h3'] ) ) {
			$out[] = array( 'no_subheadings', array() );
		}
		if ( 'informational' === $intent && $words >= 500 && empty( $f['question_headings'] ) ) {
			$out[] = array( 'no_question_headings', array() );
		}
		if ( in_array( $intent, array( 'informational', 'commercial' ), true ) && $words >= 300 && ( (int) $f['first_para_words'] > 80 || 0 === (int) $f['first_para_words'] ) ) {
			$out[] = array( 'no_direct_answer', array( 'n' => (int) $f['first_para_words'] ) );
		}
		if ( $important && in_array( $intent, array( 'informational', 'commercial', 'transactional' ), true ) && empty( $f['faq'] ) && $words >= 500 ) {
			$out[] = array( 'no_faq', array() );
		}
		if ( $words >= 800 && 0 === ( (int) $f['lists'] + (int) $f['tables'] ) ) {
			$out[] = array( 'no_lists', array() );
		}
		$schema = (array) ( $f['schema'] ?? array() );
		if ( ! empty( $f['fetched'] ) && ! $schema ) {
			$out[] = array( 'no_structured_data', array( '_severity' => $important ? 'warning' : 'info' ) );
		} elseif ( $schema && $post ) {
			if ( 'product' === $p['subtype'] && ! in_array( 'Product', $schema, true ) ) {
				$out[] = array( 'schema_mismatch', array( 'value' => 'Product' ) );
			} elseif ( 'post' === $p['subtype'] && ! array_intersect( array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle' ), $schema ) ) {
				$out[] = array( 'schema_mismatch', array( 'value' => 'Article' ) );
			}
		}
		if ( isset( $f['author'] ) && is_array( $f['author'] ) && empty( $f['author']['bio'] ) ) {
			$out[] = array( 'no_author', array() );
		}
		if ( 'post' === $p['subtype'] && 'informational' === $intent && ( $f['modified_days'] ?? 0 ) > 365 ) {
			$out[] = array( 'outdated', array( 'n' => (int) $f['modified_days'] ) );
		}
		if ( (int) $f['images_no_alt'] > 0 && (int) $f['images_no_alt'] / max( 1, (int) $f['images'] ) >= 0.3 ) {
			$out[] = array( 'missing_alt', array( 'n' => (int) $f['images_no_alt'] ) );
		}
		if ( ! empty( $f['fetched'] ) && empty( $f['description'] ) ) {
			$out[] = array( 'no_meta_description', array() );
		}
		return $out;
	}

	/** Site-wide findings. */
	public static function site() {
		global $wpdb;
		$out = array();
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$out[] = array( 'site_noindex', array(), '' );
		}
		$matrix = Robots::matrix();
		foreach ( (array) ( $matrix['bots'] ?? array() ) as $id => $m ) {
			$b = Registry::get( $id );
			if ( ! $b || $m['site_allowed'] ) {
				continue;
			}
			if ( in_array( $b['category'], array( 'ai_search', 'search' ), true ) ) {
				$out[] = array( 'robots_blocks_search', array( 'value' => $m['rule'], '_severity' => Scorer::is_priority( $id ) ? 'critical' : 'warning' ), $id );
			} elseif ( in_array( $b['category'], array( 'ai_user', 'ai_agent' ), true ) ) {
				$out[] = array( 'robots_blocks_user', array( 'value' => $m['rule'] ), $id );
			} elseif ( 'ai_training' === $b['category'] ) {
				$out[] = array( 'robots_blocks_training', array( 'value' => $m['rule'] ), $id );
			}
		}
		if ( empty( $matrix['sitemaps'] ) && function_exists( 'wp_sitemaps_get_server' ) ) {
			$sm = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ? home_url( '/sitemap_index.xml' ) : home_url( '/wp-sitemap.xml' );
			$out[] = array( 'no_sitemap_in_robots', array( 'value' => $sm ), '' );
		}
		$probe = Probe::results();
		$since = wp_date( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
		foreach ( (array) $probe['results'] as $r ) {
			if ( empty( $r['refused'] ) ) {
				continue;
			}
			// If the real, verified crawler got through recently, the CDN is checking addresses: not a block.
			$ok = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(verified) - SUM(errors) FROM ' . Installer::table( 'daily_bots' ) . ' WHERE bot = %s AND day >= %s', $r['bot'], $since ) );
			if ( $ok > 0 ) {
				continue;
			}
			$out[] = array( 'edge_blocks_bot', array( 'bot_status' => $r['bot_status'], 'browser_status' => $r['browser_status'], 'value' => $r['url'] ), $r['bot'] );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT bot, SUM(spoofed) s FROM ' . Installer::table( 'daily_bots' ) . ' WHERE day >= %s GROUP BY bot HAVING s >= 20', $since ), ARRAY_A ) as $r ) {
			$out[] = array( 'impersonation', array( 'n' => (int) $r['s'] ), $r['bot'] );
		}
		$stale = false;
		foreach ( Ranges::status() as $st ) {
			if ( (int) ( $st['fetched_at'] ?? 0 ) < time() - 14 * DAY_IN_SECONDS ) {
				$stale = true;
			}
		}
		if ( $stale && Ranges::status() ) {
			$out[] = array( 'verification_stale', array(), '' );
		}
		if ( ! Settings::get( 'proxy_header' ) && (int) get_option( 'rfaib_proxy_noted', 0 ) > time() - WEEK_IN_SECONDS ) {
			$out[] = array( 'proxy_unconfigured', array(), '' );
		}
		$opened = Findings::sync( 0, 'site', $out );
		if ( $opened ) {
			Alerts::site_findings_opened( $opened );
		}
	}
}
