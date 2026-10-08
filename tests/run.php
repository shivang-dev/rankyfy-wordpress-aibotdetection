<?php
/**
 * Test runner. Runs inside a disposable WordPress with the plugin active:
 *
 *   RFY_ALLOW_DESTRUCTIVE_TESTS=1 wp eval-file wp-content/plugins/aibotdetection/tests/run.php [filter]
 *
 * It truncates the plugin's tables between tests — never point it at a real site.
 *
 * @package RankyfyAIB
 */

if ( '1' !== getenv( 'RFY_ALLOW_DESTRUCTIVE_TESTS' ) ) {
	fwrite( STDERR, "Refusing to run: set RFY_ALLOW_DESTRUCTIVE_TESTS=1 (this wipes the plugin's data).\n" );
	exit( 1 );
}

class RFY_Assert_Failed extends Exception {}

function t_ok( $cond, $msg = 'assertion failed' ) {
	if ( ! $cond ) {
		throw new RFY_Assert_Failed( $msg );
	}
}
function t_eq( $expected, $actual, $msg = '' ) {
	if ( $expected !== $actual ) {
		throw new RFY_Assert_Failed( ( $msg ? $msg . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

/** Empty every plugin table and reset state the tests depend on. */
function t_reset() {
	global $wpdb;
	foreach ( RankyfyAIB\Installer::TABLES as $t ) {
		$wpdb->query( 'TRUNCATE ' . RankyfyAIB\Installer::table( $t ) ); // phpcs:ignore
	}
	update_option( 'rfy_agg_watermark', '0', false );
	update_option( RankyfyAIB\Tracker::THROTTLE, array(), true );
	// Hold the worker lock so the site's own WP-Cron run cannot work on the
	// tables while a test truncates and fills them.
	update_option( 'rfy_worker_lock', time(), false );
	delete_option( RankyfyAIB\Alerts::BASELINE );
	update_option( RankyfyAIB\Settings::OPTION, array(), true );
	RankyfyAIB\Ranges::purge_all();
	delete_option( RankyfyAIB\Registry::CUSTOM );
	delete_option( RankyfyAIB\Registry::REMOTE );
	RankyfyAIB\Registry::flush();
	RankyfyAIB\Verifier::flush_cache();
	RankyfyAIB\Settings::flush();
	RankyfyAIB\Registry::compile();
	wp_cache_flush();
}

/** A request context as the tracker builds it. */
function t_ctx( $path, $ts = null, $status = 200, $ms = 120 ) {
	$p = RankyfyAIB\Util::normalize_path( $path );
	return array( 'path' => $p, 'hash' => RankyfyAIB\Util::url_hash( $p ), 'kind' => RankyfyAIB\Util::path_kind( $p ), 'status' => $status, 'ms' => $ms, 'otype' => '', 'oid' => 0, 'method' => 'GET', 'ts' => $ts ? $ts : time() );
}

/** Classify and store one request the way the tracker does. */
function t_hit( $ua, $ip, $path, $ts = null, $status = 200 ) {
	$r       = RankyfyAIB\Detector::match( $ua, RankyfyAIB\Registry::matcher(), $ip, '' );
	$r['ua'] = $ua;
	return RankyfyAIB\Tracker::store( $r, t_ctx( $path, $ts, $status ), $ip, 'live' );
}

/** Give a bot a fresh published range (test double for the operator's file). */
function t_ranges( $bot, array $cidrs, $age = 0 ) {
	$b = RankyfyAIB\Registry::get( $bot );
	foreach ( $b['verify']['ranges'] as $u ) {
		RankyfyAIB\Ranges::store( $u, $cidrs, time() - $age, 'test' );
	}
}

// The path-based tests (robots rules, llms.txt) need pretty permalinks.
if ( ! get_option( 'permalink_structure' ) ) {
	update_option( 'permalink_structure', '/%postname%/' );
	$GLOBALS['wp_rewrite']->init();
	flush_rewrite_rules( false );
}

$filter = $args[0] ?? '';
$files  = glob( __DIR__ . '/test-*.php' );
sort( $files );
foreach ( $files as $f ) {
	require_once $f;
}
$pass  = 0;
$fail  = 0;
$start = microtime( true );
foreach ( get_defined_functions()['user'] as $fn ) {
	if ( 0 !== strpos( $fn, 'test_' ) || ( $filter && false === strpos( $fn, $filter ) ) ) {
		continue;
	}
	t_reset();
	try {
		$t0 = microtime( true );
		$fn();
		$pass++;
		printf( "  ok   %-60s %6.0f ms\n", $fn, ( microtime( true ) - $t0 ) * 1000 );
	} catch ( Throwable $e ) {
		$fail++;
		printf( "  FAIL %s\n       %s (%s:%d)\n", $fn, $e->getMessage(), basename( $e->getFile() ), $e->getLine() );
	}
}
printf( "\n%d passed, %d failed in %.1fs\n", $pass, $fail, microtime( true ) - $start );
t_reset();
delete_option( 'rfy_worker_lock' );
if ( $fail ) {
	exit( 1 );
}
