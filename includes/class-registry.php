<?php
/**
 * The AI crawler registry: who each crawler is, how to recognise it and how
 * to verify it.
 *
 * Three layers, later ones win per bot id:
 *   1. config/registry.json shipped with the plugin (works offline);
 *   2. the live registry from RankyFy (newer crawlers without a plugin update);
 *   3. crawlers the site owner added or adjusted on the Crawlers screen.
 *
 * Everything the request path needs is compiled into one small autoloaded
 * option (`rfy_matcher`): a single case-insensitive regular expression of
 * every user-agent token plus lookup tables. Recognising a visitor therefore
 * costs one preg_match and no database query.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Registry {

	const REMOTE  = 'rfy_registry_remote';
	const CUSTOM  = 'rfy_registry_custom';
	const MATCHER = 'rfy_matcher';

	const CATEGORIES = array( 'ai_training', 'ai_search', 'ai_user', 'ai_agent', 'ai_other', 'search', 'seo', 'social', 'monitor', 'other' );

	private static $merged = null;

	/** The bundled file, decoded. */
	public static function bundled() {
		static $data = null;
		if ( null === $data ) {
			$raw  = file_get_contents( RFY_DIR . 'config/registry.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$data = json_decode( (string) $raw, true );
			$data = is_array( $data ) ? $data : array( 'bots' => array() );
		}
		return $data;
	}

	/** Merged registry: {version, updated, source, bots{id=>bot}, referrers[], heuristics{}} */
	public static function data() {
		if ( null !== self::$merged ) {
			return self::$merged;
		}
		$base   = self::bundled();
		$remote = get_option( self::REMOTE );
		$source = 'bundled';
		if ( is_array( $remote ) && ! empty( $remote['bots'] ) && version_compare( (string) ( $remote['version'] ?? '0' ), (string) ( $base['version'] ?? '0' ), '>=' ) ) {
			$base   = $remote;
			$source = 'rankyfy';
		}
		$bots = array();
		foreach ( (array) ( $base['bots'] ?? array() ) as $b ) {
			$b = self::validate_bot( $b );
			if ( $b ) {
				$b['origin']       = $source;
				$bots[ $b['id'] ] = $b;
			}
		}
		$custom = get_option( self::CUSTOM, array() );
		foreach ( is_array( $custom ) ? $custom : array() as $b ) {
			$b = self::validate_bot( $b );
			if ( $b ) {
				$b['origin']       = 'custom';
				$bots[ $b['id'] ] = $b;
			}
		}
		$heur = (array) ( $base['heuristics'] ?? array() );
		foreach ( array( 'bot', 'ai_hint' ) as $k ) {
			if ( ! isset( $heur[ $k ] ) || ! self::regex_ok( '~' . $heur[ $k ] . '~i' ) ) {
				$heur[ $k ] = self::bundled()['heuristics'][ $k ] ?? '';
			}
		}
		self::$merged = array(
			'version'    => (string) ( $base['version'] ?? '' ),
			'updated'    => (string) ( $base['updated'] ?? '' ),
			'source'     => $source,
			'synced_at'  => is_array( $remote ) ? (int) ( $remote['_synced_at'] ?? 0 ) : 0,
			'bots'       => $bots,
			'referrers'  => self::validate_referrers( (array) ( $base['referrers'] ?? array() ) ),
			'heuristics' => $heur,
			'categories' => (array) ( self::bundled()['categories'] ?? array() ),
		);
		return self::$merged;
	}

	public static function flush() {
		self::$merged = null;
	}

	public static function bots() {
		return self::data()['bots'];
	}

	public static function get( $id ) {
		$bots = self::bots();
		return $bots[ $id ] ?? null;
	}

	public static function is_ai( $id ) {
		$b = self::get( $id );
		return $b ? ! empty( $b['ai'] ) : false;
	}

	/** Display name for a bot id, including the synthetic ids the detector uses. */
	public static function label( $id ) {
		$b = self::get( $id );
		if ( $b ) {
			return $b['name'];
		}
		if ( '_unknown' === $id ) {
			return __( 'Unrecognised bots', 'rankyfy-ai-seo' );
		}
		if ( '_potential' === $id ) {
			return __( 'Possible AI crawlers', 'rankyfy-ai-seo' );
		}
		if ( '_signed' === $id ) {
			return __( 'Signed agent (unknown operator)', 'rankyfy-ai-seo' );
		}
		return $id;
	}

	// ── validation (remote and custom entries are untrusted) ───────────────

	public static function regex_ok( $re ) {
		if ( strlen( $re ) > 600 ) {
			return false;
		}
		return false !== @preg_match( $re, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function str_list( $v, $max_items, $max_len, $pattern = null ) {
		$out = array();
		foreach ( (array) $v as $s ) {
			if ( ! is_string( $s ) ) {
				continue;
			}
			$s = trim( $s );
			if ( '' === $s || strlen( $s ) > $max_len || preg_match( '/[\x00-\x1F\x7F]/', $s ) ) {
				continue;
			}
			if ( $pattern && ! preg_match( $pattern, $s ) ) {
				continue;
			}
			$out[] = $s;
			if ( count( $out ) >= $max_items ) {
				break;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function https_list( $v, $max ) {
		$out = array();
		foreach ( (array) $v as $u ) {
			if ( is_string( $u ) && preg_match( '#^https://[a-z0-9.-]+\.[a-z]{2,}(/[\x21-\x7E]*)?$#i', $u ) && strlen( $u ) < 300 ) {
				$out[] = $u;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/** A clean bot record, or null when the entry is unusable. */
	public static function validate_bot( $b ) {
		if ( ! is_array( $b ) ) {
			return null;
		}
		$id = isset( $b['id'] ) ? (string) $b['id'] : '';
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,39}$/', $id ) ) {
			return null;
		}
		$category = in_array( $b['category'] ?? '', self::CATEGORIES, true ) ? $b['category'] : 'other';
		$verify   = is_array( $b['verify'] ?? null ) ? $b['verify'] : array();
		$out      = array(
			'id'              => $id,
			'name'            => Util::clean( $b['name'] ?? $id, 80 ),
			'provider'        => Util::clean( $b['provider'] ?? '', 80 ),
			'category'        => $category,
			'ai'              => ! empty( $b['ai'] ) || 0 === strpos( $category, 'ai_' ),
			'patterns'        => self::str_list( $b['patterns'] ?? array(), 10, 80 ),
			'robots_tokens'   => self::str_list( $b['robots_tokens'] ?? array(), 6, 60, '/^[A-Za-z0-9 ._\/-]+$/' ),
			'robots_only'     => ! empty( $b['robots_only'] ),
			'ip_only'         => ! empty( $b['ip_only'] ),
			'signed_only'     => ! empty( $b['signed_only'] ),
			'user_triggered'  => ! empty( $b['user_triggered'] ),
			'deprecated'      => ! empty( $b['deprecated'] ),
			'respects_robots' => isset( $b['respects_robots'] ) ? ( null === $b['respects_robots'] ? null : (bool) $b['respects_robots'] ) : null,
			'verify'          => array(
				'ranges'          => self::https_list( $verify['ranges'] ?? array(), 6 ),
				'rdns'            => self::str_list( $verify['rdns'] ?? array(), 6, 80, '/^\.[a-z0-9.-]+$/i' ),
				'signature_hosts' => self::str_list( $verify['signature_hosts'] ?? array(), 4, 80, '/^[a-z0-9.-]+$/i' ),
			),
			'docs'            => ( is_string( $b['docs'] ?? null ) && preg_match( '#^https://#', $b['docs'] ) ) ? esc_url_raw( $b['docs'] ) : '',
			'confidence'      => in_array( $b['confidence'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $b['confidence'] : 'medium',
			'source'          => in_array( $b['source'] ?? '', array( 'official', 'community', 'custom' ), true ) ? $b['source'] : 'community',
			'verified_on'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $b['verified_on'] ?? '' ) ) ? $b['verified_on'] : '',
			'description'     => Util::clean( $b['description'] ?? '', 400 ),
		);
		// A bot nobody can recognise is only useful as a robots.txt token.
		if ( ! $out['patterns'] && ! $out['ip_only'] && ! $out['signed_only'] && ! $out['robots_only'] ) {
			return null;
		}
		return $out;
	}

	private static function validate_referrers( array $refs ) {
		$out = array();
		foreach ( $refs as $r ) {
			if ( ! is_array( $r ) || ! preg_match( '/^[a-z0-9-]{2,20}$/', (string) ( $r['id'] ?? '' ) ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $r['id'],
				'name'  => Util::clean( $r['name'] ?? $r['id'], 60 ),
				'hosts' => array_map( 'strtolower', self::str_list( $r['hosts'] ?? array(), 8, 80, '/^[a-z0-9.-]+$/i' ) ),
				'utm'   => array_map( 'strtolower', self::str_list( $r['utm'] ?? array(), 6, 40, '/^[a-z0-9._-]+$/i' ) ),
			);
		}
		return $out;
	}

	public static function referrer_name( $id ) {
		foreach ( self::data()['referrers'] as $r ) {
			if ( $r['id'] === $id ) {
				return $r['name'];
			}
		}
		return $id;
	}

	// ── custom crawlers (site owner) ───────────────────────────────────────

	public static function custom() {
		$c = get_option( self::CUSTOM, array() );
		return is_array( $c ) ? array_values( $c ) : array();
	}

	/** @return array|\WP_Error */
	public static function save_custom( array $bot ) {
		$bot['source']      = 'custom';
		$bot['verified_on'] = gmdate( 'Y-m-d' );
		$clean              = self::validate_bot( $bot );
		if ( ! $clean || ! $clean['patterns'] ) {
			return new \WP_Error( 'rfy_invalid_bot', __( 'Give the crawler an id (lowercase letters, digits, dashes) and at least one user-agent text to look for.', 'rankyfy-ai-seo' ), array( 'status' => 400 ) );
		}
		$list = array();
		foreach ( self::custom() as $b ) {
			if ( ( $b['id'] ?? '' ) !== $clean['id'] ) {
				$list[] = $b;
			}
		}
		$list[] = $clean;
		update_option( self::CUSTOM, array_slice( $list, -100 ), false );
		self::flush();
		self::compile();
		return $clean;
	}

	public static function delete_custom( $id ) {
		$list = array_values( array_filter( self::custom(), static function ( $b ) use ( $id ) {
			return ( $b['id'] ?? '' ) !== $id;
		} ) );
		update_option( self::CUSTOM, $list, false );
		self::flush();
		self::compile();
	}

	/** Store a registry received from RankyFy (already decoded). */
	public static function store_remote( array $data ) {
		if ( empty( $data['bots'] ) || ! is_array( $data['bots'] ) ) {
			return false;
		}
		$valid = 0;
		foreach ( $data['bots'] as $b ) {
			if ( self::validate_bot( $b ) ) {
				$valid++;
			}
		}
		// Refuse a registry that would silently drop most crawlers.
		if ( $valid < 10 ) {
			return false;
		}
		$data['_synced_at'] = time();
		update_option( self::REMOTE, $data, false );
		self::flush();
		self::compile();
		return true;
	}

	// ── compiled matcher (request path) ────────────────────────────────────

	/** Bot priority when one user agent matches several tokens. */
	private static function priority( array $b ) {
		$p = $b['ai'] ? 300 : ( 'search' === $b['category'] ? 200 : 100 );
		return $p - ( $b['deprecated'] ? 50 : 0 );
	}

	public static function compile() {
		self::flush();
		$data   = self::data();
		$tokens = array();
		$info   = array();
		$sig    = array();
		foreach ( $data['bots'] as $id => $b ) {
			foreach ( $b['patterns'] as $p ) {
				$tokens[ strtolower( $p ) ] = $id;
			}
			foreach ( $b['verify']['signature_hosts'] as $h ) {
				$sig[ strtolower( $h ) ] = $id;
			}
			$info[ $id ] = array(
				'ai' => $b['ai'] ? 1 : 0,
				'c'  => $b['category'],
				'p'  => self::priority( $b ),
				'r'  => $b['verify']['ranges'] ? 1 : 0,
				'd'  => $b['verify']['rdns'] ? 1 : 0,
			);
		}
		// Longest first, so "Claude-SearchBot" wins over a shorter token at the same position.
		uksort( $tokens, static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
		$alts = array_map( static function ( $t ) {
			return preg_quote( $t, '~' );
		}, array_keys( $tokens ) );
		$re   = $alts ? '~' . implode( '|', $alts ) . '~i' : '';
		// Tokens are quoted literals, so this only fails on a pathological registry.
		if ( $re && ( strlen( $re ) > 20000 || false === @preg_match( $re, '' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$re = '';
		}

		$ref = array();
		$utm = array();
		foreach ( $data['referrers'] as $r ) {
			foreach ( $r['hosts'] as $h ) {
				$ref[ $h ] = $r['id'];
			}
			foreach ( $r['utm'] as $u ) {
				$utm[ $u ] = $r['id'];
			}
		}

		$exclude = array_values( array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'exclude_paths' ) ) ) ) );

		$matcher = array(
			'v'    => $data['version'],
			're'   => $re,
			'tok'  => $tokens,
			'info' => $info,
			'bot'  => '~' . $data['heuristics']['bot'] . '~i',
			'hint' => '~' . $data['heuristics']['ai_hint'] . '~i',
			'ref'  => $ref,
			'utm'  => $utm,
			'sig'  => $sig,
			'ex'   => $exclude,
		);
		update_option( self::MATCHER, $matcher, true );
		Ranges::compile_ip_only();
		return $matcher;
	}

	public static function matcher() {
		$m = get_option( self::MATCHER );
		if ( ! is_array( $m ) || ! isset( $m['re'] ) ) {
			$m = self::compile();
		}
		return $m;
	}

	/** The registry as the admin UI shows it. */
	public static function client_view() {
		$d    = self::data();
		$out  = array();
		$rng  = Ranges::status();
		foreach ( $d['bots'] as $b ) {
			$b['ranges_status'] = array();
			foreach ( $b['verify']['ranges'] as $u ) {
				$b['ranges_status'][] = $rng[ $u ] ?? array( 'url' => $u, 'fetched_at' => 0, 'count' => 0 );
			}
			$out[] = $b;
		}
		return array(
			'version'    => $d['version'],
			'updated'    => $d['updated'],
			'source'     => $d['source'],
			'synced_at'  => $d['synced_at'],
			'categories' => $d['categories'],
			'bots'       => $out,
			'referrers'  => $d['referrers'],
			'heuristics' => $d['heuristics'],
		);
	}
}
