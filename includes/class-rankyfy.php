<?php
/**
 * Everything that involves RankyFy's servers, in one place.
 *
 * The plugin never holds keys and never talks to RankyFy services directly.
 * Two kinds of call go to the RankyFy WordPress gateway:
 *
 *  • Public, unauthenticated: the live crawler registry with consolidated
 *    published address ranges (GET /ai-crawlers/registry). Works without an
 *    account; the plugin falls back to its bundled registry and to the
 *    operators' own range files if it is unavailable.
 *
 *  • Account calls, made through the RankyFy SEO plugin's connection
 *    (`\Rankyfy\Auth`) when that plugin is installed and connected — no
 *    second sign-in, no second token store:
 *      POST /ai-crawlers/classify   unrecognised bot user agents (only the UA text)
 *      POST /content/assist         Content AI analysis of one page (uses credits)
 *
 * Every failure degrades to local-only operation: crawler monitoring,
 * verification, robots.txt analysis, page analysis and alerts keep working.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Rankyfy {

	const DEFAULT_GATEWAY = 'https://api.rankyfy.com/api/wp/v1';
	const SYNC            = 'rfy_registry_sync';

	/** RankyFy SEO is installed and its account is connected. */
	public static function available() {
		return class_exists( '\Rankyfy\Auth' ) && \Rankyfy\Auth::is_connected() && \Rankyfy\Auth::project_id();
	}

	public static function state() {
		if ( ! class_exists( '\Rankyfy\Auth' ) ) {
			return 'not_installed';
		}
		if ( ! \Rankyfy\Auth::is_connected() ) {
			return 'not_connected';
		}
		return 'connected';
	}

	public static function gateway_url() {
		if ( class_exists( '\Rankyfy\Config' ) ) {
			return \Rankyfy\Config::gateway_url();
		}
		$url = defined( 'RANKYFY_GATEWAY_URL' ) && RANKYFY_GATEWAY_URL ? (string) RANKYFY_GATEWAY_URL : ( getenv( 'RANKYFY_GATEWAY_URL' ) ?: self::DEFAULT_GATEWAY );
		$url = untrailingslashit( trim( (string) apply_filters( 'rankyfy_gateway_url', $url ) ) );
		return preg_match( '#^https?://#i', $url ) ? $url : self::DEFAULT_GATEWAY;
	}

	/** Authenticated gateway call through RankyFy SEO's connection. */
	public static function request( $method, $path, array $opts = array() ) {
		if ( ! self::available() ) {
			return new WP_Error(
				'rfy_not_connected',
				class_exists( '\Rankyfy\Auth' )
					? __( 'Connect your RankyFy account in RankyFy SEO → Settings to use AI analysis.', 'rankyfy-ai-seo' )
					: __( 'AI analysis uses your RankyFy account. Install and connect the RankyFy SEO plugin to enable it; everything else works without it.', 'rankyfy-ai-seo' ),
				array( 'status' => 409 )
			);
		}
		return \Rankyfy\Auth::request( $method, $path, $opts );
	}

	// ── registry and ranges (public) ───────────────────────────────────────

	public static function sync_registry( $force = false ) {
		$st = get_option( self::SYNC, array() );
		$st = is_array( $st ) ? $st : array();
		if ( ! $force && time() - (int) ( $st['at'] ?? 0 ) < 12 * HOUR_IN_SECONDS ) {
			return 'fresh';
		}
		$st['at'] = time();
		$headers  = array( 'Accept' => 'application/json' );
		if ( ! empty( $st['etag'] ) && ! $force ) {
			$headers['If-None-Match'] = $st['etag'];
		}
		$res = wp_remote_get(
			self::gateway_url() . '/ai-crawlers/registry',
			array(
				'timeout'             => 20,
				'redirection'         => 1,
				'limit_response_size' => 8 * MB_IN_BYTES,
				'headers'             => $headers,
				'user-agent'          => 'RankyFy-AI-Crawler-Monitor/' . RFY_VERSION . '; ' . home_url( '/' ),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 304 === $code ) {
			$st['result'] = 'unchanged';
			update_option( self::SYNC, $st, false );
			return 'unchanged';
		}
		if ( 200 !== $code ) {
			$st['result'] = 'unavailable';
			$st['code']   = $code;
			update_option( self::SYNC, $st, false );
			return 'unavailable';
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$ok   = is_array( $body ) && isset( $body['registry'] ) && Registry::store_remote( (array) $body['registry'] );
		$n    = 0;
		foreach ( (array) ( $body['ranges'] ?? array() ) as $url => $r ) {
			if ( ! is_string( $url ) || ! in_array( $url, Ranges::urls(), true ) || empty( $r['prefixes'] ) ) {
				continue; // only files the registry names
			}
			$cidrs = array_values( array_filter( (array) $r['prefixes'], static function ( $c ) {
				return is_string( $c ) && Util::cidr_range( $c );
			} ) );
			$at    = min( time(), (int) ( $r['fetched_at'] ?? time() ) );
			if ( $cidrs && Ranges::store( $url, $cidrs, $at, 'rankyfy' ) ) {
				$n++;
			}
		}
		if ( $n ) {
			Ranges::compile_ip_only();
		}
		$st['etag']   = (string) wp_remote_retrieve_header( $res, 'etag' );
		$st['result'] = $ok ? 'updated' : 'invalid';
		$st['ranges'] = $n;
		update_option( self::SYNC, $st, false );
		if ( $ok ) {
			Log::info( 'crawler registry updated from RankyFy', array( 'version' => Registry::data()['version'], 'range_files' => $n ) );
		}
		return $st['result'];
	}

	public static function sync_status() {
		$st = get_option( self::SYNC, array() );
		return is_array( $st ) ? $st : array();
	}

	// ── unknown agents (account) ───────────────────────────────────────────

	/**
	 * Ask RankyFy about bot user agents this site does not recognise. Only the
	 * user-agent text and a request count are sent — no address, no URL.
	 */
	public static function share_unknown_agents() {
		global $wpdb;
		if ( ! Settings::get( 'share_unknown' ) || ! self::available() ) {
			return 0;
		}
		$t    = Installer::table( 'agents' );
		$rows = $wpdb->get_results( "SELECT ua_hash, ua, hits FROM {$t} WHERE state = 'new' AND cls IN ('unknown','potential') AND shared_at < last_seen ORDER BY hits DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return 0;
		}
		$res = self::request(
			'POST',
			'/ai-crawlers/classify',
			array(
				'json'    => array( 'agents' => array_map( static function ( $r ) {
					return array( 'ua' => $r['ua'], 'hits' => (int) $r['hits'] );
				}, $rows ) ),
				'timeout' => 20,
			)
		);
		$now = time();
		$ids = implode( "','", array_map( 'esc_sql', array_column( $rows, 'ua_hash' ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET shared_at = %d WHERE ua_hash IN ('{$ids}')", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_wp_error( $res ) ) {
			return 0;
		}
		$n = 0;
		foreach ( (array) ( $res['results'] ?? array() ) as $r ) {
			if ( empty( $r['ua'] ) || ! is_string( $r['ua'] ) ) {
				continue;
			}
			$cls = ! empty( $r['ai'] ) ? 'potential' : 'unknown';
			$wpdb->update( $t, array( 'cls' => $cls ), array( 'ua_hash' => md5( Util::clean( $r['ua'], 255 ) ), 'state' => 'new' ) );
			$n++;
		}
		return $n;
	}

	// ── Content AI page analysis (account, credits) ────────────────────────

	/** @return array|WP_Error the stored analysis */
	public static function analyze_page( $page_id ) {
		global $wpdb;
		if ( ! self::available() ) {
			return self::request( 'POST', '/content/assist' ); // the "connect RankyFy" error, before any work
		}
		$t = Installer::table( 'pages' );
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND deleted = 0", $page_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $p || 'post' !== $p['object_type'] ) {
			return new WP_Error( 'rfy_not_found', __( 'AI analysis is available for posts, pages and products.', 'rankyfy-ai-seo' ), array( 'status' => 404 ) );
		}
		$post = get_post( (int) $p['object_id'] );
		if ( ! $post ) {
			return new WP_Error( 'rfy_not_found', __( 'The page no longer exists.', 'rankyfy-ai-seo' ), array( 'status' => 404 ) );
		}
		$facts = json_decode( (string) $p['facts'], true );
		$terms = array_column( Analyzer::key_terms( (int) $p['id'], 6 ), 'term' );
		$text  = trim( wp_strip_all_tags( preg_replace( '/<!--\s*\/?wp:[^>]*?-->/s', '', strip_shortcodes( $post->post_content ) ) ) );
		if ( Text::word_count( $text ) < 30 ) {
			return new WP_Error( 'rfy_too_short', __( 'This page has too little text in the editor for AI analysis (page-builder layouts are not supported yet).', 'rankyfy-ai-seo' ), array( 'status' => 422 ) );
		}
		$others = $wpdb->get_results(
			$wpdb->prepare( "SELECT path, title FROM {$t} WHERE deleted = 0 AND id <> %d AND object_type = 'post' ORDER BY importance DESC LIMIT 40", $page_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$cats = get_terms( array( 'taxonomy' => 'category', 'orderby' => 'count', 'order' => 'DESC', 'number' => 8, 'fields' => 'names', 'hide_empty' => true ) );
		$res  = self::request(
			'POST',
			'/content/assist',
			array(
				'json'    => array(
					'title'              => get_the_title( $post ),
					'content'            => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 30000 ) : substr( $text, 0, 30000 ),
					'url'                => get_permalink( $post ),
					'slug'               => $post->post_name,
					'content_type'       => $post->post_type,
					'target_keyword'     => $terms[0] ?? '',
					'secondary_keywords' => array_slice( $terms, 1, 5 ),
					'language'           => substr( (string) ( $facts['lang'] ?? get_bloginfo( 'language' ) ), 0, 10 ),
					'site'               => array(
						'name'       => get_bloginfo( 'name' ),
						'tagline'    => get_bloginfo( 'description' ),
						'url'        => home_url( '/' ),
						'categories' => is_array( $cats ) ? array_values( $cats ) : array(),
					),
					'pages'              => array_map( static function ( $o ) {
						return array( 'title' => (string) $o['title'], 'url' => home_url( rawurldecode( $o['path'] ) ) );
					}, (array) $others ),
				),
				'timeout' => 150,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$keep = array( 'summary', 'intent', 'primary_keyword', 'secondary_keywords', 'semantic_terms', 'entities', 'faqs', 'internal_links', 'gaps', 'add', 'depth', 'search_status', 'generated_by' );
		$a    = array_intersect_key( (array) $res, array_flip( $keep ) );
		$wpdb->update( $t, array( 'assist' => wp_json_encode( $a ), 'assist_at' => time() ), array( 'id' => $page_id ) );
		self::inferred_findings( $p, $a, $facts );
		return self::present_assist( $a, time() );
	}

	/** Inferred findings from an analysis; never mixed with measured ones. */
	private static function inferred_findings( array $p, array $a, $facts ) {
		$gaps = array_values( array_filter( array_map( 'strval', (array) ( $a['gaps'] ?? array() ) ) ) );
		foreach ( (array) ( $a['add'] ?? array() ) as $add ) {
			if ( ! empty( $add['topic'] ) ) {
				$gaps[] = (string) $add['topic'];
			}
		}
		$gaps    = array_slice( array_values( array_unique( $gaps ) ), 0, 15 );
		$missing = array();
		foreach ( array_merge( (array) ( $a['semantic_terms'] ?? array() ), (array) ( $a['entities'] ?? array() ) ) as $term ) {
			if ( empty( $term['used'] ) && ! empty( $term['term'] ) ) {
				$missing[] = (string) $term['term'];
			}
		}
		$have = array_map( 'strtolower', array_merge( (array) ( $facts['question_headings'] ?? array() ), (array) ( $facts['questions'] ?? array() ) ) );
		$qs   = array();
		foreach ( (array) ( $a['faqs'] ?? array() ) as $f ) {
			$q = (string) ( $f['question'] ?? '' );
			if ( $q && ! in_array( strtolower( $q ), $have, true ) ) {
				$qs[] = $q;
			}
		}
		$cur = array();
		if ( $gaps ) {
			$cur[] = array( 'content_gaps', array( 'n' => count( $gaps ), 'items' => $gaps ) );
		}
		if ( $missing ) {
			$cur[] = array( 'keyword_gaps', array( 'n' => count( $missing ), 'items' => array_slice( $missing, 0, 20 ) ) );
		}
		if ( $qs ) {
			$cur[] = array( 'faq_opportunities', array( 'n' => count( $qs ), 'items' => array_slice( $qs, 0, 15 ) ) );
		}
		Findings::sync( (int) $p['id'], 'inferred', $cur );
		if ( (int) $p['importance'] >= (int) Settings::get( 'importance_min' ) ) {
			Alerts::opportunity( $p, count( $gaps ), count( $qs ) );
		}
	}

	/**
	 * Analysis for display, with every item labelled by where it comes from:
	 * "measured" (real keyword volumes, questions seen in search results) or
	 * "suggested" (written by the AI model).
	 */
	public static function present_assist( $a, $at ) {
		if ( ! is_array( $a ) || ! $a ) {
			return null;
		}
		$kw = static function ( $k ) {
			return array(
				'keyword'     => (string) ( $k['keyword'] ?? '' ),
				'why'         => (string) ( $k['why'] ?? '' ),
				'volume'      => isset( $k['volume'] ) ? (int) $k['volume'] : null,
				'competition' => (string) ( $k['competition'] ?? '' ),
				'used'        => ! empty( $k['used'] ),
				'source'      => ! empty( $k['measured'] ) ? 'measured' : 'suggested',
			);
		};
		return array(
			'at'                 => (int) $at,
			'summary'            => (string) ( $a['summary'] ?? '' ),
			'intent'             => array(
				'primary'     => (string) ( $a['intent']['primary'] ?? '' ),
				'audience'    => (string) ( $a['intent']['audience'] ?? '' ),
				'explanation' => (string) ( $a['intent']['explanation'] ?? '' ),
				'fit'         => (string) ( $a['intent']['fit'] ?? '' ),
			),
			'primary_keyword'    => isset( $a['primary_keyword'] ) ? $kw( $a['primary_keyword'] ) : null,
			'secondary_keywords' => array_map( $kw, (array) ( $a['secondary_keywords'] ?? array() ) ),
			'semantic_terms'     => array_map( static function ( $t ) {
				return array( 'term' => (string) ( $t['term'] ?? '' ), 'used' => ! empty( $t['used'] ) );
			}, (array) ( $a['semantic_terms'] ?? array() ) ),
			'entities'           => array_map( static function ( $t ) {
				return array( 'term' => (string) ( $t['term'] ?? '' ), 'kind' => (string) ( $t['kind'] ?? '' ), 'used' => ! empty( $t['used'] ) );
			}, (array) ( $a['entities'] ?? array() ) ),
			'questions'          => array_map( static function ( $f ) {
				return array(
					'question' => (string) ( $f['question'] ?? '' ),
					'answer'   => (string) ( $f['answer'] ?? '' ),
					'source'   => ! empty( $f['from_search'] ) ? 'search_results' : 'suggested',
				);
			}, (array) ( $a['faqs'] ?? array() ) ),
			'gaps'               => array_values( array_map( 'strval', (array) ( $a['gaps'] ?? array() ) ) ),
			'additions'          => array_map( static function ( $x ) {
				return array( 'topic' => (string) ( $x['topic'] ?? '' ), 'why' => (string) ( $x['why'] ?? '' ), 'heading' => (string) ( $x['heading'] ?? '' ) );
			}, (array) ( $a['add'] ?? array() ) ),
			'internal_links'     => array_map( static function ( $l ) {
				return array( 'url' => esc_url_raw( (string) ( $l['url'] ?? '' ) ), 'title' => (string) ( $l['title'] ?? '' ), 'anchor' => (string) ( $l['anchor'] ?? '' ), 'why' => (string) ( $l['why'] ?? '' ) );
			}, (array) ( $a['internal_links'] ?? array() ) ),
			'depth'              => array(
				'words'       => (int) ( $a['depth']['words'] ?? 0 ),
				'recommended' => (int) ( $a['depth']['recommended_words'] ?? 0 ),
				'verdict'     => (string) ( $a['depth']['verdict'] ?? '' ),
			),
		);
	}

	/** Daily, opt-in: analyse the most important pages that have no fresh analysis. */
	public static function auto_ai_analysis() {
		global $wpdb;
		if ( ! Settings::get( 'ai_auto' ) || ! self::available() ) {
			return 0;
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . Installer::table( 'pages' ) . " WHERE deleted = 0 AND object_type = 'post' AND (importance >= %d OR pinned > 0) AND assist_at < %d ORDER BY importance DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) Settings::get( 'importance_min' ),
				time() - 30 * DAY_IN_SECONDS,
				min( 5, (int) Settings::get( 'ai_auto_pages' ) ) // at most 5 a day
			)
		);
		$n = 0;
		foreach ( (array) $ids as $id ) {
			$r = self::analyze_page( (int) $id );
			if ( is_wp_error( $r ) ) {
				Log::warning( 'automatic AI analysis stopped', array( 'code' => $r->get_error_code() ) );
				break; // out of credits, not connected, service down: stop for today
			}
			$n++;
		}
		return $n;
	}

}
