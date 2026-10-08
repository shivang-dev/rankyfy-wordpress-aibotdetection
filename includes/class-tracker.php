<?php
/**
 * The request path.
 *
 * boot() runs while the plugin file loads. For an ordinary visitor it does
 * one regular-expression test on the user agent (plus, only when the visitor
 * came from an AI assistant, a referrer check) and returns: no database
 * query, no hook, no object. Requests from crawlers register one shutdown
 * callback that records the request after WordPress has produced the
 * response — so the status code, the page and the time taken are the real
 * ones — with one INSERT (two when a verification has to be queued).
 *
 * Volume protection: per-page rows are written only for AI crawlers (and
 * search crawlers if the owner asks); everything else is a single counter
 * upsert. Addresses that flood the site while failing verification are
 * throttled by the worker through a small autoloaded list.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Tracker {

	const THROTTLE = 'rfy_throttle';

	/** @var array|null classification of the current request */
	private static $hit = null;

	public static function boot() {
		if ( ! self::should_track() ) {
			return;
		}
		$m  = Registry::matcher();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( $m['ex'] ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $m['ex'] as $prefix ) {
				if ( '' !== $prefix && 0 === strpos( $uri, $prefix ) ) {
					return;
				}
			}
		}

		// A Signature-Agent header counts only together with the signature itself;
		// on its own it is just a claim anyone can send.
		$sig = ( ! empty( $_SERVER['HTTP_SIGNATURE_AGENT'] ) && ! empty( $_SERVER['HTTP_SIGNATURE_INPUT'] ) && ! empty( $_SERVER['HTTP_SIGNATURE'] ) ) ? (string) $_SERVER['HTTP_SIGNATURE_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// The address is only needed when the user agent looks like a browser
		// and address-only agents are configured; Detector asks for it lazily.
		$ip  = get_option( Ranges::IP_ONLY ) ? Util::client_ip() : '';
		$r   = Detector::match( $ua, $m, $ip, $sig );

		if ( 'human' === $r['cls'] ) {
			$ref = self::referral( $m );
			if ( $ref ) {
				self::$hit = array( 'ref' => $ref );
				add_action( 'shutdown', array( __CLASS__, 'record_referral' ), PHP_INT_MAX );
			}
			return;
		}
		$r['ua']   = $ua;
		self::$hit = $r;
		add_action( 'shutdown', array( __CLASS__, 'record' ), PHP_INT_MAX );
	}

	/** The registered crawler making the current request, or '' (visitors, unknown bots). */
	public static function current_bot() {
		if ( ! is_array( self::$hit ) || empty( self::$hit['bot'] ) || 'spoofed' === ( self::$hit['cls'] ?? '' ) ) {
			return '';
		}
		return (string) self::$hit['bot'];
	}

	private static function should_track() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ) {
			return false;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! Installer::ready() || ! Settings::get( 'tracking' ) ) {
			return false;
		}
		// The plugin's own probes ("how does the site answer GPTBot?") are not visits.
		if ( isset( $_SERVER['HTTP_X_RFY_PROBE'] ) && hash_equals( Probe::token(), (string) $_SERVER['HTTP_X_RFY_PROBE'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return false;
		}
		return true;
	}

	/** AI assistant that sent this visitor, from the Referer host or a utm_source. */
	private static function referral( array $m ) {
		if ( ! Settings::get( 'track_referrals' ) ) {
			return '';
		}
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) && $m['ref'] ) {
			$host = strtolower( (string) wp_parse_url( (string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( isset( $m['ref'][ $host ] ) ) {
				return $m['ref'][ $host ];
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only attribution parameter
		if ( ! empty( $_GET['utm_source'] ) && is_string( $_GET['utm_source'] ) && $m['utm'] ) {
			$src = strtolower( substr( (string) $_GET['utm_source'], 0, 40 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended
			if ( isset( $m['utm'][ $src ] ) ) {
				return $m['utm'][ $src ];
			}
		}
		return '';
	}

	/** What WordPress served: object, path, status, time. */
	private static function context() {
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path   = Util::normalize_path( $uri );
		$status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		$status = $status > 0 ? $status : 200;
		$start  = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$otype  = '';
		$oid    = 0;
		if ( did_action( 'wp' ) && function_exists( 'get_queried_object' ) ) {
			$obj = get_queried_object();
			if ( $obj instanceof \WP_Post ) {
				$otype = 'post';
				$oid   = (int) $obj->ID;
			} elseif ( $obj instanceof \WP_Term ) {
				$otype = 'term';
				$oid   = (int) $obj->term_id;
			} elseif ( function_exists( 'is_home' ) && is_home() && is_front_page() ) {
				$otype = 'home';
			}
		}
		return array(
			'path'   => $path,
			'hash'   => Util::url_hash( $path ),
			'kind'   => Util::path_kind( $path ),
			'status' => min( 999, $status ),
			'ms'     => (int) max( 0, min( 600000, ( microtime( true ) - $start ) * 1000 ) ),
			'otype'  => $otype,
			'oid'    => $oid,
			'method' => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $_SERVER['REQUEST_METHOD'] ), 0, 7 ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'ts'     => isset( $_SERVER['REQUEST_TIME'] ) ? (int) $_SERVER['REQUEST_TIME'] : time(),
		);
	}

	public static function record() {
		$r = self::$hit;
		if ( ! $r ) {
			return;
		}
		self::$hit = null;
		try {
			$ctx = self::context();
			$sig = self::signature_headers();
			self::release_client();
			self::store( $r, $ctx, Util::client_ip(), 'live', $sig );
		} catch ( \Throwable $e ) {
			// Never let monitoring break a response.
			Log::error( 'record failed', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Persist one classified request. Shared by live tracking and log import.
	 *
	 * @param array  $r   Detector::match() result plus 'ua'.
	 * @param array  $ctx context(): path, hash, kind, status, ms, otype, oid, method, ts.
	 * @param string $ip  client address (used for verification; stored only as network + keyed hash).
	 * @return string what happened: event | counted | throttled | skipped
	 */
	public static function store( array $r, array $ctx, $ip, $source = 'live', array $sig = array() ) {
		global $wpdb;
		$ip_hash = $ip ? Util::ip_hash( $ip ) : '';
		$cls     = $r['cls'];
		$bot     = $r['bot'];
		$v       = array( 'vstate' => 'none', 'needs' => '' );

		if ( in_array( $cls, array( 'ai', 'search', 'known' ), true ) ) {
			$v = Detector::verify( $r, $ip, 'live' === $source );
			if ( 'failed' === $v['vstate'] ) {
				$cls = 'spoofed';
			}
		}

		$per_page = 'ai' === $cls || 'spoofed' === $cls || 'potential' === $cls
			|| ( 'search' === $cls && Settings::get( 'track_search_pages' ) );

		if ( $per_page && $ip_hash && self::throttled( $ip_hash ) ) {
			$per_page = false;
			$v['needs'] = '';
		}

		if ( in_array( $cls, array( 'unknown', 'potential' ), true ) && Settings::get( 'track_unknown' ) ) {
			self::remember_agent( $r['ua'], $cls, $ctx['ts'], 'live' === $source );
		}

		if ( ! $per_page && 'live' !== $source ) {
			// Counters cannot be de-duplicated, so imported logs only add per-page
			// rows (which can). Re-importing a log never inflates a number.
			return 'not_recorded';
		}
		if ( ! $per_page ) {
			// One counter row per day and crawler; no page, no address.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
			$wpdb->query( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
				$wpdb->prepare(
					'INSERT INTO ' . Installer::table( 'daily_bots' ) . ' (day, bot, hits, verified, spoofed, errors, ms_total) VALUES (%s, %s, %d, %d, %d, %d, %d)
					 ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), verified = verified + VALUES(verified), spoofed = spoofed + VALUES(spoofed), errors = errors + VALUES(errors), ms_total = ms_total + VALUES(ms_total)',
					Util::day( $ctx['ts'] ),
					$bot ? $bot : '_unknown',
					'spoofed' === $cls ? 0 : 1, // hits are genuine requests; impersonations are counted apart
					'verified' === $v['vstate'] ? 1 : 0,
					'spoofed' === $cls ? 1 : 0,
					$ctx['status'] >= 400 ? 1 : 0,
					$ctx['ms']
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			return 'counted';
		}

		$ua    = Util::clean( $r['ua'], 255 );
		$net   = 'network' === Settings::get( 'ip_storage' ) ? Util::ip_network( $ip ) : '';
		// Same second, address, page, method and user agent = the same request
		// (seen live and again in an imported log).
		$dedup = md5( $ctx['ts'] . '|' . $ip_hash . '|' . strtolower( $ctx['path'] ) . '|' . $ctx['method'] . '|' . $ua );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
		$ok    = $wpdb->query( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Installer::table( 'events' ) . ' (ts, day, bot, cls, vstate, method, path, url_hash, kind, object_type, object_id, status, ms, ip_net, ip_hash, ua, source, dedup)
				 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %s, %s, %s, %s, %s)',
				$ctx['ts'],
				Util::day( $ctx['ts'] ),
				$bot,
				$cls,
				$v['vstate'],
				$ctx['method'],
				$ctx['path'],
				$ctx['hash'],
				$ctx['kind'],
				$ctx['otype'],
				$ctx['oid'],
				$ctx['status'],
				$ctx['ms'],
				$net,
				$ip_hash,
				$ua,
				$source,
				$dedup
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $ok ) {
			return 'skipped'; // duplicate (log import of a request we already saw live)
		}
		if ( $v['needs'] && $ip ) {
			Verifier::enqueue( $v['needs'], (int) $wpdb->insert_id, $bot, $ip, $ip_hash, 'sig' === $v['needs'] ? $sig : array() );
		}
		return 'event';
	}

	public static function record_referral() {
		global $wpdb;
		$r = self::$hit;
		self::$hit = null;
		if ( ! $r || empty( $r['ref'] ) ) {
			return;
		}
		$ctx = self::context();
		if ( $ctx['status'] >= 400 || 'asset' === $ctx['kind'] || 'GET' !== $ctx['method'] ) {
			return;
		}
		self::release_client();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
		$wpdb->query( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
			$wpdb->prepare(
				'INSERT INTO ' . Installer::table( 'referrals' ) . ' (day, source, url_hash, path, object_id, hits) VALUES (%s, %s, %s, %s, %d, 1)
				 ON DUPLICATE KEY UPDATE hits = hits + 1',
				Util::day( $ctx['ts'] ),
				$r['ref'],
				$ctx['hash'],
				$ctx['path'],
				$ctx['oid']
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Finish the HTTP response before writing, so the visitor never waits for
	 * the database. This runs as the very last shutdown callback (WordPress
	 * has already flushed its output buffers at priority 1), so nothing that
	 * comes after it can lose output.
	 */
	private static function release_client() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	private static function remember_agent( $ua, $cls, $ts, $count = true ) {
		global $wpdb;
		$ua = Util::clean( $ua, 255 );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Installer::table( 'agents' ) . ' (ua_hash, ua, cls, state, hits, first_seen, last_seen) VALUES (%s, %s, %s, %s, ' . ( $count ? 1 : 0 ) . ', %d, %d)
				 ON DUPLICATE KEY UPDATE hits = hits + ' . ( $count ? 1 : 0 ) . ', last_seen = GREATEST(last_seen, VALUES(last_seen))', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from the fixed rfy_ prefix (Installer::table), never from user input
				md5( $ua ),
				$ua,
				$cls,
				'new',
				$ts,
				$ts
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Signature headers (and the covered fields) for Web Bot Auth verification. */
	private static function signature_headers() {
		if ( empty( $_SERVER['HTTP_SIGNATURE_AGENT'] ) || empty( $_SERVER['HTTP_SIGNATURE_INPUT'] ) || empty( $_SERVER['HTTP_SIGNATURE'] ) ) {
			return array();
		}
		$out = array(
			'@method'         => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'@authority'      => isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'@scheme'         => is_ssl() ? 'https' : 'http',
			'@target-uri'     => '',
			'@path'           => (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'@query'          => '?' . (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_QUERY ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'signature'       => substr( (string) $_SERVER['HTTP_SIGNATURE'], 0, 2000 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'signature-input' => substr( (string) $_SERVER['HTTP_SIGNATURE_INPUT'], 0, 2000 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'signature-agent' => substr( (string) $_SERVER['HTTP_SIGNATURE_AGENT'], 0, 500 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		$out['@target-uri'] = $out['@scheme'] . '://' . $out['@authority'] . (string) ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// Any other plain header the signature covers.
		if ( preg_match_all( '/"([a-z0-9-]+)"/', $out['signature-input'], $mm ) ) {
			foreach ( array_slice( array_unique( $mm[1] ), 0, 12 ) as $name ) {
				$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
				if ( ! isset( $out[ $name ] ) && isset( $_SERVER[ $key ] ) ) {
					$out[ $name ] = substr( (string) $_SERVER[ $key ], 0, 1000 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				}
			}
		}
		return $out;
	}

	/** Remember (at most daily) that crawler requests arrive through an unconfigured proxy. */
	public static function note_proxy() {
		if ( ! get_transient( 'rfy_proxy_seen' ) ) {
			set_transient( 'rfy_proxy_seen', 1, DAY_IN_SECONDS );
			update_option( 'rfy_proxy_noted', time(), false );
		}
	}

	private static function throttled( $ip_hash ) {
		$t = get_option( self::THROTTLE );
		if ( ! is_array( $t ) || empty( $t['ips'] ) || (int) ( $t['until'] ?? 0 ) < time() ) {
			return false;
		}
		return isset( $t['ips'][ $ip_hash ] );
	}
}
