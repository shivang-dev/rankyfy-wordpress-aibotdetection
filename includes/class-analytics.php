<?php
/**
 * Read side for the dashboard. Everything comes from the rollup tables
 * (never a scan of raw requests, except bounded "recent requests" lists that
 * use an index), and results are cached for a few minutes behind a version
 * counter that the worker bumps when new data lands.
 *
 * Every response keeps observed data and inferred suggestions in separate,
 * labelled fields.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Analytics {

	const VERSION = 'rfaib_cache_v';
	const TTL     = 300;
	const SAMPLE  = 20000; // requests a crawler's breakdowns are computed from

	public static function bust() {
		update_option( self::VERSION, (int) get_option( self::VERSION, 0 ) + 1, false );
	}

	private static function cached( $key, callable $fn, $ttl = self::TTL ) {
		$k   = 'rfaib_c_' . md5( $key . '|' . (int) get_option( self::VERSION, 0 ) );
		$hit = get_transient( $k );
		if ( false !== $hit ) {
			return $hit;
		}
		$val = $fn();
		set_transient( $k, $val, $ttl );
		return $val;
	}

	private static function since( $days ) {
		return wp_date( 'Y-m-d', time() - ( (int) $days - 1 ) * DAY_IN_SECONDS );
	}

	/** Bot ids by group. */
	public static function bot_ids( $group ) {
		$out = array();
		foreach ( Registry::bots() as $id => $b ) {
			if ( 'ai' === $group && $b['ai'] ) {
				$out[] = $id;
			} elseif ( 'search' === $group && 'search' === $b['category'] ) {
				$out[] = $id;
			} elseif ( $group === $b['category'] ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	private static function in_list( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return "''";
		}
		return implode( ',', array_map( static function ( $id ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $id );
		}, $ids ) );
	}

	/** AI bot ids including custom ones and unregistered-but-AI placeholders. */
	private static function ai_in() {
		return self::in_list( array_merge( self::bot_ids( 'ai' ), array( '_signed' ) ) );
	}

	// ── overview ───────────────────────────────────────────────────────────

	public static function overview( $days = 30 ) {
		return self::cached( 'overview' . $days, static function () use ( $days ) {
			global $wpdb;
			$db    = Installer::table( 'daily_bots' );
			$d     = Installer::table( 'daily' );
			$since = self::since( $days );
			$prev  = self::since( 2 * $days );
			$ai    = self::ai_in();

			$tot = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(IF(day >= %s, hits, 0)) cur, SUM(IF(day < %s, hits, 0)) prev, SUM(IF(day >= %s, verified, 0)) ver, SUM(IF(day >= %s, spoofed, 0)) spoof, COUNT(DISTINCT IF(day >= %s AND hits > 0, bot, NULL)) bots FROM {$db} WHERE day >= %s AND bot IN ({$ai})", $since, $since, $since, $since, $since, $prev ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// A page crawled in the window has its last crawl in the window: exact, and page_bots is small.
			$crawled = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT url_hash) FROM ' . Installer::table( 'page_bots' ) . " WHERE last_seen >= %d AND bot IN ({$ai})", time() - (int) $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cov     = self::coverage( $days );
			$user    = self::in_list( array_merge( self::bot_ids( 'ai_user' ), self::bot_ids( 'ai_agent' ) ) );
			$user_n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(hits) FROM {$db} WHERE day >= %s AND bot IN ({$user})", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$refs    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(hits) FROM ' . Installer::table( 'referrals' ) . ' WHERE day >= %s', $since ) );

			return array(
				'range'       => (int) $days,
				'observed'    => array(
					'ai_requests'      => (int) $tot['cur'],
					'ai_requests_prev' => (int) $tot['prev'],
					'verified'         => (int) $tot['ver'],
					'impersonations'   => (int) $tot['spoof'],
					'ai_bots'          => (int) $tot['bots'],
					'pages_crawled'    => $crawled,
					'user_fetches'     => $user_n,
					'ai_referrals'     => $refs,
				),
				'coverage'    => $cov,
				'timeline'    => self::timeline( $days ),
				'top_bots'    => array_slice( self::bots( $days ), 0, 8 ),
				'top_pages'   => self::top_pages( $days, 10 ),
				'score'       => Scorer::site(),
				'score_trend' => self::snapshot_series( 'aeo_score', 90 ),
				'readiness'   => Readiness::summary(),
				'readiness_trend' => self::snapshot_series( 'readiness', 90 ),
				'llms_enabled'    => (bool) Settings::get( 'llms_enabled' ),
				'findings'    => Findings::counts(),
				'next_steps'  => self::recommendations( array( 'limit' => 5 ) )['items'],
				'alerts'      => Alerts::list( '', 5 ),
				'referrals'   => self::referral_sources( $days ),
			);
		}, 120 );
	}

	/** Important pages crawled by AI in the window, and never. */
	public static function coverage( $days = 30 ) {
		global $wpdb;
		$p     = Installer::table( 'pages' );
		$pb    = Installer::table( 'page_bots' );
		$ai    = self::ai_in();
		$min   = (int) Settings::get( 'importance_min' );
		$from  = time() - (int) $days * DAY_IN_SECONDS;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) total,
				        SUM(x.last_ai IS NOT NULL AND x.last_ai >= %d) crawled_window,
				        SUM(x.last_ai IS NOT NULL) crawled_ever,
				        SUM(p.importance >= %d OR p.pinned > 0) important,
				        SUM((p.importance >= %d OR p.pinned > 0) AND x.last_ai >= %d) important_crawled,
				        SUM((p.importance >= %d OR p.pinned > 0) AND x.last_ai IS NULL) important_never
				 FROM {$p} p LEFT JOIN (SELECT url_hash, MAX(last_seen) last_ai FROM {$pb} WHERE bot IN ({$ai}) GROUP BY url_hash) x ON x.url_hash = p.url_hash
				 WHERE p.deleted = 0 AND p.pinned >= 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$min,
				$min,
				$from,
				$min
			),
			ARRAY_A
		);
		return array(
			'pages'             => (int) $row['total'],
			'crawled'           => (int) $row['crawled_window'],
			'crawled_ever'      => (int) $row['crawled_ever'],
			'not_crawled'       => (int) $row['total'] - (int) $row['crawled_window'],
			'important'         => (int) $row['important'],
			'important_crawled' => (int) $row['important_crawled'],
			'important_never'   => (int) $row['important_never'],
			'learning'          => ( time() - (int) get_option( 'rfaib_monitoring_since', time() ) ) < 14 * DAY_IN_SECONDS,
		);
	}

	/** Daily AI requests by category, plus impersonations and classic search. */
	public static function timeline( $days ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, bot, hits, spoofed FROM ' . Installer::table( 'daily_bots' ) . ' WHERE day >= %s', self::since( $days ) ), ARRAY_A );
		$out  = array();
		for ( $i = (int) $days - 1; $i >= 0; $i-- ) {
			$day         = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$out[ $day ] = array( 'day' => $day, 'ai_search' => 0, 'ai_user' => 0, 'ai_training' => 0, 'ai_other' => 0, 'search' => 0, 'spoofed' => 0, 'other' => 0 );
		}
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $out[ $r['day'] ] ) ) {
				continue;
			}
			$b   = Registry::get( $r['bot'] );
			$cat = $b ? $b['category'] : ( '_signed' === $r['bot'] ? 'ai_agent' : 'other' );
			$key = 'ai_agent' === $cat ? 'ai_user' : ( isset( $out[ $r['day'] ][ $cat ] ) ? $cat : 'other' );
			$out[ $r['day'] ][ $key ]      += (int) $r['hits'];
			$out[ $r['day'] ]['spoofed'] += (int) $r['spoofed'];
		}
		return array_values( $out );
	}

	// ── bots ───────────────────────────────────────────────────────────────

	public static function bots( $days = 30 ) {
		return self::cached( 'bots' . $days, static function () use ( $days ) {
			global $wpdb;
			$since  = self::since( $days );
			$prev   = self::since( 2 * $days );
			$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT bot, SUM(IF(day >= %s, hits, 0)) h, SUM(IF(day < %s, hits, 0)) ph, SUM(IF(day >= %s, verified, 0)) v, SUM(IF(day >= %s, spoofed, 0)) s, SUM(IF(day >= %s, errors, 0)) e, SUM(IF(day >= %s, ms_total, 0)) ms FROM ' . Installer::table( 'daily_bots' ) . ' WHERE day >= %s GROUP BY bot', $since, $since, $since, $since, $since, $since, $prev ), ARRAY_A );
			$pages  = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT bot, COUNT(*) n FROM ' . Installer::table( 'page_bots' ) . ' WHERE last_seen >= %d GROUP BY bot', time() - (int) $days * DAY_IN_SECONDS ), ARRAY_A ) as $r ) {
				$pages[ $r['bot'] ] = (int) $r['n'];
			}
			$seen = array();
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Installer::table( 'bots_seen' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$seen[ $r['bot'] ] = $r;
			}
			$spark = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT bot, day, hits FROM ' . Installer::table( 'daily_bots' ) . ' WHERE day >= %s', self::since( 14 ) ), ARRAY_A ) as $r ) {
				$spark[ $r['bot'] ][ $r['day'] ] = (int) $r['hits'];
			}
			$matrix = Robots::matrix();
			$out    = array();
			foreach ( (array) $rows as $r ) {
				$b = Registry::get( $r['bot'] );
				if ( ( ! $b && '_signed' !== $r['bot'] && '_potential' !== $r['bot'] ) || ( (int) $r['h'] + (int) $r['s'] ) === 0 ) {
					continue;
				}
				$series = array();
				for ( $i = 13; $i >= 0; $i-- ) {
					$series[] = $spark[ $r['bot'] ][ wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] ?? 0;
				}
				$out[] = array(
					'id'           => $r['bot'],
					'name'         => Registry::label( $r['bot'] ),
					'provider'     => $b['provider'] ?? '',
					'category'     => $b['category'] ?? ( '_potential' === $r['bot'] ? 'potential' : 'ai_agent' ),
					'ai'           => $b ? $b['ai'] : true,
					'requests'     => (int) $r['h'],
					'requests_prev' => (int) $r['ph'],
					'verified'     => (int) $r['v'],
					'impersonations' => (int) $r['s'],
					'errors'       => (int) $r['e'],
					'avg_ms'       => (int) $r['h'] ? (int) round( (int) $r['ms'] / (int) $r['h'] ) : null,
					'pages'        => $pages[ $r['bot'] ] ?? 0,
					'first_seen'   => isset( $seen[ $r['bot'] ] ) ? (int) $seen[ $r['bot'] ]['first_seen'] : null,
					'last_seen'    => isset( $seen[ $r['bot'] ] ) ? (int) $seen[ $r['bot'] ]['last_seen'] : null,
					'verifiable'   => $b ? ( (bool) $b['verify']['ranges'] || (bool) $b['verify']['rdns'] || (bool) $b['verify']['signature_hosts'] ) : false,
					'robots'       => isset( $matrix['bots'][ $r['bot'] ] ) ? array( 'allowed' => $matrix['bots'][ $r['bot'] ]['site_allowed'], 'rule' => $matrix['bots'][ $r['bot'] ]['rule'] ) : null,
					'spark'        => $series,
				);
			}
			usort( $out, static function ( $a, $b ) {
				return ( $b['ai'] <=> $a['ai'] ) ?: ( $b['requests'] <=> $a['requests'] );
			} );
			return $out;
		} );
	}

	public static function bot_detail( $id, $days = 30 ) {
		global $wpdb;
		$b = Registry::get( $id );
		if ( ! $b && ! in_array( $id, array( '_signed', '_potential', '_unknown' ), true ) ) {
			return null;
		}
		$since = self::since( $days );
		$e     = Installer::table( 'events' );
		$from  = time() - (int) $days * DAY_IN_SECONDS;

		$timeline = array();
		$rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT day, hits, verified, spoofed, errors FROM ' . Installer::table( 'daily_bots' ) . ' WHERE bot = %s AND day >= %s ORDER BY day', $id, $since ), ARRAY_A );
		$by_day   = array();
		foreach ( (array) $rows as $r ) {
			$by_day[ $r['day'] ] = $r;
		}
		for ( $i = (int) $days - 1; $i >= 0; $i-- ) {
			$day        = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$timeline[] = array(
				'day'      => $day,
				'requests' => (int) ( $by_day[ $day ]['hits'] ?? 0 ),
				'verified' => (int) ( $by_day[ $day ]['verified'] ?? 0 ),
				'spoofed'  => (int) ( $by_day[ $day ]['spoofed'] ?? 0 ),
				'errors'   => (int) ( $by_day[ $day ]['errors'] ?? 0 ),
			);
		}
		$pages = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT d.url_hash, MAX(d.path) path, SUM(d.hits) hits, SUM(d.errors) errors, MAX(d.last_status) status, p.id page_id, p.title, p.importance
				 FROM ' . Installer::table( 'daily' ) . ' d LEFT JOIN ' . Installer::table( 'pages' ) . ' p ON p.url_hash = d.url_hash AND p.deleted = 0
				 WHERE d.bot = %s AND d.day >= %s GROUP BY d.url_hash ORDER BY hits DESC LIMIT 25',
				$id,
				$since
			),
			ARRAY_A
		);
		$sessions = $wpdb->get_results( $wpdb->prepare( 'SELECT started, ended, hits, pages, ips, errors FROM ' . Installer::table( 'sessions' ) . ' WHERE bot = %s ORDER BY ended DESC LIMIT 20', $id ), ARRAY_A );
		// Breakdowns use the latest SAMPLE requests in the window, so a crawler with
		// millions of requests costs the same as one with thousands.
		$sample   = $wpdb->prepare( "SELECT status, cls, vstate, ip_net, ua FROM {$e} WHERE bot = %s AND ts >= %d ORDER BY ts DESC LIMIT " . self::SAMPLE, $id, $from ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status   = $wpdb->get_results( "SELECT status, COUNT(*) n FROM ({$sample}) x GROUP BY status ORDER BY n DESC LIMIT 12", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$verif    = $wpdb->get_results( "SELECT cls, vstate, COUNT(*) n FROM ({$sample}) x GROUP BY cls, vstate", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$nets     = $wpdb->get_results( "SELECT ip_net, vstate, COUNT(*) n FROM ({$sample}) x WHERE ip_net <> '' GROUP BY ip_net, vstate ORDER BY n DESC LIMIT 15", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$uas      = $wpdb->get_results( "SELECT ua, COUNT(*) n FROM ({$sample}) x GROUP BY ua ORDER BY n DESC LIMIT 10", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent   = $wpdb->get_results( $wpdb->prepare( "SELECT ts, bot, method, path, status, ms, cls, vstate, ip_net, source FROM {$e} WHERE bot = %s ORDER BY ts DESC LIMIT 50", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$seen     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'bots_seen' ) . ' WHERE bot = %s', $id ), ARRAY_A );
		$matrix   = Robots::matrix();
		$reg      = $b;
		if ( $reg ) {
			$reg['ranges_status'] = array();
			$st = Ranges::status();
			foreach ( $reg['verify']['ranges'] as $u ) {
				$reg['ranges_status'][] = $st[ $u ] ?? array( 'url' => $u, 'fetched_at' => 0, 'count' => 0 );
			}
		}
		return array(
			'id'            => $id,
			'name'          => Registry::label( $id ),
			'registry'      => $reg,
			'robots'        => $matrix['bots'][ $id ] ?? null,
			'first_seen'    => $seen ? (int) $seen['first_seen'] : null,
			'last_seen'     => $seen ? (int) $seen['last_seen'] : null,
			'timeline'      => $timeline,
			'pages'         => array_map( static function ( $r ) {
				$r['hits']     = (int) $r['hits'];
				$r['errors']   = (int) $r['errors'];
				$r['status']   = (int) $r['status'];
				$r['page_id']  = $r['page_id'] ? (int) $r['page_id'] : null;
				unset( $r['url_hash'] );
				return $r;
			}, (array) $pages ),
			'sessions'      => array_map( static function ( $r ) {
				return array_map( 'intval', $r );
			}, (array) $sessions ),
			'status_codes'  => array_map( static function ( $r ) {
				return array( 'status' => (int) $r['status'], 'n' => (int) $r['n'] );
			}, (array) $status ),
			'verification'  => (array) $verif,
			'networks'      => (array) $nets,
			'user_agents'   => (array) $uas,
			'recent'        => array_map( array( __CLASS__, 'event_view' ), (array) $recent ),
			'retention_days' => (int) Settings::get( 'retention_events' ),
			'sample'        => self::SAMPLE,
		);
	}

	public static function event_view( array $r ) {
		return array(
			'ts'     => (int) $r['ts'],
			'method' => $r['method'],
			'path'   => $r['path'],
			'status' => (int) $r['status'],
			'ms'     => (int) $r['ms'],
			'cls'    => $r['cls'],
			'vstate' => $r['vstate'],
			'conf'   => Detector::confidence( $r['cls'], $r['vstate'], $r['bot'] ?? '' ),
			'net'    => $r['ip_net'] ?? '',
			'source' => $r['source'] ?? 'live',
			'bot'    => $r['bot'] ?? null,
			'name'   => isset( $r['bot'] ) ? Registry::label( $r['bot'] ) : null,
		);
	}

	// ── pages ──────────────────────────────────────────────────────────────

	/**
	 * Paginated page list with crawl status.
	 *
	 * @param array $q filter (all|important|crawled|uncrawled|never|errors|blocked|dropped), bot, search, sort, page, days
	 */
	public static function pages( array $q ) {
		global $wpdb;
		$p     = Installer::table( 'pages' );
		$pb    = Installer::table( 'page_bots' );
		$d     = Installer::table( 'daily' );
		$f     = Installer::table( 'findings' );
		$days  = max( 1, min( 365, (int) ( $q['days'] ?? 30 ) ) );
		$since = self::since( $days );
		$from  = time() - $days * DAY_IN_SECONDS;
		$bot   = isset( $q['bot'] ) && Registry::get( $q['bot'] ) ? $q['bot'] : '';
		$ai    = $bot ? self::in_list( array( $bot ) ) : self::ai_in();
		$min   = (int) Settings::get( 'importance_min' );
		$per   = 50;
		$page  = max( 1, (int) ( $q['page'] ?? 1 ) );
		$where = array( 'p.deleted = 0' );
		$imp   = $wpdb->prepare( '(p.importance >= %d OR p.pinned > 0) AND p.pinned >= 0', $min );
		switch ( $q['filter'] ?? 'all' ) {
			case 'important':
				$where[] = $imp;
				break;
			case 'crawled':
				$where[] = 'w.hits > 0';
				break;
			case 'uncrawled':
				$where[] = '(w.hits IS NULL OR w.hits = 0)';
				break;
			case 'never':
				$where[] = $imp . ' AND x.last_ai IS NULL';
				break;
			case 'dropped':
				$where[] = $imp . $wpdb->prepare( ' AND x.last_ai < %d', time() - 30 * DAY_IN_SECONDS );
				break;
			case 'errors':
				$where[] = "EXISTS (SELECT 1 FROM {$f} fe WHERE fe.page_id = p.id AND fe.status = 'open' AND fe.code IN ('bot_errors','http_error'))";
				break;
			case 'blocked':
				$where[] = "EXISTS (SELECT 1 FROM {$f} fb WHERE fb.page_id = p.id AND fb.status = 'open' AND fb.code IN ('robots_blocked','noindex','canonical_elsewhere','noai'))";
				break;
		}
		if ( ! empty( $q['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( substr( (string) $q['search'], 0, 100 ) ) . '%';
			$where[] = $wpdb->prepare( '(p.title LIKE %s OR p.path LIKE %s)', $like, $like );
		}
		if ( ! empty( $q['type'] ) && preg_match( '/^[a-z0-9_-]{1,40}$/', $q['type'] ) ) {
			$where[] = $wpdb->prepare( 'p.subtype = %s', $q['type'] );
		}
		$order = array(
			'importance' => 'p.importance DESC, p.id ASC',
			'hits'       => 'w.hits DESC, p.importance DESC',
			'last'       => 'x.last_ai DESC',
			'score'      => 'p.aeo_score ASC, p.importance DESC',
			'title'      => 'p.title ASC',
		)[ $q['sort'] ?? 'importance' ] ?? 'p.importance DESC, p.id ASC';

		$sql_from = "FROM {$p} p
			LEFT JOIN (SELECT url_hash, MAX(last_seen) last_ai, COUNT(*) nbots, GROUP_CONCAT(bot ORDER BY last_seen DESC SEPARATOR ',') bots FROM {$pb} WHERE bot IN ({$ai}) GROUP BY url_hash) x ON x.url_hash = p.url_hash
			LEFT JOIN (" . ( $bot
				? "SELECT url_hash, SUM(hits) hits, SUM(errors) errors FROM {$d} WHERE day >= '" . esc_sql( $since ) . "' AND bot IN ({$ai}) GROUP BY url_hash"
				: 'SELECT url_hash, SUM(hits) hits, SUM(errors) errors FROM ' . Installer::table( 'daily_pages' ) . " WHERE day >= '" . esc_sql( $since ) . "' GROUP BY url_hash" ) . ") w ON w.url_hash = p.url_hash
			WHERE " . implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$sql_from}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			"SELECT p.id, p.object_type, p.object_id, p.subtype, p.path, p.title, p.importance, p.imp_reasons, p.pinned, p.aeo_score, p.word_count, p.inlinks, p.noindex, p.http_status, p.analyzed_at, p.assist_at,
			        x.last_ai, x.nbots, x.bots, COALESCE(w.hits, 0) hits, COALESCE(w.errors, 0) errors,
			        (SELECT COUNT(*) FROM {$f} fc WHERE fc.page_id = p.id AND fc.status = 'open' AND fc.severity = 'critical') critical,
			        (SELECT COUNT(*) FROM {$f} fw WHERE fw.page_id = p.id AND fw.status = 'open' AND fw.severity = 'warning') warnings
			 {$sql_from} ORDER BY {$order} LIMIT " . (int) $per . ' OFFSET ' . (int) ( ( $page - 1 ) * $per ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $r ) {
			$items[] = array(
				'id'         => (int) $r['id'],
				'type'       => $r['object_type'],
				'subtype'    => $r['subtype'],
				'path'       => $r['path'],
				'title'      => $r['title'],
				'importance' => (int) $r['importance'],
				'important'  => ( (int) $r['importance'] >= $min || (int) $r['pinned'] > 0 ) && (int) $r['pinned'] >= 0,
				'reasons'    => array_filter( explode( ',', (string) $r['imp_reasons'] ) ),
				'pinned'     => (int) $r['pinned'],
				'score'      => null === $r['aeo_score'] ? null : (int) $r['aeo_score'],
				'words'      => (int) $r['word_count'],
				'inlinks'    => (int) $r['inlinks'],
				'last_ai'    => $r['last_ai'] ? (int) $r['last_ai'] : null,
				'bots'       => $r['bots'] ? array_slice( explode( ',', $r['bots'] ), 0, 6 ) : array(),
				'hits'       => (int) $r['hits'],
				'errors'     => (int) $r['errors'],
				'critical'   => (int) $r['critical'],
				'warnings'   => (int) $r['warnings'],
				'analyzed'   => (int) $r['analyzed_at'] > 0,
				'ai_analyzed' => (int) $r['assist_at'] > 0,
				'edit'       => 'post' === $r['object_type'] && current_user_can( 'edit_post', (int) $r['object_id'] ) ? get_edit_post_link( (int) $r['object_id'], 'raw' ) : null,
				'view'       => Inventory::url( $r ),
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'pages' => (int) ceil( $total / $per ),
			'days'  => $days,
		);
	}

	public static function top_pages( $days, $limit = 10 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT url_hash, SUM(hits) hits FROM ' . Installer::table( 'daily_pages' ) . ' WHERE day >= %s GROUP BY url_hash ORDER BY hits DESC LIMIT %d',
				self::since( $days ),
				$limit
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return array();
		}
		$hashes = array_column( $rows, 'url_hash' );
		$in     = self::in_list( $hashes );
		$ai     = self::ai_in();
		$info   = array();
		foreach ( (array) $wpdb->get_results( 'SELECT pb.url_hash, MAX(pb.path) path, COUNT(*) nbots, MAX(p.id) page_id, MAX(p.title) title, MAX(p.importance) importance FROM ' . Installer::table( 'page_bots' ) . ' pb LEFT JOIN ' . Installer::table( 'pages' ) . " p ON p.url_hash = pb.url_hash AND p.deleted = 0 WHERE pb.url_hash IN ({$in}) AND pb.bot IN ({$ai}) AND pb.last_seen >= " . (int) ( time() - (int) $days * DAY_IN_SECONDS ) . ' GROUP BY pb.url_hash', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$info[ $r['url_hash'] ] = $r;
		}
		return array_map( static function ( $r ) use ( $info ) {
			$i = $info[ $r['url_hash'] ] ?? array();
			return array(
				'path'       => $i['path'] ?? '',
				'title'      => $i['title'] ?? null,
				'page_id'    => ! empty( $i['page_id'] ) ? (int) $i['page_id'] : null,
				'hits'       => (int) $r['hits'],
				'bots'       => (int) ( $i['nbots'] ?? 0 ),
				'importance' => isset( $i['importance'] ) ? (int) $i['importance'] : null,
			);
		}, $rows );
	}

	public static function page_detail( $id ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'pages' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $p ) {
			return null;
		}
		$facts = json_decode( (string) $p['facts'], true );
		$crawl = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT bot, first_seen, last_seen, hits, last_status FROM ' . Installer::table( 'page_bots' ) . ' WHERE url_hash = %s ORDER BY last_seen DESC', $p['url_hash'] ), ARRAY_A ) as $r ) {
			$crawl[ $r['bot'] ] = $r;
		}
		$bots = array();
		foreach ( Registry::bots() as $bid => $b ) {
			if ( ( ! $b['ai'] && 'search' !== $b['category'] ) || ( $b['robots_only'] && ! $b['robots_tokens'] ) ) {
				continue;
			}
			$c        = $crawl[ $bid ] ?? null;
			$allowed  = Robots::allows( $bid, $p['path'] );
			if ( ! $c && ! Scorer::is_priority( $bid ) && $allowed['allowed'] ) {
				continue; // keep the table to crawlers that matter or did something
			}
			$bots[] = array(
				'id'         => $bid,
				'name'       => $b['name'],
				'category'   => $b['category'],
				'robots_only' => $b['robots_only'],
				'first_seen' => $c ? (int) $c['first_seen'] : null,
				'last_seen'  => $c ? (int) $c['last_seen'] : null,
				'hits'       => $c ? (int) $c['hits'] : 0,
				'status'     => $c ? (int) $c['last_status'] : null,
				'allowed'    => $allowed['allowed'],
				'rule'       => $allowed['rule'],
			);
		}
		$timeline = array();
		$rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT day, bot, hits FROM ' . Installer::table( 'daily' ) . ' WHERE url_hash = %s AND day >= %s', $p['url_hash'], self::since( 90 ) ), ARRAY_A );
		$by       = array();
		foreach ( (array) $rows as $r ) {
			$b                  = Registry::get( $r['bot'] );
			$k                  = $b && $b['ai'] ? 'ai' : 'search';
			$by[ $r['day'] ][ $k ] = ( $by[ $r['day'] ][ $k ] ?? 0 ) + (int) $r['hits'];
		}
		for ( $i = 89; $i >= 0; $i-- ) {
			$day        = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$timeline[] = array( 'day' => $day, 'ai' => $by[ $day ]['ai'] ?? 0, 'search' => $by[ $day ]['search'] ?? 0 );
		}
		$recent = $wpdb->get_results( $wpdb->prepare( 'SELECT ts, bot, method, path, status, ms, cls, vstate, ip_net, source FROM ' . Installer::table( 'events' ) . ' WHERE url_hash = %s ORDER BY ts DESC LIMIT 30', $p['url_hash'] ), ARRAY_A );
		$refs   = $wpdb->get_results( $wpdb->prepare( 'SELECT source, SUM(hits) h FROM ' . Installer::table( 'referrals' ) . ' WHERE url_hash = %s AND day >= %s GROUP BY source ORDER BY h DESC', $p['url_hash'], self::since( 90 ) ), ARRAY_A );
		$score  = json_decode( (string) $p['aeo'], true );
		$assist = Rankyfy::present_assist( json_decode( (string) $p['assist'], true ), (int) $p['assist_at'] );
		return array(
			'page'      => array(
				'id'          => (int) $p['id'],
				'type'        => $p['object_type'],
				'subtype'     => $p['subtype'],
				'object_id'   => (int) $p['object_id'],
				'path'        => $p['path'],
				'title'       => $p['title'],
				'importance'  => (int) $p['importance'],
				'reasons'     => array_filter( explode( ',', (string) $p['imp_reasons'] ) ),
				'pinned'      => (int) $p['pinned'],
				'analyzed_at' => (int) $p['analyzed_at'],
				'fetched_at'  => (int) $p['fetch_at'],
				'edit'        => 'post' === $p['object_type'] && current_user_can( 'edit_post', (int) $p['object_id'] ) ? get_edit_post_link( (int) $p['object_id'], 'raw' ) : null,
				'view'        => Inventory::url( $p ),
			),
			'score'     => null === $p['aeo_score'] ? null : array( 'score' => (int) $p['aeo_score'], 'parts' => $score, 'max' => Scorer::MAX ),
			'observed'  => array(
				'crawlers'  => $bots,
				'timeline'  => $timeline,
				'recent'    => array_map( array( __CLASS__, 'event_view' ), (array) $recent ),
				'referrals' => array_map( static function ( $r ) {
					return array( 'source' => $r['source'], 'name' => Registry::referrer_name( $r['source'] ), 'visits' => (int) $r['h'] );
				}, (array) $refs ),
				'content'   => is_array( $facts ) ? array(
					'words'             => (int) $facts['words'],
					'headings'          => array_slice( (array) $facts['headings'], 0, 25 ),
					'question_headings' => (array) $facts['question_headings'],
					'questions'         => (array) ( $facts['questions'] ?? array() ),
					'schema'            => (array) $facts['schema'],
					'lists'             => (int) $facts['lists'],
					'tables'            => (int) $facts['tables'],
					'faq'               => (bool) $facts['faq'],
					'images'            => (int) $facts['images'],
					'images_no_alt'     => (int) $facts['images_no_alt'],
					'inlinks'           => (int) $p['inlinks'],
					'outlinks'          => (int) $p['outlinks'],
					'status'            => $facts['status'],
					'canonical'         => $facts['canonical'],
					'robots_meta'       => $facts['robots_meta'],
					'x_robots'          => $facts['x_robots'],
					'noindex'           => (bool) $facts['noindex'],
					'description'       => $facts['description'],
					'author'            => $facts['author'],
					'modified_days'     => $facts['modified_days'],
					'fetched'           => (bool) $facts['fetched'],
					'intent'            => $facts['intent'],
					'entities'          => (array) $facts['entities'],
					'terms'             => Analyzer::key_terms( (int) $p['id'], 12 ),
				) : null,
			),
			'findings'  => Findings::for_page( (int) $p['id'] ),
			'resolved'  => array_slice( Findings::for_page( (int) $p['id'], 'resolved' ), 0, 20 ),
			'links'     => Linker::for_page( (int) $p['id'], 6 ),
			'inferred'  => array(
				'analysis'       => $assist,
				'query_patterns' => self::query_patterns( $p, $facts ),
			),
			'ai_available' => Rankyfy::available(),
		);
	}

	/**
	 * Question patterns built from the page's own key terms and intent. They
	 * are templates — clearly labelled as such — not observed searches.
	 */
	public static function query_patterns( array $p, $facts ) {
		if ( ! is_array( $facts ) || 'post' !== $p['object_type'] ) {
			return array();
		}
		$t = self::subject( (string) ( $p['title'] ?? '' ) );
		if ( '' === $t ) {
			$terms = array_column( Analyzer::key_terms( (int) $p['id'], 5 ), 'term' );
			$t     = $terms[0] ?? '';
		}
		if ( '' === $t ) {
			return array();
		}
		$raw = strtolower( trim( (string) ( $p['title'] ?? '' ) ) );
		if ( '?' === substr( $raw, -1 ) ) {
			return array( $raw, "{$t} explained" ); // the title is already the question
		}
		if ( 0 === strpos( $raw, 'how to ' ) ) {
			return array( "how to {$t}", "how long does it take to {$t}", "{$t} step by step", "{$t} for beginners", "common mistakes when you {$t}" );
		}
		$intent = $facts['intent']['intent'] ?? 'informational';
		$best   = preg_match( '/\bbest\b/', $t ) ? $t : "best {$t}";
		switch ( $intent ) {
			case 'transactional':
				$out = array( "{$t} price", "where to buy {$t}", "is {$t} worth it", "{$t} reviews" );
				break;
			case 'commercial':
				$out = array( $best, "{$t} alternatives", "which {$t} should I choose", "{$t} pros and cons" );
				break;
			case 'local':
				$out = array( "{$t} near me", "{$best} in my area", "{$t} opening hours" );
				break;
			default:
				$out = array( "what is {$t}", "how does {$t} work", "{$t} explained", "{$t} examples", "common {$t} mistakes" );
		}
		// Questions the page already uses as headings are kept as they are (they are the page's own words).
		return array_values( array_unique( array_map( 'trim', $out ) ) );
	}

	/**
	 * What a page is about, from its own title: the part before a separator
	 * ("— part 3", "| Shop"), without question words or generic wrappers
	 * ("How to …", "The ultimate guide to …", "… explained"). Empty when the
	 * title is too long to be a subject.
	 */
	public static function subject( $title ) {
		$t = strtolower( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ) );
		$t = preg_split( '/\s[—–|:]\s|\s-\s/u', $t )[0];
		$t = preg_replace( '/^(how to|how do (i|you)|what (is|are)|why (is|are|do|does)|the ultimate guide to|a guide to|guide to|the best|best|top \d+|the|a|an)\s+/u', '', trim( $t ) );
		$t = preg_replace( '/\s+(guide|explained|tips|review|reviews|for beginners|\d{4})$/u', '', $t );
		$t = trim( preg_replace( '/[?!.]+$/', '', $t ) );
		$n = Text::word_count( $t );
		return ( $n >= 1 && $n <= 6 ) ? $t : '';
	}

	// ── recommendations ────────────────────────────────────────────────────

	public static function recommendations( array $q ) {
		global $wpdb;
		$f     = Installer::table( 'findings' );
		$p     = Installer::table( 'pages' );
		$where = array( "f.status = 'open'", '(f.page_id = 0 OR (p.deleted = 0 AND p.pinned >= 0))' );
		if ( ! empty( $q['severity'] ) && isset( Catalog::SEVERITY_WEIGHT[ $q['severity'] ] ) ) {
			$where[] = $wpdb->prepare( 'f.severity = %s', $q['severity'] );
		}
		if ( ! empty( $q['kind'] ) && in_array( $q['kind'], array( 'observed', 'inferred' ), true ) ) {
			$where[] = $wpdb->prepare( 'f.kind = %s', $q['kind'] );
		}
		if ( ! empty( $q['code'] ) && isset( Catalog::CODES[ $q['code'] ] ) ) {
			$where[] = $wpdb->prepare( 'f.code = %s', $q['code'] );
		}
		if ( ! empty( $q['group'] ) ) {
			$codes = array();
			foreach ( Catalog::CODES as $c => $m ) {
				if ( $m[3] === $q['group'] ) {
					$codes[] = $c;
				}
			}
			$where[] = $codes ? 'f.code IN (' . self::in_list( $codes ) . ')' : '1=0';
		}
		$limit  = max( 1, min( 100, (int) ( $q['limit'] ?? 50 ) ) );
		$offset = max( 0, ( (int) ( $q['page'] ?? 1 ) - 1 ) * $limit );
		$w      = implode( ' AND ', $where );
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$f} f LEFT JOIN {$p} p ON p.id = f.page_id WHERE {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Priority: severity first, then how important the page is; site-wide issues rank as most important.
		$rows   = $wpdb->get_results(
			"SELECT f.*, p.path, p.title AS page_title, p.importance, p.object_type, p.object_id FROM {$f} f LEFT JOIN {$p} p ON p.id = f.page_id WHERE {$w}
			 ORDER BY (FIELD(f.severity, 'info', 'warning', 'critical') * 1000 + IF(f.page_id = 0, 150, COALESCE(p.importance, 0))) DESC, f.id ASC LIMIT {$limit} OFFSET {$offset}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return array(
			'items' => Findings::present( (array) $rows ),
			'total' => $total,
			'page'  => (int) ( $q['page'] ?? 1 ),
			'pages' => (int) ceil( $total / $limit ),
		);
	}

	// ── opportunities ──────────────────────────────────────────────────────

	public static function opportunities() {
		return self::cached( 'opps', static function () {
			global $wpdb;
			$p   = Installer::table( 'pages' );
			$min = (int) Settings::get( 'importance_min' );

			// Observed: what the content covers, what assistants fetch, who sends visitors.
			$cand   = $wpdb->get_results(
				'SELECT pt.term, COUNT(*) pages, SUM(pt.weight) w FROM ' . Installer::table( 'page_terms' ) . " pt JOIN {$p} p ON p.id = pt.page_id
				 WHERE p.deleted = 0 GROUP BY pt.term HAVING pages >= 2 ORDER BY w DESC LIMIT 300", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
			// A topic is a term the site also uses in page titles — that filters out
			// word pairs that only happen to sit next to each other in body text.
			$titles = ' ' . strtolower( implode( ' | ', (array) $wpdb->get_col( "SELECT title FROM {$p} WHERE deleted = 0 LIMIT 5000" ) ) ) . ' '; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$topics = array();
			foreach ( (array) $cand as $c ) {
				if ( preg_match( '/(?<![\\p{L}\\p{N}])' . preg_quote( $c['term'], '/' ) . '(?![\\p{L}\\p{N}])/u', $titles ) ) {
					$topics[] = $c;
				}
				if ( count( $topics ) >= 30 ) {
					break;
				}
			}
			$user = self::in_list( array_merge( self::bot_ids( 'ai_user' ), self::bot_ids( 'ai_agent' ) ) );
			$asked = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT d.url_hash, MAX(d.path) path, SUM(d.hits) hits, GROUP_CONCAT(DISTINCT d.bot) bots, pg.id page_id, pg.title FROM ' . Installer::table( 'daily' ) . " d LEFT JOIN {$p} pg ON pg.url_hash = d.url_hash AND pg.deleted = 0
					 WHERE d.day >= %s AND d.bot IN ({$user}) GROUP BY d.url_hash ORDER BY hits DESC LIMIT 15", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::since( 30 )
				),
				ARRAY_A
			);
			$answered = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p} WHERE deleted = 0 AND facts LIKE '%\"question_headings\":[\"%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Inferred: aggregated from RankyFy Content AI analyses of pages.
			$keywords  = array();
			$questions = array();
			$gaps      = array();
			$rows      = $wpdb->get_results( "SELECT id, path, title, importance, assist, assist_at FROM {$p} WHERE deleted = 0 AND assist_at > 0 ORDER BY importance DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $r ) {
				$a = Rankyfy::present_assist( json_decode( (string) $r['assist'], true ), (int) $r['assist_at'] );
				if ( ! $a ) {
					continue;
				}
				$ref = array( 'page_id' => (int) $r['id'], 'title' => $r['title'], 'path' => $r['path'] );
				foreach ( array_merge( $a['primary_keyword'] ? array( $a['primary_keyword'] ) : array(), $a['secondary_keywords'] ) as $k ) {
					if ( '' === $k['keyword'] ) {
						continue;
					}
					$key = strtolower( $k['keyword'] );
					if ( ! isset( $keywords[ $key ] ) || ( $k['volume'] ?? 0 ) > ( $keywords[ $key ]['volume'] ?? 0 ) ) {
						$keywords[ $key ] = $k + array( 'page' => $ref );
					}
				}
				foreach ( $a['questions'] as $qq ) {
					$questions[] = $qq + array( 'page' => $ref );
				}
				foreach ( array_merge( $a['gaps'], array_column( $a['additions'], 'topic' ) ) as $g ) {
					if ( $g ) {
						$gaps[] = array( 'topic' => $g, 'page' => $ref );
					}
				}
			}
			uasort( $keywords, static function ( $a, $b ) {
				return ( 'measured' === $b['source'] ) <=> ( 'measured' === $a['source'] ) ?: ( ( $b['volume'] ?? 0 ) <=> ( $a['volume'] ?? 0 ) );
			} );
			$patterns = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p} WHERE deleted = 0 AND object_type = 'post' AND analyzed_at > 0 AND (importance >= %d OR pinned > 0) ORDER BY importance DESC LIMIT 12", $min ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$qp = self::query_patterns( $r, json_decode( (string) $r['facts'], true ) );
				if ( $qp ) {
					$patterns[] = array( 'page' => array( 'page_id' => (int) $r['id'], 'title' => $r['title'], 'path' => $r['path'] ), 'patterns' => array_slice( $qp, 0, 4 ) );
				}
			}
			$not_analyzed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p} WHERE deleted = 0 AND object_type = 'post' AND (importance >= %d OR pinned > 0) AND assist_at = 0", $min ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return array(
				'observed' => array(
					'topics'           => array_map( static function ( $r ) {
						return array( 'term' => $r['term'], 'pages' => (int) $r['pages'] );
					}, (array) $topics ),
					'assistant_pages'  => array_map( static function ( $r ) {
						return array(
							'path'    => $r['path'],
							'title'   => $r['title'],
							'page_id' => $r['page_id'] ? (int) $r['page_id'] : null,
							'fetches' => (int) $r['hits'],
							'bots'    => array_map( array( Registry::class, 'label' ), explode( ',', (string) $r['bots'] ) ),
						);
					}, (array) $asked ),
					'referrals'        => self::referral_sources( 30 ),
					'pages_with_questions' => $answered,
				),
				'inferred' => array(
					'keywords'       => array_slice( array_values( $keywords ), 0, 40 ),
					'questions'      => array_slice( $questions, 0, 40 ),
					'gaps'           => array_slice( $gaps, 0, 40 ),
					'query_patterns' => $patterns,
					'analyzed_pages' => count( (array) $rows ),
					'not_analyzed'   => $not_analyzed,
				),
				'links'    => Linker::opportunities(),
			);
		} );
	}

	public static function referral_sources( $days ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source, SUM(hits) h, COUNT(DISTINCT url_hash) pages FROM ' . Installer::table( 'referrals' ) . ' WHERE day >= %s GROUP BY source ORDER BY h DESC', self::since( $days ) ), ARRAY_A );
		return array_map( static function ( $r ) {
			return array( 'source' => $r['source'], 'name' => Registry::referrer_name( $r['source'] ), 'visits' => (int) $r['h'], 'pages' => (int) $r['pages'] );
		}, (array) $rows );
	}

	// ── technical ──────────────────────────────────────────────────────────

	public static function technical() {
		global $wpdb;
		$m    = Robots::matrix();
		$cur  = Robots::current();
		$rows = array();
		foreach ( (array) ( $m['bots'] ?? array() ) as $id => $x ) {
			$b      = Registry::get( $id );
			$rows[] = array(
				'id'            => $id,
				'name'          => $b ? $b['name'] : $id,
				'provider'      => $b ? $b['provider'] : '',
				'category'      => $b ? $b['category'] : '',
				'robots_only'   => $b ? $b['robots_only'] : false,
				'token'         => $x['token'],
				'site_allowed'  => $x['site_allowed'],
				'rule'          => $x['rule'],
				'group'         => $x['group'],
				'blocked_pages' => $x['blocked_count'],
				'respects'      => $x['respects'],
				'priority'      => Scorer::is_priority( $id ),
			);
		}
		$by_code = $wpdb->get_results(
			'SELECT f.code, f.severity, COUNT(*) n FROM ' . Installer::table( 'findings' ) . ' f JOIN ' . Installer::table( 'pages' ) . " p ON p.id = f.page_id WHERE f.status = 'open' AND p.deleted = 0 GROUP BY f.code, f.severity ORDER BY n DESC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return array(
			'robots'       => array(
				'source'     => $cur['source'],
				'status'     => (int) $cur['status'],
				'fetched_at' => (int) $cur['fetched_at'],
				'body'       => substr( (string) $cur['body'], 0, 20000 ),
				'sitemaps'   => $m['sitemaps'] ?? array(),
				'pages_checked' => (int) ( $m['pages_check'] ?? 0 ),
			),
			'matrix'       => $rows,
			'probe'        => Probe::results(),
			'site'         => Findings::site(),
			'page_issues'  => array_map( static function ( $r ) {
				return array( 'code' => $r['code'], 'severity' => $r['severity'], 'n' => (int) $r['n'], 'group' => Catalog::meta( $r['code'] )['group'], 'title' => Catalog::label( $r['code'] ) );
			}, (array) $by_code ),
			'verification' => array(
				'ranges'   => array_values( Ranges::status() ),
				'rdns'     => (bool) Settings::get( 'verify_rdns' ),
				'signatures' => (bool) Settings::get( 'verify_signatures' ),
				'queue'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'verify_queue' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			),
		);
	}

	// ── history ────────────────────────────────────────────────────────────

	/**
	 * Activity by day, week or month, per category or per bot, plus the daily
	 * snapshots (score, coverage, issues) for trends.
	 */
	public static function history( $days = 90, $group = 'day' ) {
		return self::cached( 'history' . $days . $group, static function () use ( $days, $group ) {
			global $wpdb;
			$fmt  = array( 'day' => '%Y-%m-%d', 'week' => '%x-W%v', 'month' => '%Y-%m' )[ $group ] ?? '%Y-%m-%d';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE_FORMAT(day, %s) period, bot, SUM(hits) h, SUM(spoofed) s FROM ' . Installer::table( 'daily_bots' ) . ' WHERE day >= %s GROUP BY period, bot ORDER BY period', $fmt, self::since( $days ) ), ARRAY_A );
			$periods = array();
			$per_bot = array();
			foreach ( (array) $rows as $r ) {
				$b   = Registry::get( $r['bot'] );
				$cat = $b ? ( 'ai_agent' === $b['category'] ? 'ai_user' : $b['category'] ) : 'other';
				if ( ! isset( $periods[ $r['period'] ] ) ) {
					$periods[ $r['period'] ] = array( 'period' => $r['period'], 'ai_search' => 0, 'ai_user' => 0, 'ai_training' => 0, 'ai_other' => 0, 'search' => 0, 'spoofed' => 0 );
				}
				if ( isset( $periods[ $r['period'] ][ $cat ] ) ) {
					$periods[ $r['period'] ][ $cat ] += (int) $r['h'];
				}
				$periods[ $r['period'] ]['spoofed'] += (int) $r['s'];
				if ( $b && $b['ai'] ) {
					$per_bot[ $r['bot'] ][ $r['period'] ] = (int) $r['h'];
				}
			}
			$growth = $wpdb->get_results( $wpdb->prepare( 'SELECT bot, first_seen FROM ' . Installer::table( 'bots_seen' ) . ' WHERE first_seen >= %d ORDER BY first_seen', time() - (int) $days * DAY_IN_SECONDS ), ARRAY_A );
			$resolved = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE_FORMAT(FROM_UNIXTIME(resolved_at), %s) period, COUNT(*) n FROM ' . Installer::table( 'findings' ) . " WHERE status = 'resolved' AND resolved_at >= %d GROUP BY period", $fmt, time() - (int) $days * DAY_IN_SECONDS ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$opened   = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE_FORMAT(FROM_UNIXTIME(first_seen), %s) period, COUNT(*) n FROM ' . Installer::table( 'findings' ) . ' WHERE first_seen >= %d GROUP BY period', $fmt, time() - (int) $days * DAY_IN_SECONDS ), ARRAY_A );
			$bots     = array();
			foreach ( $per_bot as $id => $series ) {
				$bots[] = array( 'id' => $id, 'name' => Registry::label( $id ), 'series' => $series, 'total' => array_sum( $series ) );
			}
			usort( $bots, static function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			} );
			return array(
				'group'      => $group,
				'activity'   => array_values( $periods ),
				'bots'       => array_slice( $bots, 0, 10 ),
				'new_bots'   => array_map( static function ( $r ) {
					return array( 'id' => $r['bot'], 'name' => Registry::label( $r['bot'] ), 'first_seen' => (int) $r['first_seen'] );
				}, (array) $growth ),
				'snapshots'  => self::snapshots( $days ),
				'issues'     => array(
					'opened'   => (array) $opened,
					'resolved' => (array) $resolved,
				),
				'resolved_recent' => self::recently_resolved( 15 ),
			);
		} );
	}

	public static function recently_resolved( $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT f.*, p.path, p.title AS page_title, p.importance, p.object_type, p.object_id FROM ' . Installer::table( 'findings' ) . ' f LEFT JOIN ' . Installer::table( 'pages' ) . " p ON p.id = f.page_id WHERE f.status = 'resolved' ORDER BY f.resolved_at DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return Findings::present( (array) $rows );
	}

	public static function snapshots( $days ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, metrics FROM ' . Installer::table( 'snapshots' ) . ' WHERE day >= %s ORDER BY day', self::since( $days ) ), ARRAY_A );
		return array_map( static function ( $r ) {
			$m        = json_decode( (string) $r['metrics'], true );
			$m        = is_array( $m ) ? $m : array();
			$m['day'] = $r['day'];
			return $m;
		}, (array) $rows );
	}

	private static function snapshot_series( $key, $days ) {
		$out = array();
		foreach ( self::snapshots( $days ) as $s ) {
			if ( isset( $s[ $key ] ) ) {
				$out[] = array( 'day' => $s['day'], 'value' => $s[ $key ] );
			}
		}
		return $out;
	}

	/** Daily metrics for long-term trends (kept for the history retention period). */
	public static function snapshot() {
		global $wpdb;
		$ov    = self::coverage( 30 );
		$score = Scorer::site();
		$ready = Readiness::get();
		$db    = Installer::table( 'daily_bots' );
		$ai    = self::ai_in();
		$y     = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$m     = array(
			'ai_requests_1d'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(hits) FROM {$db} WHERE day = %s AND bot IN ({$ai})", $y ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'ai_requests_7d'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(hits) FROM {$db} WHERE day >= %s AND bot IN ({$ai})", self::since( 7 ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'ai_bots_7d'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT bot) FROM {$db} WHERE day >= %s AND hits > 0 AND bot IN ({$ai})", self::since( 7 ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'pages'            => $ov['pages'],
			'pages_crawled_30d' => $ov['crawled'],
			'important'        => $ov['important'],
			'important_crawled_30d' => $ov['important_crawled'],
			'coverage'         => $ov['important'] ? round( 100 * $ov['important_crawled'] / $ov['important'], 1 ) : null,
			'aeo_score'        => $score ? $score['score'] : null,
			'aeo_parts'        => $score ? $score['parts'] : null,
			'readiness'        => $ready['score'],
			'readiness_groups' => array_map( static function ( $g ) {
				return $g['score'];
			}, $ready['groups'] ),
			'findings'         => Findings::counts(),
			'referrals_7d'     => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(hits) FROM ' . Installer::table( 'referrals' ) . ' WHERE day >= %s', self::since( 7 ) ) ),
		);
		$today     = Util::day();
		$yesterday = $wpdb->get_var( $wpdb->prepare( 'SELECT metrics FROM ' . Installer::table( 'snapshots' ) . ' WHERE day < %s ORDER BY day DESC LIMIT 1', $today ) );
		$wpdb->replace( Installer::table( 'snapshots' ), array( 'day' => $today, 'metrics' => wp_json_encode( $m ) ) );
		Alerts::after_snapshot( $m, $yesterday ? json_decode( $yesterday, true ) : null );
		return $m;
	}

	// ── unknown agents ─────────────────────────────────────────────────────

	public static function agents( $state = 'new' ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'agents' ) . ' WHERE state = %s ORDER BY FIELD(cls, \'potential\', \'unknown\'), hits DESC LIMIT 200', $state ), ARRAY_A );
		return array_map( static function ( $r ) {
			preg_match( '/([A-Za-z][A-Za-z0-9._-]{2,40})(?:\/[\d.]+)?/', preg_replace( '/^Mozilla\/[\d.]+\s*(\([^)]*\))?\s*/', '', $r['ua'] ), $m );
			return array(
				'hash'       => $r['ua_hash'],
				'ua'         => $r['ua'],
				'cls'        => $r['cls'],
				'hits'       => (int) $r['hits'],
				'first_seen' => (int) $r['first_seen'],
				'last_seen'  => (int) $r['last_seen'],
				'token'      => $m[1] ?? '',
			);
		}, (array) $rows );
	}
}
