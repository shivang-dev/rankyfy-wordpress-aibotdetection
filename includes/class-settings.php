<?php
/**
 * Site owner's preferences. One autoloaded option, because the request-time
 * tracker reads a few of these on every page view and autoloaded options are
 * already in memory.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'rfaib_settings';

	/** key => [default, type, extra] */
	public static function schema() {
		return array(
			'tracking'            => array( true, 'bool' ),
			'track_search_pages'  => array( false, 'bool' ),   // per-page rows for classic search crawlers
			'track_unknown'       => array( true, 'bool' ),    // remember unrecognised bot user agents
			'track_referrals'     => array( true, 'bool' ),    // visits arriving from AI assistants (counts only)
			'exclude_paths'       => array( "/wp-admin/\n/wp-login.php", 'lines' ),
			'proxy_header'        => array( '', 'enum', array( '', 'cf-connecting-ip', 'x-forwarded-for', 'x-real-ip' ) ),
			'trusted_proxies'     => array( '', 'lines' ),     // CIDRs allowed to set proxy_header
			'ip_storage'          => array( 'network', 'enum', array( 'network', 'none' ) ),
			'retention_events'    => array( 30, 'int', array( 1, 365 ) ),
			'retention_history'   => array( 400, 'int', array( 30, 1825 ) ),
			'retention_sessions'  => array( 180, 'int', array( 7, 1825 ) ),
			'verify_rdns'         => array( true, 'bool' ),
			'verify_signatures'   => array( true, 'bool' ),
			'fetch_ranges_direct' => array( true, 'bool' ),    // fall back to operators' own IP lists when RankyFy is unreachable
			'share_unknown'       => array( true, 'bool' ),    // send unrecognised bot user agents (only) to RankyFy for classification
			'probe'               => array( true, 'bool' ),    // test how the site answers requests identifying as AI crawlers
			'importance_min'      => array( 40, 'int', array( 1, 100 ) ),
			'notify_email'        => array( '', 'email' ),
			'notify_mode'         => array( 'critical', 'enum', array( 'off', 'critical', 'all', 'digest' ) ),
			'webhook_url'         => array( '', 'url' ),
			'webhook_level'       => array( 'warning', 'enum', array( 'critical', 'warning', 'info' ) ),
			'ai_auto'             => array( false, 'bool' ),   // spend RankyFy credits on AI analysis of top pages automatically
			'ai_auto_pages'       => array( 10, 'int', array( 1, 100 ) ),
			'priority_bots'       => array( 'oai-searchbot,chatgpt-user,claude-searchbot,claude-user,perplexitybot,perplexity-user,googlebot,bingbot', 'csv' ),
			// Publish-time guard.
			'guard_mode'          => array( 'warn', 'enum', array( 'off', 'warn', 'confirm' ) ), // confirm: critical problems must be acknowledged before publishing
			'guard_redirects'     => array( true, 'bool' ),    // 301 from a published URL that changed (slug or parent) to the new one
			'guard_thin_words'    => array( 300, 'int', array( 50, 3000 ) ),
			// llms.txt / ai.txt (served only when switched on: they are public files).
			'llms_enabled'        => array( false, 'bool' ),
			'llms_full'           => array( false, 'bool' ),   // also /llms-full.txt with the text of the top pages
			'llms_summary'        => array( '', 'text' ),      // the "> summary" line; empty = site tagline
			'llms_intro'          => array( '', 'text' ),      // optional notes under the summary
			'llms_max_links'      => array( 80, 'int', array( 10, 500 ) ),
			'llms_types'          => array( '', 'csv' ),       // post types to list; empty = every public type
			'ai_txt_enabled'      => array( false, 'bool' ),
			'ai_txt_policy'       => array( 'allow', 'enum', array( 'allow', 'no_media', 'no_training' ) ),
		);
	}

	private static $cache = null;

	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$out    = array();
			foreach ( self::schema() as $k => $def ) {
				$out[ $k ] = array_key_exists( $k, $stored ) ? $stored[ $k ] : $def[0];
			}
			if ( '' === $out['notify_email'] ) {
				$out['notify_email'] = (string) get_option( 'admin_email' );
			}
			self::$cache = $out;
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Validate and save a partial update. Unknown keys are ignored, values of
	 * the wrong shape are dropped.
	 *
	 * @return array the saved settings
	 */
	public static function update( array $in ) {
		$schema = self::schema();
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( $in as $k => $v ) {
			if ( ! isset( $schema[ $k ] ) ) {
				continue;
			}
			$clean = self::clean( $v, $schema[ $k ] );
			if ( null !== $clean ) {
				$stored[ $k ] = $clean;
			}
		}
		update_option( self::OPTION, $stored, true );
		self::flush();
		Registry::compile(); // proxy and exclusion settings are part of the request-time matcher
		return self::all();
	}

	private static function clean( $v, array $def ) {
		switch ( $def[1] ) {
			case 'bool':
				return (bool) filter_var( $v, FILTER_VALIDATE_BOOLEAN );
			case 'int':
				$n = (int) $v;
				return max( $def[2][0], min( $def[2][1], $n ) );
			case 'enum':
				return in_array( (string) $v, $def[2], true ) ? (string) $v : null;
			case 'email':
				$v = trim( (string) $v );
				return '' === $v || is_email( $v ) ? sanitize_email( $v ) : null;
			case 'url':
				$v = trim( (string) $v );
				if ( '' === $v ) {
					return '';
				}
				$v    = esc_url_raw( $v, array( 'https' ) );
				$host = strtolower( (string) wp_parse_url( $v, PHP_URL_HOST ) );
				// Syntax only here (no DNS at save time); wp_safe_remote_post refuses
				// internal targets again when an alert is actually sent.
				if ( '' === $v || '' === $host || 'localhost' === $host || false === strpos( $host, '.' )
					|| ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) ) {
					return null;
				}
				return $v;
			case 'csv':
				$parts = array_filter( array_map( 'sanitize_key', explode( ',', (string) $v ) ) );
				return implode( ',', array_slice( array_unique( $parts ), 0, 40 ) );
			case 'text':
				return substr( sanitize_textarea_field( (string) $v ), 0, 2000 );
			case 'lines':
				$lines = preg_split( '/\r\n|\r|\n/', (string) $v );
				$lines = array_filter( array_map( static function ( $l ) {
					return substr( preg_replace( '/[^\x21-\x7E]/', '', trim( $l ) ), 0, 200 );
				}, $lines ) );
				return implode( "\n", array_slice( array_unique( $lines ), 0, 100 ) );
		}
		return null;
	}

	/** What the admin UI sees. */
	public static function client_view() {
		$all = self::all();
		return $all;
	}
}
