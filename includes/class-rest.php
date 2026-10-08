<?php
/**
 * REST API for the admin screens: rankyfy-aib/v1.
 *
 * Cookie authentication with the REST nonce, a capability check on every
 * route (manage_options by default, filterable), per-user rate limits,
 * strict argument validation. No address, token or secret is ever returned;
 * events expose only the network part of an address.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class Rest {

	const NS = 'rankyfy-aib/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function capability() {
		return (string) apply_filters( 'rfaib_capability', 'manage_options' );
	}

	public static function can() {
		return current_user_can( self::capability() );
	}

	private static function route( $path, $methods, $callback, array $args = array(), $limit = null, $permission = null ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'permission_callback' => $permission ? $permission : array( __CLASS__, 'can' ),
				'args'                => $args,
				'callback'            => static function ( WP_REST_Request $r ) use ( $callback, $methods, $limit ) {
					$action = $limit ? $limit : ( WP_REST_Server::READABLE === $methods ? 'read' : 'write' );
					$ok     = Rate_Limiter::hit( $action );
					if ( is_wp_error( $ok ) ) {
						return $ok;
					}
					try {
						return call_user_func( $callback, $r );
					} catch ( \Throwable $e ) {
						Log::error( 'REST handler failed', array( 'route' => $r->get_route(), 'error' => $e->getMessage() ) );
						return new WP_Error( 'rfaib_error', __( 'Something went wrong. The details were written to the plugin log.', 'rankyfy-ai-crawlers' ), array( 'status' => 500 ) );
					}
				},
			)
		);
	}

	public static function routes() {
		$days  = array(
			'days' => array(
				'default'           => 30,
				'sanitize_callback' => static function ( $v ) {
					return in_array( (int) $v, array( 1, 7, 14, 30, 90, 180, 365 ), true ) ? (int) $v : 30;
				},
			),
		);
		$id    = array(
			'id' => array(
				'validate_callback' => static function ( $v ) {
					return is_numeric( $v ) && (int) $v > 0;
				},
			),
		);
		$botid = array(
			'id' => array(
				'validate_callback' => static function ( $v ) {
					return (bool) preg_match( '/^_?[a-z0-9-]{2,40}$/', (string) $v );
				},
			),
		);
		$c = __CLASS__;
		self::route( '/status', WP_REST_Server::READABLE, array( $c, 'status' ) );
		self::route( '/overview', WP_REST_Server::READABLE, array( $c, 'overview' ), $days );
		self::route( '/bots', WP_REST_Server::READABLE, array( $c, 'bots' ), $days );
		self::route( '/bots/(?P<id>_?[a-z0-9-]{2,40})', WP_REST_Server::READABLE, array( $c, 'bot' ), $botid + $days );
		self::route( '/pages', WP_REST_Server::READABLE, array( $c, 'pages' ) );
		self::route( '/pages/(?P<id>\d+)', WP_REST_Server::READABLE, array( $c, 'page' ), $id );
		self::route( '/pages/(?P<id>\d+)/pin', WP_REST_Server::CREATABLE, array( $c, 'pin' ), $id );
		self::route( '/pages/(?P<id>\d+)/analyze', WP_REST_Server::CREATABLE, array( $c, 'analyze' ), $id, 'analyze' );
		self::route( '/pages/(?P<id>\d+)/ai-analyze', WP_REST_Server::CREATABLE, array( $c, 'ai_analyze' ), $id, 'ai_analyze' );
		self::route( '/recommendations', WP_REST_Server::READABLE, array( $c, 'recommendations' ) );
		self::route( '/findings/(?P<id>\d+)', WP_REST_Server::CREATABLE, array( $c, 'finding' ), $id );
		self::route( '/opportunities', WP_REST_Server::READABLE, array( $c, 'opportunities' ) );
		self::route( '/technical', WP_REST_Server::READABLE, array( $c, 'technical' ) );
		self::route( '/technical/refresh', WP_REST_Server::CREATABLE, array( $c, 'technical_refresh' ), array(), 'sync' );
		self::route( '/history', WP_REST_Server::READABLE, array( $c, 'history' ) );
		self::route( '/alerts', WP_REST_Server::READABLE, array( $c, 'alerts' ) );
		self::route( '/alerts/(?P<id>\d+|all)', WP_REST_Server::CREATABLE, array( $c, 'alert' ) );
		self::route( '/alerts/test', WP_REST_Server::CREATABLE, array( $c, 'alert_test' ), array(), 'test' );
		self::route( '/registry', WP_REST_Server::READABLE, array( $c, 'registry' ) );
		self::route( '/registry/sync', WP_REST_Server::CREATABLE, array( $c, 'registry_sync' ), array(), 'sync' );
		self::route( '/registry/custom', WP_REST_Server::CREATABLE, array( $c, 'registry_add' ) );
		self::route( '/registry/custom/(?P<id>[a-z0-9-]{2,40})', WP_REST_Server::DELETABLE, array( $c, 'registry_delete' ), $botid );
		self::route( '/agents', WP_REST_Server::READABLE, array( $c, 'agents' ) );
		self::route( '/agents/(?P<hash>[a-f0-9]{32})', WP_REST_Server::CREATABLE, array( $c, 'agent' ) );
		self::route( '/import', WP_REST_Server::CREATABLE, array( $c, 'import' ), array(), 'import' );
		self::route( '/settings', WP_REST_Server::READABLE, array( $c, 'get_settings' ) );
		self::route( '/settings', WP_REST_Server::CREATABLE, array( $c, 'save_settings' ) );
		self::route( '/log', WP_REST_Server::READABLE, array( $c, 'log' ) );

		self::route( '/crawlers/pages', WP_REST_Server::READABLE, array( $c, 'crawler_pages' ), $days );
		self::route( '/crawlers/url/(?P<hash>[a-f0-9]{32})', WP_REST_Server::READABLE, array( $c, 'crawler_url' ) );
		self::route( '/referrals', WP_REST_Server::READABLE, array( $c, 'referrals' ), $days );
		self::route( '/access', WP_REST_Server::READABLE, array( $c, 'access' ) );
		self::route( '/access', WP_REST_Server::CREATABLE, array( $c, 'access_save' ), array(), 'sync' );
		self::route( '/compat', WP_REST_Server::READABLE, array( $c, 'compat' ) );
		self::route( '/llms/override', WP_REST_Server::CREATABLE, array( $c, 'llms_override' ) );
		self::route( '/first-hit', WP_REST_Server::CREATABLE, array( $c, 'first_hit' ) );
		self::route( '/redirects', WP_REST_Server::CREATABLE, array( $c, 'redirect_add' ) );
		self::route( '/prune', WP_REST_Server::CREATABLE, array( $c, 'prune' ), array(), 'sync' );
		self::route( '/readiness', WP_REST_Server::READABLE, array( $c, 'readiness' ) );
		self::route( '/readiness/run', WP_REST_Server::CREATABLE, array( $c, 'readiness_run' ), array(), 'analyze' );
		self::route( '/readiness/(?P<check>[a-z_]{2,40})', WP_REST_Server::CREATABLE, array( $c, 'readiness_status' ) );
		self::route( '/ai-files', WP_REST_Server::READABLE, array( $c, 'ai_files' ) );
		self::route( '/ai-files/check', WP_REST_Server::CREATABLE, array( $c, 'ai_files_check' ), array(), 'sync' );
		self::route( '/redirects', WP_REST_Server::READABLE, array( $c, 'redirects' ) );
		self::route( '/redirects/(?P<id>\d+)', WP_REST_Server::DELETABLE, array( $c, 'redirect_delete' ), $id );
		self::route(
			'/guard/(?P<id>\d+)/choice',
			WP_REST_Server::CREATABLE,
			array( $c, 'guard_choice' ),
			$id,
			'guard',
			static function ( WP_REST_Request $r ) {
				return current_user_can( 'edit_post', (int) $r['id'] );
			}
		);
		// The publish guard is used by whoever edits the post, not only administrators.
		self::route(
			'/guard/(?P<id>\d+)',
			WP_REST_Server::CREATABLE,
			array( $c, 'guard' ),
			$id + array(
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'slug'    => array( 'type' => 'string' ),
				'parent'  => array( 'type' => 'integer', 'minimum' => 0 ),
			),
			'guard',
			static function ( WP_REST_Request $r ) {
				return current_user_can( 'edit_post', (int) $r['id'] );
			}
		);
	}

	// ── handlers ───────────────────────────────────────────────────────────

	public static function status() {
		global $wpdb;
		$age = Worker::age();
		// Keep things moving on sites where WP-Cron rarely fires.
		if ( null === $age || $age > 10 * MINUTE_IN_SECONDS ) {
			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ( null === $age || $age > HOUR_IN_SECONDS ) ) {
				Worker::tick( 5 );
				$age = Worker::age();
			} else {
				spawn_cron();
			}
		}
		$sizes = array();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT TABLE_NAME n, TABLE_ROWS r, DATA_LENGTH + INDEX_LENGTH b FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s', DB_NAME, $wpdb->esc_like( $wpdb->prefix . 'rfaib_' ) . '%' ),
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$sizes[ substr( $r['n'], strlen( $wpdb->prefix . 'rfaib_' ) ) ] = array( 'rows' => (int) $r['r'], 'bytes' => (int) $r['b'] );
		}
		$reg = Registry::data();
		return array(
			'version'          => RFAIB_VERSION,
			'monitoring_since' => (int) get_option( 'rfaib_monitoring_since' ),
			'tracking'         => (bool) Settings::get( 'tracking' ),
			'worker'           => array(
				'age'          => $age,
				'stalled'      => null !== $age && $age > HOUR_IN_SECONDS,
				'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'last_aggregation' => (int) get_option( 'rfaib_agg_last' ),
				'pending_verification' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'verify_queue' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'backlog'      => max( 0, (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Installer::table( 'events' ) ) - (int) get_option( Aggregator::WATERMARK, 0 ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			),
			'inventory'        => Inventory::status() + array( 'pages' => Inventory::count(), 'dirty' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'pages' ) . ' WHERE deleted = 0 AND dirty = 1' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'baseline'         => (bool) get_option( Alerts::BASELINE ),
			'registry'         => array(
				'version'  => $reg['version'],
				'source'   => $reg['source'],
				'bots'     => count( $reg['bots'] ),
				'sync'     => Rankyfy::sync_status(),
			),
			'rankyfy'          => Rankyfy::state(),
			'object_cache'     => wp_using_ext_object_cache(),
			'tables'           => $sizes,
			'unread_alerts'    => Alerts::unread_count(),
			'throttled'        => count( (array) ( get_option( Tracker::THROTTLE )['ips'] ?? array() ) ),
		);
	}

	public static function overview( WP_REST_Request $r ) {
		return Analytics::overview( (int) $r['days'] );
	}

	public static function bots( WP_REST_Request $r ) {
		return array(
			'items'    => Analytics::bots( (int) $r['days'] ),
			'registry' => Registry::client_view(),
		);
	}

	public static function bot( WP_REST_Request $r ) {
		$d = Analytics::bot_detail( (string) $r['id'], (int) $r['days'] );
		return $d ? $d : new WP_Error( 'rfaib_not_found', __( 'Unknown crawler.', 'rankyfy-ai-crawlers' ), array( 'status' => 404 ) );
	}

	public static function pages( WP_REST_Request $r ) {
		$filter = in_array( $r['filter'], array( 'all', 'important', 'crawled', 'uncrawled', 'never', 'errors', 'blocked', 'dropped' ), true ) ? $r['filter'] : 'all';
		$sort   = in_array( $r['sort'], array( 'importance', 'hits', 'last', 'score', 'title' ), true ) ? $r['sort'] : 'importance';
		return Analytics::pages(
			array(
				'filter' => $filter,
				'sort'   => $sort,
				'bot'    => sanitize_key( (string) $r['bot'] ),
				'type'   => sanitize_key( (string) $r['type'] ),
				'search' => sanitize_text_field( (string) $r['search'] ),
				'page'   => (int) $r['page'],
				'days'   => (int) ( $r['days'] ?: 30 ),
			)
		);
	}

	public static function page( WP_REST_Request $r ) {
		$d = Analytics::page_detail( (int) $r['id'] );
		return $d ? $d : new WP_Error( 'rfaib_not_found', __( 'Page not found.', 'rankyfy-ai-crawlers' ), array( 'status' => 404 ) );
	}

	public static function pin( WP_REST_Request $r ) {
		Inventory::set_pin( (int) $r['id'], (int) $r['pin'] );
		Analytics::bust();
		return self::page( $r );
	}

	public static function analyze( WP_REST_Request $r ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'pages' ) . ' WHERE id = %d AND deleted = 0', (int) $r['id'] ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'rfaib_not_found', __( 'Page not found.', 'rankyfy-ai-crawlers' ), array( 'status' => 404 ) );
		}
		Analyzer::analyze( $row, true );
		Coverage::refresh_pages( array( (int) $row['id'] ) );
		Analytics::bust();
		return self::page( $r );
	}

	public static function ai_analyze( WP_REST_Request $r ) {
		$res = Rankyfy::analyze_page( (int) $r['id'] );
		if ( is_wp_error( $res ) ) {
			return self::scrub( $res );
		}
		Analytics::bust();
		return self::page( $r );
	}

	/** RankyFy errors reach the browser as code + plain message + status, nothing else. */
	private static function scrub( WP_Error $e ) {
		$data   = $e->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502;
		Log::warning( 'RankyFy request failed', array( 'code' => $e->get_error_code(), 'message' => $e->get_error_message() ) );
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => $status >= 400 ? $status : 502 ) );
	}

	public static function recommendations( WP_REST_Request $r ) {
		return Analytics::recommendations(
			array(
				'severity' => sanitize_key( (string) $r['severity'] ),
				'kind'     => sanitize_key( (string) $r['kind'] ),
				'group'    => sanitize_key( (string) $r['group'] ),
				'code'     => sanitize_key( (string) $r['code'] ),
				'page'     => max( 1, (int) $r['page'] ),
				'limit'    => 50,
			)
		);
	}

	public static function finding( WP_REST_Request $r ) {
		$ok = Findings::set_status( (int) $r['id'], sanitize_key( (string) $r['status'] ) );
		Analytics::bust();
		return array( 'ok' => $ok );
	}

	public static function opportunities() {
		return Analytics::opportunities();
	}

	public static function technical() {
		return Analytics::technical();
	}

	public static function technical_refresh() {
		Robots::refresh();
		Probe::run( 15 );
		Coverage::site();
		Analytics::bust();
		return Analytics::technical();
	}

	public static function history( WP_REST_Request $r ) {
		$days  = in_array( (int) $r['days'], array( 30, 90, 180, 365, 730 ), true ) ? (int) $r['days'] : 90;
		$group = in_array( $r['group'], array( 'day', 'week', 'month' ), true ) ? $r['group'] : ( $days > 120 ? 'week' : 'day' );
		return Analytics::history( $days, $group );
	}

	public static function alerts( WP_REST_Request $r ) {
		$status = in_array( $r['status'], array( 'unread', 'read', 'dismissed' ), true ) ? $r['status'] : '';
		return array(
			'items'  => Alerts::list( $status, 100 ),
			'unread' => Alerts::unread_count(),
		);
	}

	public static function alert( WP_REST_Request $r ) {
		$id = 'all' === $r['id'] ? 'all' : (int) $r['id'];
		Alerts::set_status( $id, sanitize_key( (string) $r['status'] ) );
		return array( 'unread' => Alerts::unread_count() );
	}

	public static function alert_test() {
		$a = array(
			'id'         => 0,
			'rule'       => 'test',
			'dedupe'     => 'test',
			'severity'   => 'info',
			'title'      => __( 'Test notification from AI Crawler Monitor', 'rankyfy-ai-crawlers' ),
			'what'       => __( 'You asked for a test notification.', 'rankyfy-ai-crawlers' ),
			'why'        => __( 'Real alerts look like this: what happened, why it matters, the pages involved and what to do.', 'rankyfy-ai-crawlers' ),
			'affected'   => wp_json_encode( array() ),
			'action'     => __( 'Nothing to do.', 'rankyfy-ai-crawlers' ),
			'link'       => '#/alerts',
			'status'     => 'read',
			'created_at' => time(),
		);
		$out = array( 'email' => null, 'webhook' => null );
		if ( 'off' !== Settings::get( 'notify_mode' ) ) {
			$out['email'] = Notifier::email( array( $a ), false );
		}
		if ( Settings::get( 'webhook_url' ) ) {
			$res            = Notifier::post( Settings::get( 'webhook_url' ), Alerts::present( $a ) );
			$out['webhook'] = is_wp_error( $res ) ? $res->get_error_message() : true;
		}
		return $out;
	}

	public static function registry() {
		return Registry::client_view() + array( 'custom' => Registry::custom(), 'sync' => Rankyfy::sync_status() );
	}

	public static function registry_sync() {
		$res = Rankyfy::sync_registry( true );
		Ranges::refresh_direct( 15 );
		Analytics::bust();
		return array( 'result' => $res ) + self::registry();
	}

	public static function registry_add( WP_REST_Request $r ) {
		$bot = array(
			'id'            => sanitize_key( (string) $r['id'] ),
			'name'          => sanitize_text_field( (string) $r['name'] ),
			'provider'      => sanitize_text_field( (string) $r['provider'] ),
			'category'      => sanitize_key( (string) $r['category'] ),
			'patterns'      => array_map( 'sanitize_text_field', (array) $r['patterns'] ),
			'robots_tokens' => array_map( 'sanitize_text_field', (array) $r['robots_tokens'] ),
			'description'   => sanitize_text_field( (string) $r['description'] ),
			'docs'          => esc_url_raw( (string) $r['docs'] ),
			'confidence'    => 'medium',
			'verify'        => array(),
		);
		$res = Registry::save_custom( $bot );
		Analytics::bust();
		return is_wp_error( $res ) ? $res : self::registry();
	}

	public static function registry_delete( WP_REST_Request $r ) {
		Registry::delete_custom( (string) $r['id'] );
		Analytics::bust();
		return self::registry();
	}

	public static function agents( WP_REST_Request $r ) {
		$state = in_array( $r['state'], array( 'new', 'ignored', 'tracked' ), true ) ? $r['state'] : 'new';
		return array( 'items' => Analytics::agents( $state ) );
	}

	public static function agent( WP_REST_Request $r ) {
		global $wpdb;
		$t    = Installer::table( 'agents' );
		$hash = (string) $r['hash'];
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE ua_hash = %s", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'rfaib_not_found', __( 'Unknown user agent.', 'rankyfy-ai-crawlers' ), array( 'status' => 404 ) );
		}
		if ( 'ignore' === $r['action'] ) {
			$wpdb->update( $t, array( 'state' => 'ignored' ), array( 'ua_hash' => $hash ) );
			return array( 'ok' => true );
		}
		if ( 'track' === $r['action'] ) {
			$token = sanitize_text_field( (string) $r['token'] );
			if ( '' === $token || false === stripos( $row['ua'], $token ) ) {
				return new WP_Error( 'rfaib_invalid_token', __( 'The text to look for must appear in the user agent.', 'rankyfy-ai-crawlers' ), array( 'status' => 400 ) );
			}
			$res = Registry::save_custom(
				array(
					'id'            => substr( sanitize_title( $token ), 0, 40 ),
					'name'          => $token,
					'provider'      => sanitize_text_field( (string) $r['provider'] ) ?: __( 'Unknown', 'rankyfy-ai-crawlers' ),
					'category'      => in_array( $r['category'], Registry::CATEGORIES, true ) ? $r['category'] : 'ai_other',
					'patterns'      => array( $token ),
					'robots_tokens' => preg_match( '/^[A-Za-z0-9._-]+$/', $token ) ? array( $token ) : array(),
					'description'   => __( 'Added from an unrecognised user agent.', 'rankyfy-ai-crawlers' ),
					'confidence'    => 'low',
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$wpdb->update( $t, array( 'state' => 'tracked' ), array( 'ua_hash' => $hash ) );
			Analytics::bust();
			return array( 'ok' => true, 'bot' => $res );
		}
		return new WP_Error( 'rfaib_invalid', __( 'Unknown action.', 'rankyfy-ai-crawlers' ), array( 'status' => 400 ) );
	}

	public static function import( WP_REST_Request $r ) {
		$lines = $r->get_param( 'lines' );
		if ( ! is_array( $lines ) || count( $lines ) > Importer::BATCH ) {
			/* translators: %d: max lines */
			return new WP_Error( 'rfaib_invalid', sprintf( __( 'Send at most %d lines per batch.', 'rankyfy-ai-crawlers' ), Importer::BATCH ), array( 'status' => 400 ) );
		}
		return Importer::batch( $lines, (string) $r->get_param( 'key' ) );
	}

	public static function get_settings() {
		return array(
			'settings' => Settings::client_view(),
			'registry' => array_values( array_map( static function ( $b ) {
				return array( 'id' => $b['id'], 'name' => $b['name'], 'category' => $b['category'], 'ai' => $b['ai'] );
			}, array_filter( Registry::bots(), static function ( $b ) {
				return ! $b['robots_only'];
			} ) ) ),
			'rankyfy'  => Rankyfy::state(),
		);
	}

	public static function save_settings( WP_REST_Request $r ) {
		$in = (array) $r->get_json_params();
		Settings::update( $in );
		if ( preg_grep( '/^(llms_|ai_txt_)/', array_keys( $in ) ) ) {
			Llms::stale();
		}
		Analytics::bust();
		return self::get_settings();
	}

	public static function log() {
		return array( 'items' => Log::entries() );
	}

	// ── AI Crawlers, AI Referrals ──────────────────────────────────────────

	public static function crawler_pages( WP_REST_Request $r ) {
		return Analytics::crawler_pages(
			array(
				'days'   => (int) $r['days'],
				'bot'    => preg_match( '/^[a-z0-9-]{2,40}$/', (string) $r['bot'] ) ? (string) $r['bot'] : '',
				'status' => in_array( $r['status'], array( '2xx', '3xx', '4xx', '5xx' ), true ) ? $r['status'] : '',
				'search' => substr( sanitize_text_field( (string) $r['search'] ), 0, 200 ),
				'page'   => max( 1, (int) $r['page'] ),
			)
		);
	}

	public static function crawler_url( WP_REST_Request $r ) {
		$d = Analytics::url_detail( (string) $r['hash'] );
		return $d ? $d : new WP_Error( 'rfaib_not_found', __( 'No AI crawler has requested this address.', 'rankyfy-ai-crawlers' ), array( 'status' => 404 ) );
	}

	public static function referrals( WP_REST_Request $r ) {
		return Analytics::referrals_view(
			array(
				'days'   => (int) $r['days'],
				'engine' => preg_match( '/^[a-z0-9_-]{2,20}$/', (string) $r['engine'] ) ? (string) $r['engine'] : '',
				'search' => substr( sanitize_text_field( (string) $r['search'] ), 0, 200 ),
				'page'   => max( 1, (int) $r['page'] ),
			)
		);
	}

	// ── Access Manager ─────────────────────────────────────────────────────

	public static function access() {
		return Access::view();
	}

	public static function access_save( WP_REST_Request $r ) {
		$rules = $r->get_param( 'rules' );
		if ( ! is_array( $rules ) ) {
			return new WP_Error( 'rfaib_invalid', __( 'Nothing to save.', 'rankyfy-ai-crawlers' ), array( 'status' => 400 ) );
		}
		Access::save( $rules );
		// Read robots.txt again as crawlers get it, then everything that depends on it.
		Robots::refresh();
		Coverage::site();
		Readiness::compute();
		Analytics::bust();
		return Access::view();
	}

	public static function compat() {
		return Compat::view();
	}

	// ── llms.txt override, first-hit email, redirects, storage ─────────────

	public static function llms_override( WP_REST_Request $r ) {
		Llms::set_override( (string) $r['text'] );
		Llms::probe();
		Analytics::bust();
		return Llms::status();
	}

	public static function first_hit( WP_REST_Request $r ) {
		if ( rest_sanitize_boolean( $r['on'] ) ) {
			update_option( Alerts::FIRST_HIT, (string) Settings::get( 'notify_email' ), false );
		} else {
			delete_option( Alerts::FIRST_HIT );
		}
		Analytics::bust();
		return array( 'on' => '' !== (string) get_option( Alerts::FIRST_HIT, '' ), 'email' => (string) Settings::get( 'notify_email' ) );
	}

	/** "Add redirect" for a URL AI crawlers keep getting errors on. */
	public static function redirect_add( WP_REST_Request $r ) {
		$post   = get_post( (int) $r['post_id'] );
		$source = Util::normalize_path( (string) $r['source'] );
		if ( ! $post || 'publish' !== $post->post_status || '/' === $source ) {
			return new WP_Error( 'rfaib_invalid', __( 'Choose a published page to send this address to.', 'rankyfy-ai-crawlers' ), array( 'status' => 400 ) );
		}
		if ( Util::normalize_path( (string) get_permalink( $post ) ) === $source ) {
			return new WP_Error( 'rfaib_invalid', __( 'That page already lives at this address.', 'rankyfy-ai-crawlers' ), array( 'status' => 400 ) );
		}
		Guard::add_redirect( $source, $post->ID, 'manual' );
		Analytics::bust();
		return array( 'ok' => true ) + Guard::redirects( 1 );
	}

	public static function prune() {
		Aggregator::prune();
		Analytics::bust();
		return self::status();
	}

	// ── AI readiness ───────────────────────────────────────────────────────

	public static function readiness() {
		$out          = Readiness::get();
		$out['trend'] = array();
		foreach ( Analytics::snapshots( 180 ) as $s ) {
			if ( isset( $s['readiness'] ) && null !== $s['readiness'] ) {
				$out['trend'][] = array( 'day' => $s['day'], 'value' => $s['readiness'] );
			}
		}
		return $out;
	}

	public static function readiness_run() {
		Coverage::site();
		Readiness::compute();
		Analytics::bust();
		return self::readiness();
	}

	public static function readiness_status( WP_REST_Request $r ) {
		if ( ! Readiness::set_status( (string) $r['check'], sanitize_key( (string) $r['status'] ) ) ) {
			return new WP_Error( 'rfaib_invalid', __( 'Unknown check or status.', 'rankyfy-ai-crawlers' ), array( 'status' => 400 ) );
		}
		Readiness::compute();
		Analytics::bust();
		return self::readiness();
	}

	// ── llms.txt / ai.txt ──────────────────────────────────────────────────

	public static function ai_files() {
		return Llms::status();
	}

	public static function ai_files_check() {
		Llms::stale();
		Llms::rebuild();
		Llms::probe();
		Readiness::compute();
		Analytics::bust();
		return Llms::status();
	}

	// ── publish guard ──────────────────────────────────────────────────────

	public static function redirects( WP_REST_Request $r ) {
		return Guard::redirects( max( 1, (int) $r['page'] ) );
	}

	public static function redirect_delete( WP_REST_Request $r ) {
		return array( 'ok' => Guard::delete_redirect( (int) $r['id'] ) ) + Guard::redirects( 1 );
	}

	/** "Create 301 redirect" / "Change anyway" from the editor, applied on the next save. */
	public static function guard_choice( WP_REST_Request $r ) {
		Guard::set_choice( (int) $r['id'], (string) $r['choice'] );
		return array( 'choice' => (string) get_transient( Guard::CHOICE . (int) $r['id'] ) );
	}

	public static function guard( WP_REST_Request $r ) {
		$post = get_post( (int) $r['id'] );
		if ( ! $post ) {
			return new WP_Error( 'rfaib_not_found', __( 'Post not found.', 'rankyfy-ai-crawlers' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $post->post_type, Inventory::post_types(), true ) ) {
			return new WP_Error( 'rfaib_invalid', __( 'This content type is not public, so it is not checked.', 'rankyfy-ai-crawlers' ), array( 'status' => 400 ) );
		}
		$edits = array();
		foreach ( array( 'title', 'content', 'slug', 'parent' ) as $k ) {
			if ( $r->has_param( $k ) ) {
				$edits[ $k ] = $r[ $k ];
			}
		}
		return Guard::check( $post, $edits );
	}
}
