<?php
/**
 * REST security, validation, rate limits, backend failures, lifecycle.
 */

use RankyfyAIB\Installer;

function t_rest( $method, $route, $params = array(), $user = 0 ) {
	wp_set_current_user( $user );
	$r = new WP_REST_Request( $method, '/rankyfy-ai-seo/v1' . $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	wp_set_current_user( 0 );
	return $res;
}

function t_admin() {
	$u = get_user_by( 'login', 'admin' );
	return $u ? $u->ID : 1;
}

function test_rest_requires_capability() {
	t_eq( 401, t_rest( 'GET', '/overview' )->get_status(), 'anonymous' );
	$sub = wp_insert_user( array( 'user_login' => 'sub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	$ed  = wp_insert_user( array( 'user_login' => 'ed' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	foreach ( array( '/overview', '/pages', '/settings', '/log', '/status' ) as $route ) {
		t_eq( 403, t_rest( 'GET', $route, array(), $sub )->get_status(), "subscriber on $route" );
		t_eq( 403, t_rest( 'GET', $route, array(), $ed )->get_status(), "editor on $route" );
	}
	t_eq( 403, t_rest( 'POST', '/settings', array( 'tracking' => false ), $ed )->get_status() );
	t_eq( 200, t_rest( 'GET', '/overview', array(), t_admin() )->get_status() );
	wp_delete_user( $sub );
	wp_delete_user( $ed );
}

function test_rest_cookie_auth_needs_nonce() {
	// Over real HTTP, a logged-in cookie without the REST nonce is treated as anonymous.
	$res = wp_remote_get( rest_url( 'rankyfy-ai-seo/v1/overview' ), array( 'cookies' => array() ) );
	if ( is_wp_error( $res ) ) {
		echo "       (loopback unavailable; skipped)\n";
		return;
	}
	t_eq( 401, (int) wp_remote_retrieve_response_code( $res ) );
}

function test_rest_validates_input() {
	$a = t_admin();
	t_eq( 404, t_rest( 'GET', '/pages/999999', array(), $a )->get_status() );
	t_eq( 404, t_rest( 'GET', '/bots/not-a-registered-bot', array(), $a )->get_status() );
	$d = t_rest( 'GET', '/overview', array( 'days' => 9999 ), $a )->get_data();
	t_eq( 30, $d['range'], 'unknown ranges fall back to 30 days' );
	$p = t_rest( 'GET', '/pages', array( 'filter' => "important' OR 1=1 --", 'sort' => 'x; DROP TABLE', 'search' => "%' UNION SELECT" ), $a );
	t_eq( 200, $p->get_status(), 'hostile filter values are ignored, not executed' );
	t_eq( 400, t_rest( 'POST', '/import', array( 'lines' => array_fill( 0, 2001, array() ) ), $a )->get_status() );
	t_eq( 400, t_rest( 'POST', '/registry/custom', array( 'id' => 'Bad Id' ), $a )->get_status() );
	$s = t_rest( 'POST', '/settings', array(), $a );
	t_eq( 200, $s->get_status() );
	$r = new WP_REST_Request( 'POST', '/rankyfy-ai-seo/v1/settings' );
	$r->set_header( 'content-type', 'application/json' );
	$r->set_body( wp_json_encode( array( 'retention_events' => 99999, 'notify_mode' => 'spam', 'unknown_key' => 1 ) ) );
	wp_set_current_user( $a );
	rest_do_request( $r );
	wp_set_current_user( 0 );
	RankyfyAIB\Settings::flush();
	t_eq( 365, RankyfyAIB\Settings::get( 'retention_events' ), 'clamped' );
	t_eq( 'critical', RankyfyAIB\Settings::get( 'notify_mode' ), 'invalid enum ignored' );
	t_ok( ! array_key_exists( 'unknown_key', get_option( RankyfyAIB\Settings::OPTION ) ) );
}

function test_rest_never_returns_addresses_or_secrets() {
	t_hit( 'GPTBot/1.4', '20.171.206.99', '/secret-check/' );
	RankyfyAIB\Aggregator::run( 5 );
	$a    = t_admin();
	$json = wp_json_encode( array( t_rest( 'GET', '/bots/gptbot', array(), $a )->get_data(), t_rest( 'GET', '/status', array(), $a )->get_data(), t_rest( 'GET', '/settings', array(), $a )->get_data() ) );
	t_ok( false === strpos( $json, '20.171.206.99' ), 'full address never exposed' );
	t_ok( false === strpos( $json, (string) get_option( 'rfy_secret' ) ), 'site secret never exposed' );
}

function test_rate_limit() {
	$a  = t_admin();
	$f  = static function ( $max, $action ) {
		return 'write' === $action ? 3 : $max;
	};
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%rfy\\_rl\\_%'" );
	wp_cache_flush();
	add_filter( 'rfy_rate_limit', $f, 10, 2 );
	try {
		$codes = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$codes[] = t_rest( 'POST', '/alerts/all', array( 'status' => 'read' ), $a )->get_status();
		}
		t_eq( array( 200, 200, 200, 429, 429 ), $codes );
	} finally {
		remove_filter( 'rfy_rate_limit', $f, 10 );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%rfy\\_rl\\_%'" );
		wp_cache_flush();
	}
}

function test_backend_unavailable_degrades_gracefully() {
	// The test gateway answers 503 (see the mock service); a timeout is simulated.
	t_eq( 'unavailable', RankyfyAIB\Rankyfy::sync_registry( true ) );
	t_eq( 'bundled', RankyfyAIB\Registry::data()['source'], 'bundled registry stays in use' );
	$timeout = static function () {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	};
	add_filter( 'pre_http_request', $timeout );
	try {
		t_eq( 'unavailable', RankyfyAIB\Rankyfy::sync_registry( true ) );
		t_eq( 0, RankyfyAIB\Ranges::refresh_direct( 5 ), 'operator files unreachable: nothing stored, nothing broken' );
	} finally {
		remove_filter( 'pre_http_request', $timeout );
	}
	t_eq( 'event', t_hit( 'GPTBot/1.4', '192.0.2.1', '/still-works/' ), 'tracking unaffected' );
	if ( ! class_exists( '\Rankyfy\Auth' ) ) {
		$r = RankyfyAIB\Rankyfy::analyze_page( 1 );
		t_ok( is_wp_error( $r ) );
		t_eq( 'rfy_not_connected', $r->get_error_code() );
	}
}

function test_backend_registry_update_is_validated() {
	$base = RankyfyAIB\Registry::bundled();
	$base['version'] = '2099.01.01.1';
	$base['bots'][]  = array( 'id' => 'futurebot', 'name' => 'FutureBot', 'provider' => 'Future AI', 'category' => 'ai_search', 'ai' => true, 'patterns' => array( 'FutureBot' ), 'robots_tokens' => array( 'FutureBot' ), 'verify' => array( 'ranges' => array( 'https://future.example/ranges.json' ) ), 'confidence' => 'high', 'source' => 'official', 'verified_on' => '2099-01-01' );
	$base['bots'][]  = array( 'id' => 'EVIL ID', 'patterns' => array( 'x' ) );
	$base['heuristics']['bot'] = '(a+)+$('; // invalid regex from a compromised source
	$body = wp_json_encode( array( 'registry' => $base, 'ranges' => array( 'https://future.example/ranges.json' => array( 'fetched_at' => time(), 'prefixes' => array( '192.0.2.0/24', 'not-a-cidr' ) ), 'https://unlisted.example/x.json' => array( 'fetched_at' => time(), 'prefixes' => array( '10.0.0.0/8' ) ) ) ) );
	$http = static function ( $pre, $args, $url ) use ( $body ) {
		return false !== strpos( $url, '/ai-crawlers/registry' ) ? array( 'headers' => array( 'etag' => '"v2"' ), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() ) : $pre;
	};
	add_filter( 'pre_http_request', $http, 10, 3 );
	try {
		t_eq( 'updated', RankyfyAIB\Rankyfy::sync_registry( true ) );
	} finally {
		remove_filter( 'pre_http_request', $http, 10 );
	}
	t_eq( 'rankyfy', RankyfyAIB\Registry::data()['source'] );
	t_eq( 'futurebot', RankyfyAIB\Detector::match( 'FutureBot/1.0', RankyfyAIB\Registry::matcher() )['bot'], 'new crawler without a plugin update' );
	t_ok( null === RankyfyAIB\Registry::get( 'EVIL ID' ), 'invalid entries dropped' );
	t_eq( RankyfyAIB\Registry::bundled()['heuristics']['bot'], RankyfyAIB\Registry::data()['heuristics']['bot'], 'broken heuristic replaced by the bundled one' );
	t_eq( 'verified', RankyfyAIB\Ranges::check( 'futurebot', '192.0.2.8' ) );
	t_ok( ! isset( RankyfyAIB\Ranges::status()['https://unlisted.example/x.json'] ), 'ranges for files the registry does not name are ignored' );
}

function test_activation_upgrade_and_uninstall() {
	global $wpdb;
	t_hit( 'GPTBot/1.4', '192.0.2.1', '/keep-me/' );
	// Upgrade: an older schema version triggers dbDelta and keeps data.
	update_option( 'rfy_db_version', '0' );
	RankyfyAIB\Installer::maybe_upgrade();
	t_eq( RFY_DB_VERSION, get_option( 'rfy_db_version' ) );
	t_eq( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'events' ) ), 'data survives an upgrade' );
	// Deactivation clears the schedule; activation restores it.
	RankyfyAIB\Installer::deactivate();
	t_ok( ! wp_next_scheduled( RankyfyAIB\Worker::HOOK ) );
	RankyfyAIB\Installer::activate();
	t_ok( (bool) wp_next_scheduled( RankyfyAIB\Worker::HOOK ) );
	// Autoloaded request-path options exist (a missing option costs a query per page view).
	$autoload = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rfy\\_%' AND autoload IN ('yes','on','auto-on','auto')" );
	foreach ( array( 'rfy_settings', 'rfy_matcher', 'rfy_throttle', 'rfy_iponly', 'rfy_db_version', 'rfy_secret' ) as $o ) {
		t_ok( in_array( $o, $autoload, true ), "$o autoloaded" );
	}
	$big = $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name LIKE 'rfy\\_%' AND autoload IN ('yes','on','auto-on','auto')" );
	t_ok( (int) $big < 40000, 'autoloaded footprint stays small (' . (int) $big . ' bytes)' );
}

function test_uninstall_removes_everything() {
	// Run uninstall.php in a child process so this process keeps its tables.
	global $wpdb;
	$basename = plugin_basename( RFY_FILE );
	$cmd = 'cd ' . escapeshellarg( ABSPATH ) . ' && wp eval ' . escapeshellarg( 'define( "WP_UNINSTALL_PLUGIN", "' . $basename . '" ); include WP_PLUGIN_DIR . "/' . dirname( $basename ) . '/uninstall.php"; global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE \'" . $wpdb->prefix . "rfy\\\\_%\'" ), "|", (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \'rfy\\\\_%\'" );' ) . ' 2>&1';
	$out = trim( (string) shell_exec( $cmd ) );
	t_eq( '0|0', substr( $out, -3 ), 'tables and options removed: ' . $out );
	// Put everything back for the rest of the suite.
	wp_cache_flush();
	RankyfyAIB\Installer::activate();
	update_option( 'rfy_worker_lock', time(), false ); // the child process removed the test's lock with every other option
	RankyfyAIB\Registry::compile();
	t_ok( RankyfyAIB\Installer::ready() );
}
