<?php
/**
 * Requests the site makes to itself.
 *
 * get() fetches a page the way a visitor would (used for robots.txt and for
 * reading a page's rendered HTML). run() asks "does this site answer a
 * request that identifies as GPTBot the same way it answers a browser?" —
 * firewalls, CDNs and security plugins often refuse AI crawlers by user
 * agent without the owner knowing.
 *
 * Every self-request carries a per-site token header so the tracker does
 * not count it as a visit. The result is only a hint: a CDN that also checks
 * crawler addresses would refuse our request even though it lets the real
 * crawler in, so a refusal is reported as definite only when no verified
 * request from that crawler has succeeded recently.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Probe {

	const OPTION     = 'rfaib_probe';
	const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

	public static function token() {
		return substr( hash_hmac( 'sha256', 'probe', (string) get_option( 'rfaib_secret' ) ), 0, 32 );
	}

	/** @return array|\WP_Error wp_remote_get response */
	public static function get( $url, $ua, $timeout = 10, $redirects = 0 ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => $redirects,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'          => $ua,
				'headers'             => array(
					'X-RFAIB-Probe' => self::token(),
					'Accept'        => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8',
				),
				// Same host as the site: a local certificate problem must not hide the answer.
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	public static function ua_for( array $bot ) {
		$tok = $bot['patterns'][0] ?? $bot['name'];
		return 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ' . $tok . '/1.0; +https://rankyfy.com/ai-crawler-check)';
	}

	public static function run( $budget = 10 ) {
		global $wpdb;
		if ( ! Settings::get( 'probe' ) ) {
			return;
		}
		$deadline = microtime( true ) + $budget;
		$urls     = array( home_url( '/' ) );
		$paths    = $wpdb->get_col( 'SELECT path FROM ' . Installer::table( 'pages' ) . " WHERE deleted = 0 AND object_type = 'post' ORDER BY importance DESC LIMIT 2" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $paths as $p ) {
			$urls[] = home_url( rawurldecode( $p ) );
		}
		$urls    = array_values( array_unique( $urls ) );
		$results = array();
		$base    = array();
		$chal    = array();
		$marker  = '/cf-chl|challenge-platform|captcha|access denied|attention required/i';
		foreach ( $urls as $u ) {
			$r          = self::get( $u, self::BROWSER_UA, 10 );
			$base[ $u ] = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			$chal[ $u ] = ! is_wp_error( $r ) && preg_match( $marker, substr( (string) wp_remote_retrieve_body( $r ), 0, 20000 ) );
		}
		if ( ! array_filter( $base ) ) {
			update_option( self::OPTION, array( 'at' => time(), 'available' => false, 'results' => array() ), false );
			return;
		}
		$bots = array_filter( array_map( array( Registry::class, 'get' ), self::probe_bots() ) );
		foreach ( $bots as $bot ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			foreach ( $urls as $u ) {
				if ( ! $base[ $u ] || $base[ $u ] >= 400 ) {
					continue; // the page is not healthy for browsers either
				}
				$r      = self::get( $u, self::ua_for( $bot ), 10 );
				$code   = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
				$body   = is_wp_error( $r ) ? '' : substr( (string) wp_remote_retrieve_body( $r ), 0, 20000 );
				$refuse = 0 === $code || in_array( $code, array( 401, 403, 406, 429, 451, 503 ), true )
					// A challenge page served only to the crawler is a refusal too.
					|| ( $code < 300 && preg_match( $marker, $body ) && ! $chal[ $u ] );
				$results[] = array(
					'bot'            => $bot['id'],
					'url'            => $u,
					'browser_status' => $base[ $u ],
					'bot_status'     => $code,
					'refused'        => (bool) $refuse,
				);
				if ( $refuse ) {
					break; // one refused URL is enough for this bot
				}
			}
		}
		update_option( self::OPTION, array( 'at' => time(), 'available' => true, 'results' => $results ), false );
	}

	/** AI crawlers worth testing: the owner's priority list, AI ones only. */
	public static function probe_bots() {
		$ids = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'priority_bots' ) ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$b = Registry::get( $id );
			if ( $b && $b['ai'] && $b['patterns'] ) {
				$out[] = $id;
			}
		}
		foreach ( array( 'gptbot', 'claudebot' ) as $id ) {
			if ( Registry::get( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return array_slice( $out, 0, 10 );
	}

	public static function results() {
		$p = get_option( self::OPTION );
		return is_array( $p ) ? $p : array( 'at' => 0, 'available' => null, 'results' => array() );
	}
}
