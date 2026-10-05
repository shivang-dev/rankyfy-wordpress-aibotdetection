<?php
/**
 * Regressions for issues found in review: proxies/CDNs, DNS failures,
 * signature edge cases, failed statements inside a chunk, idempotent import.
 */

use RankyfyAIB\Installer;
use RankyfyAIB\Util;

function t_server( array $vars ) {
	foreach ( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED' ) as $k ) {
		unset( $_SERVER[ $k ] );
	}
	foreach ( $vars as $k => $v ) {
		$_SERVER[ $k ] = $v;
	}
}

function test_cloudflare_client_address_is_trusted_only_from_cloudflare() {
	t_server( array( 'REMOTE_ADDR' => '172.64.1.10', 'HTTP_CF_CONNECTING_IP' => '20.171.206.9' ) );
	t_eq( '20.171.206.9', Util::client_ip(), 'Cloudflare edge address: header used' );
	t_ok( ! Util::proxy_suspected() );
	t_server( array( 'REMOTE_ADDR' => '198.51.100.4', 'HTTP_CF_CONNECTING_IP' => '20.171.206.9' ) );
	t_eq( '198.51.100.4', Util::client_ip(), 'forged CF header from elsewhere: ignored' );
	t_server( array() );
}

function test_unconfigured_proxy_never_produces_impersonations() {
	t_ranges( 'gptbot', array( '20.171.206.0/24' ) );
	t_server( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '20.171.206.9' ) );
	$ip = Util::client_ip();
	t_eq( '10.0.0.5', $ip, 'untrusted header not used' );
	t_eq( 'event', t_hit( 'GPTBot/1.4', $ip, '/behind-proxy/' ) );
	global $wpdb;
	$row = $wpdb->get_row( 'SELECT cls, vstate FROM ' . Installer::table( 'events' ) . " WHERE path = '/behind-proxy'", ARRAY_A );
	t_eq( array( 'cls' => 'ai', 'vstate' => 'none' ), $row, 'unverifiable, not spoofed' );
	t_ok( (int) get_option( 'rfaib_proxy_noted' ) > 0, 'noted for the Technical screen' );
	RankyfyAIB\Coverage::site();
	t_ok( in_array( 'proxy_unconfigured', array_column( RankyfyAIB\Findings::site(), 'code' ), true ) );
	// Imported log lines are judged by their own addresses, not by the admin's request headers.
	$res = RankyfyAIB\Importer::batch( array( array( 'ts' => time() - 60, 'ip' => '198.51.100.9', 'path' => '/imp/', 'status' => 200, 'ua' => 'GPTBot/1.4' ) ) );
	t_eq( 1, $res['events'] );
	t_eq( 'spoofed', $wpdb->get_var( 'SELECT cls FROM ' . Installer::table( 'events' ) . " WHERE path = '/imp'" ) );
	t_server( array() );
	delete_option( 'rfaib_proxy_noted' );
	delete_transient( 'rfaib_proxy_seen' );
}

function test_failed_dns_verdicts_expire_after_a_day() {
	global $wpdb;
	$h = Util::ip_hash( '66.249.90.9' );
	$wpdb->insert( Installer::table( 'ip_verdicts' ), array( 'ip_hash' => $h, 'bot' => 'googlebot', 'verdict' => 'failed', 'method' => 'rdns', 'checked_at' => time() - 2 * DAY_IN_SECONDS ) );
	t_eq( '', RankyfyAIB\Verifier::cached( 'googlebot', $h ), 'a two-day-old failure is checked again' );
	RankyfyAIB\Verifier::flush_cache();
	$wpdb->update( Installer::table( 'ip_verdicts' ), array( 'verdict' => 'verified' ), array( 'ip_hash' => $h ) );
	t_eq( 'verified', RankyfyAIB\Verifier::cached( 'googlebot', $h ), 'a pass lasts a week' );
}

function test_signature_key_rotation_and_dictionary_form() {
	$kp  = sodium_crypto_sign_keypair();
	$pk  = sodium_crypto_sign_publickey( $kp );
	$sk  = sodium_crypto_sign_secretkey( $kp );
	$kid = RankyfyAIB\Verifier::jwk_thumbprint( rtrim( strtr( base64_encode( $pk ), '+/', '-_' ), '=' ) );
	$keys = static function ( $pre, $host ) use ( $kid, $pk ) {
		return 'chatgpt.com' === $host ? array( $kid => base64_encode( $pk ) ) : array();
	};
	add_filter( 'rfaib_signature_keys', $keys, 10, 2 );
	try {
		$now = time();
		// Dictionary form with a key-parameterised component (RFC 9421 §2.1.2).
		$params = '("@authority" "signature-agent";key="sig1");created=' . ( $now - 5 ) . ';keyid="' . $kid . '";alg="ed25519";tag="web-bot-auth"';
		$base   = "\"@authority\": shop.example\n\"signature-agent\";key=\"sig1\": \"https://chatgpt.com\"\n\"@signature-params\": " . $params;
		$sig    = base64_encode( sodium_crypto_sign_detached( $base, $sk ) );
		$row    = array( 'created_at' => $now, 'payload' => wp_json_encode( array( '@authority' => 'shop.example', 'signature-agent' => 'sig1="https://chatgpt.com"', 'signature-input' => 'sig1=' . $params, 'signature' => 'sig1=:' . $sig . ':' ) ) );
		t_eq( 'verified', RankyfyAIB\Verifier::check_signature( $row )[0], 'dictionary Signature-Agent with ;key parameter' );
		// Rotated key: keyid not (yet) in the cached directory → cannot decide, never "spoofed".
		$rot = str_replace( $kid, 'rotated-key-id', $row['payload'] );
		t_eq( 'none', RankyfyAIB\Verifier::check_signature( array( 'created_at' => $now, 'payload' => $rot ) )[0] );
	} finally {
		remove_filter( 'rfaib_signature_keys', $keys, 10 );
	}
}

function test_failed_statement_rolls_back_the_whole_chunk() {
	global $wpdb;
	for ( $i = 0; $i < 20; $i++ ) {
		t_hit( 'GPTBot/1.4', '192.0.2.' . ( $i + 1 ), '/r' . ( $i % 4 ) . '/', time() - $i * 60 );
	}
	$daily = Installer::table( 'daily' );
	$wpdb->query( "RENAME TABLE {$daily} TO {$daily}_off" ); // phpcs:ignore
	$wpdb->suppress_errors( true );
	try {
		RankyfyAIB\Aggregator::run( 5 );
		t_ok( false, 'expected the chunk to fail' );
	} catch ( RuntimeException $e ) {
		t_ok( false !== strpos( $e->getMessage(), 'rollup statement failed' ) );
	} finally {
		$wpdb->suppress_errors( false );
		$wpdb->query( "RENAME TABLE {$daily}_off TO {$daily}" ); // phpcs:ignore
	}
	wp_cache_delete( 'rfaib_agg_watermark', 'options' );
	t_eq( '0', (string) get_option( 'rfaib_agg_watermark' ), 'watermark not advanced' );
	t_eq( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'daily_bots' ) ), 'nothing half-written' );
	t_eq( 20, RankyfyAIB\Aggregator::run( 5 ), 'retried in full' );
	t_eq( 20, (int) $wpdb->get_var( 'SELECT SUM(hits) FROM ' . Installer::table( 'daily_bots' ) ) );
}

function test_import_is_idempotent_and_never_inflates_counters() {
	global $wpdb;
	$lines = array(
		array( 'ts' => time() - 100, 'ip' => '192.0.2.1', 'path' => '/i1/', 'status' => 200, 'ua' => 'GPTBot/1.4' ),
		array( 'ts' => time() - 100, 'ip' => '66.249.64.1', 'path' => '/i1/', 'status' => 200, 'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1)' ),
		array( 'ts' => time() - 100, 'ip' => '192.0.2.2', 'path' => '/i2/', 'status' => 200, 'ua' => 'python-requests/2.32' ),
		array( 'ts' => time() - 100, 'ip' => '192.0.2.3', 'path' => '/i3/', 'status' => 200, 'ua' => '' ),
		array( 'ts' => time() - 100, 'ip' => '203.0.113.7', 'path' => '/i4/', 'status' => 200, 'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'referer' => 'https://chatgpt.com/' ),
	);
	$a = RankyfyAIB\Importer::batch( $lines, 'abcdef0123456789:0' );
	t_eq( 1, $a['events'] );
	t_eq( 2, $a['not_recorded'], 'search/unknown bot lines do not touch counters' );
	t_eq( 1, $a['no_user_agent'] );
	t_eq( 1, $a['referrals'] );
	$b = RankyfyAIB\Importer::batch( $lines, 'abcdef0123456789:0' );
	t_ok( ! empty( $b['already_imported'] ), 'same batch key: skipped' );
	t_eq( 0, (int) $wpdb->get_var( 'SELECT COALESCE(SUM(hits),0) FROM ' . Installer::table( 'daily_bots' ) ) );
	t_eq( 1, (int) $wpdb->get_var( 'SELECT SUM(hits) FROM ' . Installer::table( 'referrals' ) ), 'referral counted once' );
	t_eq( 0, (int) $wpdb->get_var( 'SELECT COALESCE(SUM(hits),0) FROM ' . Installer::table( 'agents' ) ), 'imported user agents are listed but not counted' );
	delete_option( RankyfyAIB\Importer::SEEN );
}

function test_imported_pending_events_hold_the_watermark() {
	t_ranges( 'googlebot', array( '66.249.64.0/27' ) );
	RankyfyAIB\Settings::update( array( 'track_search_pages' => true ) );
	RankyfyAIB\Importer::batch( array( array( 'ts' => time() - 5 * DAY_IN_SECONDS, 'ip' => '66.249.90.77', 'path' => '/old/', 'status' => 200, 'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) ) );
	t_eq( 0, RankyfyAIB\Aggregator::run( 5 ), 'an old imported request waits for its DNS verdict too' );
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::table( 'verify_queue' ) );
	t_eq( 1, RankyfyAIB\Aggregator::run( 5 ), 'an orphaned pending event is repaired, not stuck forever' );
	t_eq( 'none', $wpdb->get_var( 'SELECT vstate FROM ' . Installer::table( 'events' ) ) );
}

function test_concurrent_page_analysis_is_safe() {
	global $wpdb;
	$ids = array();
	for ( $i = 0; $i < 12; $i++ ) {
		$ids[] = wp_insert_post( array( 'post_title' => "Concurrent $i", 'post_content' => '<p>' . str_repeat( "Cold brew ratio and grinder size for espresso number $i. ", 30 ) . '</p>', 'post_status' => 'publish' ) );
	}
	$wpdb->query( 'UPDATE ' . Installer::table( 'pages' ) . ' SET dirty = 1' );
	// Two analysers on the same pages at once (worker + manual re-check).
	$cmd = 'cd ' . escapeshellarg( ABSPATH ) . ' && wp eval ' . escapeshellarg( 'global $wpdb; foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}rfaib_pages WHERE deleted = 0", ARRAY_A ) as $r ) { try { RankyfyAIB\Analyzer::analyze( $r ); } catch ( Throwable $e ) { echo "E:", $e->getMessage(), "\n"; } }' ) . ' 2>&1';
	$a = popen( $cmd, 'r' );
	$b = popen( $cmd, 'r' );
	$out = stream_get_contents( $a ) . stream_get_contents( $b );
	pclose( $a );
	pclose( $b );
	t_ok( false === strpos( $out, 'Duplicate entry' ), 'no duplicate-key errors: ' . substr( $out, 0, 300 ) );
	// Whatever a deadlock rolled back is consistent: every analysed page has its terms.
	$bad = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'pages' ) . ' p WHERE p.deleted = 0 AND p.dirty = 0 AND p.word_count > 50 AND NOT EXISTS (SELECT 1 FROM ' . Installer::table( 'page_terms' ) . ' t WHERE t.page_id = p.id)' );
	t_eq( 0, $bad, 'no page marked analysed without its terms' );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
}
