<?php
/**
 * Scale benchmark: N synthetic crawler requests over P pages and 30 days
 * (in time order, as live tracking records them),
 * then aggregation time and dashboard query times.
 *
 *   RFAIB_ALLOW_DESTRUCTIVE_TESTS=1 wp eval-file tools/bench/scale.php 1000000 5000
 */
if ( '1' !== getenv( 'RFAIB_ALLOW_DESTRUCTIVE_TESTS' ) ) {
	exit( "Refusing to run without RFAIB_ALLOW_DESTRUCTIVE_TESTS=1\n" );
}
use RankyfyAIB as A;
global $wpdb;
$n     = (int) ( $args[0] ?? 1000000 );
$pages = (int) ( $args[1] ?? 5000 );
$e     = A\Installer::table( 'events' );
$p     = A\Installer::table( 'pages' );
$reuse = 'reuse' === ( $args[2] ?? '' ); // keep generated events, rebuild everything else
update_option( 'rfaib_worker_lock', time(), false );
foreach ( A\Installer::TABLES as $t ) {
	if ( ! $reuse || ! in_array( $t, array( 'events', 'pages' ), true ) ) {
		$wpdb->query( 'TRUNCATE ' . A\Installer::table( $t ) );
	}
}
update_option( 'rfaib_agg_watermark', '0', false );
$wpdb->query( 'SET SESSION max_recursive_iterations = 100000000' );
$t0 = microtime( true );
if ( ! $reuse ) :
$wpdb->query( "INSERT INTO {$p} (object_type, object_id, subtype, url_hash, path, title, importance, word_count, aeo_score, aeo, analyzed_at, dirty)
	WITH RECURSIVE s(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM s WHERE i < {$pages})
	SELECT 'post', i, 'post', MD5(CONCAT('/page-', i)), CONCAT('/page-', i), CONCAT('Page ', i), IF(i % 10 = 0, 60, 10), 800, 40 + i % 50, '{\"access\":30,\"discovery\":10,\"structure\":10,\"depth\":9,\"trust\":8,\"linking\":4}', UNIX_TIMESTAMP(), 0 FROM s" );
$bots = array( 'gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'perplexitybot', 'perplexity-user', 'meta-externalagent', 'bytespider', 'ccbot', 'amazonbot' );
$case = 'ELT(1 + FLOOR(RAND(i) * 10), ' . implode( ',', array_map( function ( $b ) { return "'$b'"; }, $bots ) ) . ')';
$now  = time();
$batch = 200000;
for ( $off = 0; $off < $n; $off += $batch ) {
	$hi = min( $n, $off + $batch );
	$wpdb->query( "INSERT INTO {$e} (ts, day, bot, cls, vstate, method, path, url_hash, status, ms, ip_net, ip_hash, ua, source, dedup)
		SELECT x.ts, FROM_UNIXTIME(x.ts, '%Y-%m-%d'), x.bot, IF(x.i % 20 = 0, 'spoofed', 'ai'), IF(x.i % 20 = 0, 'failed', 'verified'), 'GET', CONCAT('/page-', x.pg), MD5(CONCAT('/page-', x.pg)),
		       IF(x.i % 97 = 0, 503, IF(x.i % 31 = 0, 404, 200)), 50 + x.i % 900, '192.0.2.0/24', LEFT(MD5(x.i % 3000), 16), x.bot, 'live', MD5(CONCAT('b', x.i))
		FROM (WITH RECURSIVE s(i) AS (SELECT {$off} + 1 UNION ALL SELECT i + 1 FROM s WHERE i < {$hi})
		      SELECT i, {$now} - 30 * 86400 + FLOOR(i * 30 * 86400 / {$n}) + FLOOR(RAND(i * 7) * 20) ts, {$case} bot, 1 + FLOOR(POW(RAND(i * 13), 2) * {$pages}) pg FROM s) x" );
}
printf( "generated %s events over %s pages in %.1fs (%s)\n", number_format( $n ), number_format( $pages ), microtime( true ) - $t0, $wpdb->last_error ?: 'ok' );
endif;
$t0 = microtime( true );
$done = 0;
do {
	$k = A\Aggregator::run( 600 );
	$done += $k;
} while ( $k > 0 );
$agg = microtime( true ) - $t0;
printf( "aggregated %s events in %.1fs (%s events/s)\n", number_format( $done ), $agg, number_format( $done / max( 0.001, $agg ) ) );
foreach ( array( 'daily', 'daily_bots', 'page_bots', 'sessions' ) as $t ) {
	printf( "  %-10s %s rows\n", $t, number_format( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . A\Installer::table( $t ) ) ) );
}
$time = function ( $label, $fn ) {
	A\Analytics::bust();
	$t0 = microtime( true );
	$fn();
	printf( "  %-36s %7.0f ms\n", $label, ( microtime( true ) - $t0 ) * 1000 );
};
echo "dashboard (uncached):\n";
$time( 'overview 30 days', function () { A\Analytics::overview( 30 ); } );
$time( 'overview 90 days', function () { A\Analytics::overview( 90 ); } );
$time( 'crawlers 30 days', function () { A\Analytics::bots( 30 ); } );
$time( 'crawler detail (GPTBot)', function () { A\Analytics::bot_detail( 'gptbot', 30 ); } );
$time( 'pages: important, page 1', function () { A\Analytics::pages( array( 'filter' => 'important' ) ); } );
$time( 'pages: never crawled', function () { A\Analytics::pages( array( 'filter' => 'never' ) ); } );
$time( 'pages: most crawled', function () { A\Analytics::pages( array( 'filter' => 'all', 'sort' => 'hits' ) ); } );
$time( 'page detail', function () { A\Analytics::page_detail( 1 ); } );
$time( 'history 365 days, weekly', function () { A\Analytics::history( 365, 'week' ); } );
$time( 'recommendations', function () { A\Analytics::recommendations( array() ); } );
$time( 'retention prune', function () { A\Aggregator::prune(); } );
$t0 = microtime( true );
A\Analytics::overview( 30 );
printf( "  %-36s %7.1f ms\n", 'overview 30 days (cached)', ( microtime( true ) - $t0 ) * 1000 );
$sz = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME n, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s', DB_NAME, $wpdb->prefix . 'rfaib_%' ), ARRAY_A );
echo 'table sizes (MB): ', implode( ', ', array_map( function ( $r ) { return substr( $r['n'], strlen( $GLOBALS['wpdb']->prefix . 'rfaib_' ) ) . ' ' . $r['mb']; }, $sz ) ), "\n";
delete_option( 'rfaib_worker_lock' );
