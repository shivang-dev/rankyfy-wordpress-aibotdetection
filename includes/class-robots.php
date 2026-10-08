<?php
/**
 * robots.txt as AI crawlers see it.
 *
 * The file is read over HTTP from the live site (so rules a CDN adds — for
 * example a "managed robots.txt" that blocks AI crawlers — are included),
 * falling back to what WordPress itself would serve. Rules are evaluated per
 * the Robots Exclusion Protocol (RFC 9309): the most specific matching
 * user-agent group applies (else "*"), the longest matching rule wins, and
 * an allow beats a disallow of equal length; "*" and "$" are supported.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Robots {

	const OPTION = 'rfy_robots';
	const MATRIX = 'rfy_robots_matrix';

	// ── parser ─────────────────────────────────────────────────────────────

	/** @return array{groups:array,sitemaps:array} */
	public static function parse( $txt ) {
		$groups   = array();
		$sitemaps = array();
		$cur      = null;
		$in_rules = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $txt ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $key, $val ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$key               = strtolower( $key );
			if ( 'user-agent' === $key ) {
				if ( null === $cur || $in_rules ) {
					if ( null !== $cur ) {
						$groups[] = $cur;
					}
					$cur      = array( 'agents' => array(), 'rules' => array() );
					$in_rules = false;
				}
				$cur['agents'][] = strtolower( $val );
			} elseif ( 'allow' === $key || 'disallow' === $key ) {
				if ( null === $cur ) {
					continue; // rules before any user-agent line apply to nobody
				}
				$in_rules = true;
				if ( '' !== $val ) {
					$cur['rules'][] = array( 'allow' === $key, $val );
				}
			} elseif ( 'sitemap' === $key ) {
				$sitemaps[] = $val;
			}
		}
		if ( null !== $cur ) {
			$groups[] = $cur;
		}
		return array(
			'groups'   => $groups,
			'sitemaps' => $sitemaps,
		);
	}

	/** Rules that apply to a crawler's robots token. */
	public static function rules_for( array $parsed, $token ) {
		$token = strtolower( $token );
		$best  = '';
		foreach ( $parsed['groups'] as $g ) {
			foreach ( $g['agents'] as $a ) {
				if ( '*' === $a ) {
					continue;
				}
				// Exact token, or a broader product token ("googlebot" for "googlebot-news").
				if ( ( $a === $token || 0 === strpos( $token, $a . '-' ) ) && strlen( $a ) > strlen( $best ) ) {
					$best = $a;
				}
			}
		}
		$want  = '' === $best ? '*' : $best;
		$rules = array();
		$found = false;
		foreach ( $parsed['groups'] as $g ) {
			if ( in_array( $want, $g['agents'], true ) ) {
				$found = true;
				$rules = array_merge( $rules, $g['rules'] );
			}
		}
		return array(
			'agent' => $found ? $want : '',
			'rules' => $rules,
		);
	}

	private static function pattern_matches( $pattern, $path ) {
		$anchored = '$' === substr( $pattern, -1 );
		$p        = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$re       = '#^' . str_replace( '\*', '.*', preg_quote( $p, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
		return (bool) preg_match( $re, $path );
	}

	/**
	 * Is $path allowed for $token?
	 *
	 * @return array{allowed:bool,rule:string,agent:string}
	 */
	public static function check( array $parsed, $token, $path ) {
		$set   = self::rules_for( $parsed, $token );
		$path  = '' === $path ? '/' : $path;
		$best  = null;
		$blen  = -1;
		foreach ( $set['rules'] as $r ) {
			list( $allow, $pattern ) = $r;
			if ( ! self::pattern_matches( $pattern, $path ) ) {
				continue;
			}
			$len = strlen( $pattern );
			if ( $len > $blen || ( $len === $blen && $allow ) ) {
				$best = $r;
				$blen = $len;
			}
		}
		return array(
			'allowed' => null === $best ? true : (bool) $best[0],
			'rule'    => null === $best ? '' : ( $best[0] ? 'Allow: ' : 'Disallow: ' ) . $best[1],
			'agent'   => $set['agent'],
		);
	}

	// ── the site's file ────────────────────────────────────────────────────

	/** What WordPress itself would serve at /robots.txt. */
	public static function generated() {
		$public = (string) get_option( 'blog_public' );
		$out    = "User-agent: *\n";
		if ( '0' === $public ) {
			$out .= "Disallow: /\n";
		} else {
			$path = (string) wp_parse_url( site_url(), PHP_URL_PATH );
			$out .= "Disallow: {$path}/wp-admin/\nAllow: {$path}/wp-admin/admin-ajax.php\n";
		}
		return (string) apply_filters( 'robots_txt', $out, $public );
	}

	public static function current() {
		$r = get_option( self::OPTION );
		if ( ! is_array( $r ) || ! isset( $r['body'] ) ) {
			$r = array(
				'body'       => self::generated(),
				'source'     => 'generated',
				'status'     => 0,
				'fetched_at' => 0,
				'hash'       => '',
			);
		}
		return $r;
	}

	/** Re-read robots.txt; rebuild the crawler matrix; alert on changes that block AI. */
	public static function refresh() {
		$res    = Probe::get( home_url( '/robots.txt' ), Probe::BROWSER_UA, 8 );
		$status = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $status ) {
			$body   = substr( (string) wp_remote_retrieve_body( $res ), 0, 500 * KB_IN_BYTES );
			$source = 'http';
		} elseif ( $status >= 400 && $status < 500 ) {
			$body   = ''; // RFC 9309: an unavailable (4xx) robots.txt means no restrictions
			$source = 'http';
		} else {
			$body   = self::generated();
			$source = 'generated';
		}
		$prev = get_option( self::MATRIX );
		update_option(
			self::OPTION,
			array(
				'body'       => $body,
				'source'     => $source,
				'status'     => $status,
				'fetched_at' => time(),
				'hash'       => md5( $body ),
			),
			false
		);
		$matrix = self::build_matrix( $body );
		update_option( self::MATRIX, $matrix, false );
		if ( is_array( $prev ) && ! empty( $prev['bots'] ) ) {
			Alerts::robots_changed( $prev, $matrix );
		}
		return $matrix;
	}

	/**
	 * Per crawler: is the site (/) open, which robots group applies, and is it
	 * blocked from a sample of the important pages.
	 */
	public static function build_matrix( $body ) {
		global $wpdb;
		$parsed = self::parse( $body );
		$pages  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, path FROM ' . Installer::table( 'pages' ) . ' WHERE deleted = 0 AND importance >= %d ORDER BY importance DESC LIMIT 500', (int) Settings::get( 'importance_min' ) ),
			ARRAY_A
		);
		$bots = array();
		foreach ( Registry::bots() as $id => $b ) {
			if ( ! $b['robots_tokens'] || ( ! $b['ai'] && 'search' !== $b['category'] ) ) {
				continue;
			}
			$token   = $b['robots_tokens'][0];
			$root    = self::check( $parsed, $token, '/' );
			$blocked = array();
			foreach ( (array) $pages as $p ) {
				$c = self::check( $parsed, $token, rawurldecode( $p['path'] ) );
				if ( ! $c['allowed'] ) {
					$blocked[] = (int) $p['id'];
				}
			}
			$bots[ $id ] = array(
				'token'          => $token,
				'site_allowed'   => $root['allowed'],
				'rule'           => $root['rule'],
				'group'          => $root['agent'],
				'blocked_pages'  => array_slice( $blocked, 0, 500 ),
				'blocked_count'  => count( $blocked ),
				'respects'       => $b['respects_robots'],
			);
		}
		return array(
			'built_at'    => time(),
			'pages_check' => count( (array) $pages ),
			'sitemaps'    => $parsed['sitemaps'],
			'bots'        => $bots,
		);
	}

	public static function matrix() {
		$m = get_option( self::MATRIX );
		if ( ! is_array( $m ) ) {
			$m = self::build_matrix( self::current()['body'] );
		}
		return $m;
	}

	/** Is one path open to one bot under the current file? */
	public static function allows( $bot_id, $path ) {
		$b = Registry::get( $bot_id );
		if ( ! $b || ! $b['robots_tokens'] ) {
			return array( 'allowed' => true, 'rule' => '', 'agent' => '' );
		}
		static $cache = array();
		$body = (string) self::current()['body'];
		$key  = md5( $body );
		if ( ! isset( $cache[ $key ] ) ) {
			$cache = array( $key => self::parse( $body ) ); // keep only the current file
		}
		return self::check( $cache[ $key ], $b['robots_tokens'][0], rawurldecode( $path ) );
	}
}
