<?php
/**
 * Recording, rollups, sessions, retention, throttling, log import, privacy.
 */

use RankyfyAIB\Aggregator;
use RankyfyAIB\Installer;
use RankyfyAIB\Util;

const T_GPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot';

function t_count( $table, $where = '1=1' ) {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( $table ) . ' WHERE ' . $where ); // phpcs:ignore
}
function t_sum( $table, $col, $where = '1=1' ) {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COALESCE(SUM({$col}),0) FROM " . Installer::table( $table ) . ' WHERE ' . $where ); // phpcs:ignore
}

function test_store_paths_per_class() {
	t_ranges( 'gptbot', array( '20.171.206.0/24' ) );
	t_eq( 'event', t_hit( T_GPT, '20.171.206.9', '/a/' ) );
	t_eq( 'event', t_hit( T_GPT, '198.51.100.7', '/a/' ), 'impersonations are recorded (for the security view)' );
	t_eq( 'counted', t_hit( 'Mozilla/5.0 (compatible; AhrefsBot/7.0)', '198.51.100.8', '/a/' ), 'SEO bots: counter only' );
	t_eq( 'counted', t_hit( 'Mozilla/5.0 (compatible; Googlebot/2.1)', '66.249.64.1', '/a/' ), 'search engines: counter only by default' );
	t_eq( 'counted', t_hit( 'python-requests/2.32', '198.51.100.9', '/a/' ), 'unknown bots: counter only' );
	t_eq( 'event', t_hit( 'SuperNewAIBot/0.1', '198.51.100.10', '/a/' ), 'possible AI crawlers are recorded' );
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT bot, cls, vstate, ip_net, ip_hash FROM ' . Installer::table( 'events' ) . ' ORDER BY id', ARRAY_A );
	t_eq( array( 'gptbot', 'ai', 'verified' ), array( $rows[0]['bot'], $rows[0]['cls'], $rows[0]['vstate'] ) );
	t_eq( array( 'gptbot', 'spoofed', 'failed' ), array( $rows[1]['bot'], $rows[1]['cls'], $rows[1]['vstate'] ) );
	t_eq( '20.171.206.0/24', $rows[0]['ip_net'], 'only the network is stored' );
	t_eq( 16, strlen( $rows[0]['ip_hash'] ) );
	t_eq( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Installer::table( 'events' ) . " WHERE ip_net LIKE '%.9' OR ua LIKE '%198.51%'" ) ); // phpcs:ignore
	t_eq( 2, t_count( 'agents' ), 'unrecognised user agents remembered for discovery' );
}

function test_ip_storage_none() {
	RankyfyAIB\Settings::update( array( 'ip_storage' => 'none' ) );
	t_eq( 'event', t_hit( T_GPT, '20.171.206.9', '/a/' ) );
	// (wpdb::get_var() turns '' into null, so count instead.)
	t_eq( 1, t_count( 'events', "path = '/a' AND ip_net = ''" ), 'no address information stored' );
}

function test_log_injection_is_neutralised() {
	$ua = "GPTBot/1.4\r\n[error] forged log line\x00\x1b[31m";
	t_hit( $ua, '20.171.206.9', "/a/\r\nInjected: header" );
	global $wpdb;
	$row = $wpdb->get_row( 'SELECT ua, path FROM ' . Installer::table( 'events' ), ARRAY_A );
	t_ok( ! preg_match( '/[\x00-\x1F\x7F]/', $row['ua'] . $row['path'] ), 'no control characters stored' );
	t_eq( "'=HYPERLINK(1)", Util::csv_cell( '=HYPERLINK(1)' ), 'CSV formula injection neutralised' );
	t_eq( 'normal', Util::csv_cell( 'normal' ) );
	t_ok( strlen( Util::clean( str_repeat( 'é', 400 ), 255 ) ) <= 255 && preg_match( '//u', Util::clean( str_repeat( 'é', 400 ), 255 ) ), 'bounded and still valid UTF-8' );
}

function test_path_normalisation() {
	t_eq( '/', Util::normalize_path( '/' ) );
	t_eq( '/blog/post', Util::normalize_path( '/blog/post/?utm_source=x#frag' ) );
	t_eq( '/blog/post', Util::normalize_path( '//blog///post//' ) );
	t_eq( Util::url_hash( Util::normalize_path( '/caf%C3%A9/' ) ), Util::url_hash( Util::normalize_path( '/café' ) ), 'encoded and raw forms are the same page' );
	t_eq( 'robots', Util::path_kind( '/robots.txt' ) );
	t_eq( 'sitemap', Util::path_kind( '/wp-sitemap-posts-post-1.xml' ) );
	t_eq( 'llms', Util::path_kind( '/llms.txt' ) );
}

function test_duplicate_requests_are_ignored() {
	$ts = time() - 100;
	t_eq( 'event', t_hit( T_GPT, '20.171.206.9', '/a/', $ts ) );
	t_eq( 'skipped', t_hit( T_GPT, '20.171.206.9', '/a/', $ts ), 'same request seen again (live + imported log)' );
	t_eq( 'event', t_hit( 'SuperNewAIBot/0.1', '20.171.206.9', '/a/', $ts ), 'different client in the same second is a different request' );
}

function test_aggregation_is_exact_and_idempotent() {
	t_ranges( 'gptbot', array( '20.171.206.0/24' ) );
	$day = time() - 2 * DAY_IN_SECONDS;
	for ( $i = 0; $i < 30; $i++ ) {
		t_hit( T_GPT, '20.171.206.' . ( $i % 5 + 1 ), '/p' . ( $i % 3 ) . '/', $day + $i * 60, 0 === $i % 10 ? 503 : 200 );
	}
	for ( $i = 0; $i < 4; $i++ ) {
		t_hit( T_GPT, '198.51.100.' . ( $i + 1 ), '/p0/', $day + $i );
	}
	t_hit( 'Mozilla/5.0 (compatible; ClaudeBot/1.0)', '192.0.2.1', '/p1/', $day + 5000 );
	t_eq( 35, Aggregator::run( 10 ) );
	t_ok( isset( Aggregator::$new_bots['gptbot'], Aggregator::$new_bots['claudebot'] ), 'both crawlers reported as new' );
	t_eq( 0, Aggregator::run( 10 ), 'nothing folded twice' );
	t_eq( 31, t_sum( 'daily', 'hits' ), 'genuine requests only' );
	t_eq( 3, t_sum( 'daily', 'errors' ) );
	t_eq( 31, t_sum( 'daily_bots', 'hits' ) );
	t_eq( 4, t_sum( 'daily_bots', 'spoofed' ) );
	t_eq( 30, t_sum( 'daily_bots', 'verified' ) );
	t_eq( 4, t_count( 'page_bots' ), '3 pages x GPTBot + 1 page x ClaudeBot' );
	t_eq( 2, t_count( 'bots_seen' ) );
	t_eq( 4, t_sum( 'bots_seen', 'spoofed' ) );
	// 30 GPTBot requests one minute apart = one session; ClaudeBot alone = another.
	t_eq( 2, t_count( 'sessions' ) );
	global $wpdb;
	$s = $wpdb->get_row( 'SELECT * FROM ' . Installer::table( 'sessions' ) . " WHERE bot = 'gptbot'", ARRAY_A );
	t_eq( array( 30, 3, 5, 3 ), array( (int) $s['hits'], (int) $s['pages'], (int) $s['ips'], (int) $s['errors'] ) );
	t_eq( 29 * 60, (int) $s['ended'] - (int) $s['started'] );
}

function test_new_bots_and_first_crawls_are_detected_once() {
	t_hit( T_GPT, '192.0.2.1', '/x/' );
	Aggregator::run( 10 );
	t_ok( isset( Aggregator::$new_bots['gptbot'] ) );
	t_eq( 1, count( Aggregator::$first_crawls ) );
	t_hit( T_GPT, '192.0.2.1', '/x/', time() + 1 );
	t_hit( T_GPT, '192.0.2.1', '/y/', time() + 2 );
	Aggregator::run( 10 );
	t_eq( array(), Aggregator::$new_bots, 'already seen' );
	t_eq( array( '/y' ), array_column( Aggregator::$first_crawls, 'path' ), 'only the page it had not crawled before' );
}

function test_pending_verification_holds_the_watermark() {
	t_ranges( 'googlebot', array( '66.249.64.0/27' ) );
	RankyfyAIB\Settings::update( array( 'track_search_pages' => true ) );
	t_hit( 'Mozilla/5.0 (compatible; Googlebot/2.1)', '66.249.90.1', '/a/' ); // pending rDNS
	t_hit( T_GPT, '192.0.2.1', '/b/' );
	t_eq( 0, Aggregator::run( 5 ), 'waits for the verdict' );
	global $wpdb;
	$wpdb->query( 'UPDATE ' . Installer::table( 'events' ) . " SET vstate = 'verified'" );
	t_eq( 2, Aggregator::run( 5 ) );
}

function test_concurrent_aggregation_counts_once() {
	for ( $i = 0; $i < 400; $i++ ) {
		t_hit( T_GPT, '192.0.2.' . ( $i % 200 + 1 ), '/c' . ( $i % 40 ) . '/', time() - $i * 30 );
	}
	// Two worker processes at once (as when WP-Cron and a manual run overlap).
	$cmd = 'cd ' . escapeshellarg( ABSPATH ) . ' && wp eval ' . escapeshellarg( 'RankyfyAIB\Aggregator::run( 20 );' ) . ' --skip-plugins=rankyfy-seo 2>&1';
	$a   = popen( $cmd, 'r' );
	$b   = popen( $cmd, 'r' );
	stream_get_contents( $a );
	stream_get_contents( $b );
	pclose( $a );
	pclose( $b );
	Aggregator::run( 5 );
	t_eq( 400, t_sum( 'daily', 'hits' ), 'each request counted exactly once' );
	t_eq( 400, t_sum( 'daily_bots', 'hits' ) );
}

function test_throttle_flooding_unverified_clients() {
	global $wpdb;
	$now = time();
	$vals = array();
	for ( $i = 0; $i < 650; $i++ ) {
		$vals[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s, %s, %s, %s)', $now - $i % 500, Util::day(), 'gptbot', 'spoofed', 'failed', '/f' . $i, md5( '/f' . $i ), 'flooderhash00001', md5( 'f' . $i ) );
	}
	$wpdb->query( 'INSERT INTO ' . Installer::table( 'events' ) . ' (ts, day, bot, cls, vstate, path, url_hash, ip_hash, dedup) VALUES ' . implode( ',', $vals ) ); // phpcs:ignore
	Aggregator::throttle();
	$t = get_option( RankyfyAIB\Tracker::THROTTLE );
	t_ok( isset( $t['ips']['flooderhash00001'] ) );
	// A throttled address only reaches the counters.
	$ip = '198.51.100.77';
	$t['ips'][ Util::ip_hash( $ip ) ] = 1;
	update_option( RankyfyAIB\Tracker::THROTTLE, $t, true );
	t_eq( 'counted', t_hit( T_GPT, $ip, '/z/' ) );
}

function test_retention_prunes_old_data() {
	global $wpdb;
	RankyfyAIB\Settings::update( array( 'retention_events' => 7, 'retention_history' => 30 ) );
	t_hit( T_GPT, '192.0.2.1', '/old/', time() - 10 * DAY_IN_SECONDS );
	t_hit( T_GPT, '192.0.2.1', '/new/', time() - DAY_IN_SECONDS );
	Aggregator::run( 5 );
	$wpdb->insert( Installer::table( 'daily' ), array( 'day' => wp_date( 'Y-m-d', time() - 40 * DAY_IN_SECONDS ), 'bot' => 'gptbot', 'url_hash' => md5( 'x' ), 'hits' => 1 ) );
	Aggregator::prune();
	t_eq( 1, t_count( 'events' ), 'raw requests older than 7 days removed' );
	t_eq( 2, t_count( 'daily' ), 'history kept within 30 days, older removed' );
}

function test_log_import_validates_and_reclassifies() {
	t_ranges( 'gptbot', array( '20.171.206.0/24' ) );
	$now = time() - 3600;
	$res = RankyfyAIB\Importer::batch(
		array(
			array( 'ts' => $now, 'ip' => '20.171.206.5', 'method' => 'GET', 'path' => '/a/?x=1', 'status' => 200, 'ua' => T_GPT ),
			array( 'ts' => $now, 'ip' => '20.171.206.5', 'method' => 'GET', 'path' => '/a/?x=1', 'status' => 200, 'ua' => T_GPT ), // duplicate
			array( 'ts' => $now, 'ip' => '198.51.100.1', 'method' => 'GET', 'path' => '/b/', 'status' => 200, 'ua' => T_GPT, 'cls' => 'ai', 'vstate' => 'verified' ), // client claims are ignored
			array( 'ts' => $now, 'ip' => 'not-an-ip', 'path' => '/c/', 'status' => 200, 'ua' => T_GPT ),
			array( 'ts' => time() + 86400, 'ip' => '20.171.206.5', 'path' => '/c/', 'status' => 200, 'ua' => T_GPT ),
			array( 'ts' => $now - 900 * DAY_IN_SECONDS, 'ip' => '20.171.206.5', 'path' => '/c/', 'status' => 200, 'ua' => T_GPT ),
			array( 'ts' => $now, 'ip' => '203.0.113.1', 'path' => '/d/', 'status' => 200, 'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'referer' => 'https://chatgpt.com/' ),
			'garbage',
		)
	);
	t_eq( 2, $res['events'] );
	t_eq( 1, $res['duplicates'] );
	t_eq( 3, $res['invalid'] );
	t_eq( 1, $res['too_old'] );
	t_eq( 1, $res['referrals'] );
	global $wpdb;
	t_eq( 'spoofed', $wpdb->get_var( 'SELECT cls FROM ' . Installer::table( 'events' ) . " WHERE path = '/b'" ), 're-verified on the server' );
	t_eq( 'log', $wpdb->get_var( 'SELECT source FROM ' . Installer::table( 'events' ) . " WHERE path = '/a'" ) );
}
