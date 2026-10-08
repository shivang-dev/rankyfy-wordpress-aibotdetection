<?php
/**
 * Published crawler address ranges — the strongest cheap proof that a
 * request really comes from the operator it names.
 *
 * Ranges come from RankyFy (one consolidated, already-parsed download) and,
 * when RankyFy is unreachable, straight from each operator's published file.
 * Each file is stored once (several bots share one file) in a non-autoloaded
 * option, pre-bucketed so a lookup is an array index and a handful of
 * integer comparisons, never a scan of thousands of prefixes.
 *
 * Storage is text only (IPv4 as integers, IPv6 as 32-char hex), because
 * option values go through a UTF-8 text column.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Ranges {

	const PREFIX     = 'rfy_rng_';
	const STATUS     = 'rfy_ranges_status';
	const IP_ONLY    = 'rfy_iponly';
	const FRESH_DAYS = 7;
	const MAX        = 20000; // prefixes per file

	private static $loaded = array();

	private static function key( $url ) {
		return self::PREFIX . substr( md5( $url ), 0, 16 );
	}

	/** Every range file named by the registry. */
	public static function urls() {
		$urls = array();
		foreach ( Registry::bots() as $b ) {
			foreach ( $b['verify']['ranges'] as $u ) {
				$urls[ $u ] = true;
			}
		}
		return array_keys( $urls );
	}

	public static function status() {
		$s = get_option( self::STATUS, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function set_status( $url, array $fields ) {
		$s         = self::status();
		$s[ $url ] = array_merge( $s[ $url ] ?? array( 'url' => $url ), $fields );
		// Forget files the registry no longer names.
		$s = array_intersect_key( $s, array_flip( self::urls() ) );
		update_option( self::STATUS, $s, false );
	}

	// ── parsing ────────────────────────────────────────────────────────────

	/** Every CIDR or address found anywhere in a decoded JSON document. */
	public static function extract( $json ) {
		$out  = array();
		$walk = static function ( $v ) use ( &$walk, &$out ) {
			if ( count( $out ) >= self::MAX ) {
				return;
			}
			if ( is_array( $v ) ) {
				foreach ( $v as $x ) {
					$walk( $x );
				}
			} elseif ( is_string( $v ) && strlen( $v ) <= 50 && preg_match( '#^[0-9a-f.:]+(/\d{1,3})?$#i', $v ) && Util::cidr_range( $v ) ) {
				$out[] = $v;
			}
		};
		$walk( $json );
		return array_values( array_unique( $out ) );
	}

	/** Bucket a list of CIDRs. */
	public static function build( array $cidrs ) {
		$v4   = array();
		$v6   = array();
		$wide = array();
		foreach ( $cidrs as $cidr ) {
			$r = Util::cidr_range( $cidr );
			if ( ! $r ) {
				continue;
			}
			if ( 4 === strlen( $r[0] ) ) {
				$s = unpack( 'N', $r[0] )[1];
				$e = unpack( 'N', $r[1] )[1];
				$a = $s >> 16;
				$b = $e >> 16;
				if ( $b - $a > 255 ) {
					$wide[] = array( 4, $s, $e );
					continue;
				}
				for ( $k = $a; $k <= $b; $k++ ) {
					$v4[ $k ][] = array( $s, $e );
				}
			} else {
				$s = bin2hex( $r[0] );
				$e = bin2hex( $r[1] );
				if ( $r[2] < 32 ) {
					$wide[] = array( 6, $s, $e );
					continue;
				}
				$v6[ substr( $s, 0, 8 ) ][] = array( $s, $e );
			}
		}
		return array(
			'v4'   => $v4,
			'v6'   => $v6,
			'wide' => $wide,
		);
	}

	/** Is a packed-address lookup inside a bucketed set? */
	public static function in_set( array $set, $ip ) {
		$bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			return false;
		}
		if ( 4 === strlen( $bin ) ) {
			$n = unpack( 'N', $bin )[1];
			foreach ( $set['v4'][ $n >> 16 ] ?? array() as $r ) {
				if ( $n >= $r[0] && $n <= $r[1] ) {
					return true;
				}
			}
			foreach ( $set['wide'] ?? array() as $r ) {
				if ( 4 === $r[0] && $n >= $r[1] && $n <= $r[2] ) {
					return true;
				}
			}
			return false;
		}
		$h = bin2hex( $bin );
		foreach ( $set['v6'][ substr( $h, 0, 8 ) ] ?? array() as $r ) {
			if ( strcmp( $h, $r[0] ) >= 0 && strcmp( $h, $r[1] ) <= 0 ) {
				return true;
			}
		}
		foreach ( $set['wide'] ?? array() as $r ) {
			if ( 6 === $r[0] && strcmp( $h, $r[1] ) >= 0 && strcmp( $h, $r[2] ) <= 0 ) {
				return true;
			}
		}
		return false;
	}

	public static function store( $url, array $cidrs, $fetched_at, $origin ) {
		$cidrs = array_slice( $cidrs, 0, self::MAX );
		if ( ! $cidrs ) {
			return false;
		}
		$set       = self::build( $cidrs );
		$set['at'] = (int) $fetched_at;
		update_option( self::key( $url ), $set, false );
		unset( self::$loaded[ $url ] );
		self::set_status(
			$url,
			array(
				'fetched_at' => (int) $fetched_at,
				'count'      => count( $cidrs ),
				'origin'     => $origin,
				'error'      => '',
			)
		);
		return true;
	}

	private static function load( $url ) {
		if ( ! array_key_exists( $url, self::$loaded ) ) {
			$set                  = get_option( self::key( $url ) );
			self::$loaded[ $url ] = is_array( $set ) ? $set : null;
		}
		return self::$loaded[ $url ];
	}

	/**
	 * Verification by address for one bot.
	 *
	 * @return string 'verified' | 'failed' (fresh ranges, address not in them) | 'unknown' (no usable ranges)
	 */
	public static function check( $bot_id, $ip ) {
		$bot = Registry::get( $bot_id );
		if ( ! $bot || ! $bot['verify']['ranges'] ) {
			return 'unknown';
		}
		$any_fresh = false;
		foreach ( $bot['verify']['ranges'] as $url ) {
			$set = self::load( $url );
			if ( ! $set ) {
				continue;
			}
			if ( self::in_set( $set, $ip ) ) {
				return 'verified';
			}
			if ( time() - (int) ( $set['at'] ?? 0 ) < self::FRESH_DAYS * DAY_IN_SECONDS ) {
				$any_fresh = true;
			}
		}
		// Only call a request spoofed when we hold current ranges for every file the bot uses.
		return $any_fresh ? 'failed' : 'unknown';
	}

	// ── address-only agents (browser user agent, published ranges) ─────────

	/** Autoloaded bucket map for agents recognised only by address. Empty when none. */
	public static function compile_ip_only() {
		$map   = array();
		$total = 0;
		$st    = self::status();
		foreach ( Registry::bots() as $id => $b ) {
			if ( empty( $b['ip_only'] ) ) {
				continue;
			}
			foreach ( $b['verify']['ranges'] as $url ) {
				$set = self::load( $url );
				if ( $set ) {
					$map[ $id ] = $set; // one file per address-only agent
					$total     += (int) ( $st[ $url ]['count'] ?? 0 );
				}
			}
		}
		// This map is read on every page view, so it must stay small. If the
		// published lists ever grow past this, address-only detection is
		// skipped rather than slowing every visitor down.
		if ( $map && $total <= 3000 ) {
			update_option( self::IP_ONLY, $map, true );
		} else {
			// Keep an (empty) autoloaded value: a missing option costs a query per page view.
			update_option( self::IP_ONLY, array(), true );
		}
	}

	public static function ip_only_match( $ip ) {
		$map = get_option( self::IP_ONLY );
		if ( ! is_array( $map ) || ! $map ) {
			return '';
		}
		foreach ( $map as $id => $set ) {
			if ( self::in_set( $set, $ip ) ) {
				return $id;
			}
		}
		return '';
	}

	// ── refresh ────────────────────────────────────────────────────────────

	/**
	 * Refresh stale range files directly from their publishers. Called by the
	 * worker after RankyFy's consolidated copy (if any) has been applied, so in
	 * the normal case nothing is stale and nothing is fetched here.
	 */
	public static function refresh_direct( $budget_seconds = 20 ) {
		if ( ! Settings::get( 'fetch_ranges_direct' ) ) {
			return 0;
		}
		$deadline = microtime( true ) + $budget_seconds;
		$status   = self::status();
		$done     = 0;
		foreach ( self::urls() as $url ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			$st      = $status[ $url ] ?? array();
			$age     = time() - (int) ( $st['fetched_at'] ?? 0 );
			$tried   = time() - (int) ( $st['tried_at'] ?? 0 );
			if ( $age < DAY_IN_SECONDS || $tried < 6 * HOUR_IN_SECONDS ) {
				continue;
			}
			self::set_status( $url, array( 'tried_at' => time() ) );
			$res = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'redirection'         => 2,
					'limit_response_size' => 4 * MB_IN_BYTES,
					'headers'             => array( 'Accept' => 'application/json' ),
					'user-agent'          => 'RankyFy-AI-Crawler-Monitor/' . RFY_VERSION . ' (+https://rankyfy.com/)',
				)
			);
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( 200 !== $code ) {
				self::set_status( $url, array( 'error' => is_wp_error( $res ) ? $res->get_error_code() : 'http_' . $code ) );
				Log::warning( 'range file unavailable', array( 'url' => $url, 'code' => $code ) );
				continue;
			}
			$json  = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$cidrs = self::extract( $json );
			if ( ! $cidrs ) {
				self::set_status( $url, array( 'error' => 'no_ranges' ) );
				continue;
			}
			self::store( $url, $cidrs, time(), 'publisher' );
			$done++;
		}
		if ( $done ) {
			self::compile_ip_only();
		}
		self::refresh_cloudflare();
		return $done;
	}

	/** Cloudflare edge ranges, used to trust CF-Connecting-IP without any setup. Autoloaded and small. */
	private static function refresh_cloudflare() {
		if ( time() - (int) get_option( 'rfy_cf_at', 0 ) < WEEK_IN_SECONDS ) {
			return;
		}
		update_option( 'rfy_cf_at', time(), false );
		$all = array();
		foreach ( array( 'https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6' ) as $url ) {
			$res = wp_safe_remote_get( $url, array( 'timeout' => 8, 'limit_response_size' => 64 * KB_IN_BYTES ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return; // keep the current list
			}
			foreach ( preg_split( '/\s+/', (string) wp_remote_retrieve_body( $res ) ) as $c ) {
				if ( Util::cidr_range( $c ) ) {
					$all[] = $c;
				}
			}
		}
		if ( count( $all ) >= 10 && count( $all ) <= 200 ) {
			update_option( 'rfy_cf_ranges', $all, true );
		}
	}

	public static function purge_all() {
		self::$loaded = array();
		foreach ( self::urls() as $url ) {
			delete_option( self::key( $url ) );
		}
		delete_option( self::STATUS );
		update_option( self::IP_ONLY, array(), true );
	}
}
