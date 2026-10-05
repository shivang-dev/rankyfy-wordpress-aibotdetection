<?php
/**
 * Small helpers used on the request path and by the worker. Nothing here
 * touches the database.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Util {

	/**
	 * Make an untrusted string safe to store and to show: valid UTF-8, no
	 * control characters (so a crafted user agent cannot forge log lines or
	 * break a CSV export), bounded length.
	 */
	public static function clean( $s, $max = 255 ) {
		$s = (string) $s;
		if ( function_exists( 'mb_scrub' ) ) {
			$s = mb_scrub( $s, 'UTF-8' );
		} elseif ( ! preg_match( '//u', $s ) ) {
			$s = utf8_encode( $s ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions -- fallback only
		}
		$s = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $s );
		$s = trim( (string) $s );
		if ( strlen( $s ) > $max ) {
			$s = function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
		}
		return $s;
	}

	/**
	 * Canonical form of a URL path, used to join requests, inventory pages and
	 * history: no scheme/host, no query or fragment, single slashes, no
	 * trailing slash (except "/"), percent-encoding normalised.
	 */
	public static function normalize_path( $uri ) {
		$uri = (string) $uri;
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $uri ) ) {
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		} else {
			// A request path: "//x" is a path here, not a protocol-relative host.
			$path = (string) preg_replace( '/[?#].*$/s', '', $uri );
		}
		if ( '' === $path ) {
			$path = '/';
		}
		$path = '/' . ltrim( preg_replace( '#/{2,}#', '/', $path ), '/' );
		// Decode then re-encode so "%7E" and "~" (or "café" and "caf%C3%A9") are the same page.
		$path = implode( '/', array_map( static function ( $seg ) {
			return rawurlencode( rawurldecode( $seg ) );
		}, explode( '/', $path ) ) );
		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
		}
		return self::clean( $path, 500 );
	}

	/** Path of a full URL relative to this site's home path. */
	public static function site_path( $url ) {
		return self::normalize_path( $url );
	}

	public static function url_hash( $normalized_path ) {
		return md5( strtolower( $normalized_path ) );
	}

	/** Lightweight label for special paths so the UI can group them. */
	public static function path_kind( $path ) {
		$p = strtolower( $path );
		if ( '/robots.txt' === $p ) {
			return 'robots';
		}
		if ( '/llms.txt' === $p || '/llms-full.txt' === $p ) {
			return 'llms';
		}
		if ( preg_match( '#sitemap[^/]*\.xml$|^/wp-sitemap#', $p ) ) {
			return 'sitemap';
		}
		if ( preg_match( '#/feed$|/feed/|/rss$#', $p ) ) {
			return 'feed';
		}
		if ( 0 === strpos( $p, '/wp-json' ) ) {
			return 'api';
		}
		if ( preg_match( '#\.(?:jpe?g|png|gif|webp|avif|svg|css|js|woff2?|ico|pdf|mp4)$#', $p ) ) {
			return 'asset';
		}
		return '';
	}

	// ── addresses ──────────────────────────────────────────────────────────

	/**
	 * The client address. A proxy header is honoured only when the site owner
	 * named it and the connection really comes from one of their trusted
	 * proxies — otherwise anyone could claim to be OpenAI by sending a header.
	 */
	/** Cloudflare's published edge ranges (refreshed by the worker; this is the fallback). */
	const CLOUDFLARE = array( '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32' );

	/** @var bool|null whether the address in use came from a trusted forwarding header */
	private static $forwarded = null;

	public static function cloudflare_ranges() {
		$r = get_option( 'rfaib_cf_ranges' );
		return is_array( $r ) && count( $r ) >= 10 ? $r : self::CLOUDFLARE;
	}

	/**
	 * The request arrived through a proxy we were not told about: forwarding
	 * headers are present, but the address in use is the connection's. Crawler
	 * verification would then test the proxy's address, so it is skipped.
	 */
	public static function proxy_suspected() {
		if ( null === self::$forwarded ) {
			self::client_ip();
		}
		if ( self::$forwarded ) {
			return false;
		}
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED', 'HTTP_TRUE_CLIENT_IP', 'HTTP_FASTLY_CLIENT_IP' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function client_ip() {
		self::$forwarded = false;
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
		$header = Settings::get( 'proxy_header' );
		// Cloudflare needs no setup: its header is used only when the connection
		// really comes from Cloudflare's published ranges, so it cannot be forged.
		if ( ! $header && $remote && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) && self::ip_in_list( $remote, self::cloudflare_ranges() ) ) {
				self::$forwarded = true;
				return $cf;
			}
		}
		if ( $header && $remote ) {
			$trusted = Settings::get( 'trusted_proxies' );
			if ( $trusted && self::ip_in_list( $remote, preg_split( '/\s+/', $trusted ) ) ) {
				$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
				if ( ! empty( $_SERVER[ $key ] ) ) {
					$parts = explode( ',', (string) $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					// X-Forwarded-For: the right-most address not added by our own proxies is the client.
					$parts = array_reverse( array_map( 'trim', $parts ) );
					foreach ( $parts as $cand ) {
						if ( filter_var( $cand, FILTER_VALIDATE_IP ) && ! self::ip_in_list( $cand, preg_split( '/\s+/', $trusted ) ) ) {
							self::$forwarded = true;
							return $cand;
						}
					}
				}
			}
		}
		return $remote;
	}

	/** True when $ip is inside any CIDR (or equal to any address) in $list. */
	public static function ip_in_list( $ip, array $list ) {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			return false;
		}
		foreach ( $list as $cidr ) {
			$r = self::cidr_range( $cidr );
			if ( $r && strlen( $r[0] ) === strlen( $bin ) && strcmp( $bin, $r[0] ) >= 0 && strcmp( $bin, $r[1] ) <= 0 ) {
				return true;
			}
		}
		return false;
	}

	/** [first, last] packed addresses of a CIDR, or null. */
	public static function cidr_range( $cidr ) {
		$cidr = trim( (string) $cidr );
		if ( '' === $cidr ) {
			return null;
		}
		$parts = explode( '/', $cidr, 2 );
		$bin   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			return null;
		}
		$bits = strlen( $bin ) * 8;
		$len  = isset( $parts[1] ) && '' !== $parts[1] ? (int) $parts[1] : $bits;
		if ( $len < 0 || $len > $bits || ( isset( $parts[1] ) && ! ctype_digit( $parts[1] ) ) ) {
			return null;
		}
		$first = '';
		$last  = '';
		for ( $i = 0; $i < strlen( $bin ); $i++ ) {
			$byte_bits = max( 0, min( 8, $len - $i * 8 ) );
			$mask      = $byte_bits ? ( 0xFF << ( 8 - $byte_bits ) ) & 0xFF : 0;
			$b         = ord( $bin[ $i ] );
			$first    .= chr( $b & $mask );
			$last     .= chr( ( $b & $mask ) | ( ~$mask & 0xFF ) );
		}
		return array( $first, $last, $len );
	}

	/**
	 * Network part of an address (IPv4 /24, IPv6 /48) — enough to see which
	 * network a crawler uses, not enough to identify a person.
	 */
	public static function ip_network( $ip ) {
		$bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			return '';
		}
		if ( 4 === strlen( $bin ) ) {
			return inet_ntop( substr( $bin, 0, 3 ) . "\0" ) . '/24';
		}
		return inet_ntop( substr( $bin, 0, 6 ) . str_repeat( "\0", 10 ) ) . '/48';
	}

	/** Keyed hash of an address: groups requests from one address without storing it. */
	public static function ip_hash( $ip ) {
		return substr( hash_hmac( 'sha256', (string) $ip, (string) get_option( 'rfaib_secret' ) ), 0, 16 );
	}

	public static function now() {
		return time();
	}

	/** Site-local calendar day for a timestamp. */
	public static function day( $ts = null ) {
		return wp_date( 'Y-m-d', null === $ts ? time() : (int) $ts );
	}

	/** Neutralise values that a spreadsheet would execute as formulas. */
	public static function csv_cell( $v ) {
		$v = (string) $v;
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$v = "'" . $v;
		}
		return $v;
	}
}
