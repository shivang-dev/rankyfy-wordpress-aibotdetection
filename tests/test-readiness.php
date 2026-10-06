<?php
/**
 * AI Readiness engine (18 checks, score, issue queue) and the llms.txt /
 * ai.txt generator.
 */

use RankyfyAIB\Findings;
use RankyfyAIB\Installer;
use RankyfyAIB\Llms;
use RankyfyAIB\Readiness;
use RankyfyAIB\Settings;

/** An analysed, important post row in the inventory. */
function t_ready_page( $title, $words = 600, $importance = 80 ) {
	global $wpdb;
	$id = t_post( $title, '<p>' . str_repeat( 'Useful sentence about the topic at hand. ', (int) ( $words / 7 ) ) . '</p>' );
	$wpdb->update(
		Installer::table( 'pages' ),
		array( 'importance' => $importance, 'analyzed_at' => time(), 'dirty' => 0, 'word_count' => $words, 'facts' => wp_json_encode( array( 'description' => $title . ' — a short summary.' ) ) ),
		array( 'object_type' => 'post', 'object_id' => $id )
	);
	return array( $id, (int) t_page_row( $id )['id'] );
}

function t_check( array $r, $id ) {
	foreach ( $r['checks'] as $c ) {
		if ( $id === $c['id'] ) {
			return $c;
		}
	}
	return null;
}

function test_readiness_has_18_weighted_checks() {
	$r = Readiness::compute();
	t_eq( 18, count( $r['checks'] ) );
	t_eq( 100, array_sum( array_column( $r['checks'], 'weight' ) ), 'weights add up to 100' );
	t_eq( array( 'access', 'discovery', 'content', 'trust' ), array_keys( $r['groups'] ) );
	t_ok( null === $r['score'] || ( $r['score'] >= 0 && $r['score'] <= 100 ) );
	foreach ( $r['checks'] as $c ) {
		t_ok( in_array( $c['status'], array( 'pass', 'warn', 'fail', 'na' ), true ), $c['id'] . ' status' );
		t_ok( '' !== $c['title'] && '' !== $c['why'] && '' !== $c['action'], $c['id'] . ' has text' );
	}
}

function test_readiness_scores_page_checks_from_findings() {
	list( , $a ) = t_ready_page( 'Readiness page A' );
	list( , $b ) = t_ready_page( 'Readiness page B' );
	list( , $c ) = t_ready_page( 'Readiness page C' );
	list( , $d ) = t_ready_page( 'Readiness page D' );
	Findings::sync( $a, 'content', array( array( 'thin_content', array( 'n' => 80 ) ) ) );
	Findings::sync( $b, 'content', array( array( 'thin_content', array( 'n' => 90 ) ) ) );
	$r     = Readiness::compute();
	$depth = t_check( $r, 'content_depth' );
	t_eq( 2, $depth['n'] );
	t_eq( 4, $depth['total'] );
	t_eq( 'fail', $depth['status'], '50% thin' );
	t_eq( 2, count( $depth['pages'] ) );
	t_ok( in_array( 'content_depth', array_column( $r['queue'], 'id' ), true ), 'failing check is queued' );

	// Ignored findings are the owner's call and stop counting.
	global $wpdb;
	$wpdb->update( Installer::table( 'findings' ), array( 'status' => 'ignored' ), array( 'page_id' => $b, 'code' => 'thin_content' ) );
	$depth = t_check( Readiness::compute(), 'content_depth' );
	t_eq( 1, $depth['n'] );
	t_eq( 'warn', $depth['status'], '75% fine' );

	// Fixing it resolves the check and records when.
	Findings::sync( $a, 'content', array() );
	$r = Readiness::compute();
	t_eq( 'pass', t_check( $r, 'content_depth' )['status'] );
	t_ok( in_array( 'content_depth', array_column( $r['resolved'], 'id' ), true ), 'resolution history' );
}

function test_readiness_queue_order_and_accepting() {
	update_option( 'blog_public', '0' );
	$r = Readiness::compute();
	t_eq( 'fail', t_check( $r, 'site_indexable' )['status'] );
	t_eq( 'critical', $r['queue'][0]['severity'], 'critical problems lead the queue' );
	$gains = array_column( array_filter( $r['queue'], static function ( $q ) {
		return 'warning' === $q['severity'];
	} ), 'gain' );
	$sorted = $gains;
	rsort( $sorted );
	t_eq( $sorted, array_values( $gains ), 'same severity: biggest gain first' );
	update_option( 'blog_public', '1' );

	Settings::update( array( 'llms_enabled' => false ) );
	delete_option( Llms::STATUS );
	$r = Readiness::compute();
	t_ok( in_array( 'llms_txt', array_column( $r['queue'], 'id' ), true ) );
	$before = $r['score'];
	t_ok( Readiness::set_status( 'llms_txt', 'accepted' ) );
	$r = Readiness::compute();
	t_ok( ! in_array( 'llms_txt', array_column( $r['queue'], 'id' ), true ), 'accepted checks leave the queue' );
	t_eq( 1, $r['counts']['accepted'] );
	t_ok( $r['score'] >= $before, 'and the score' );
	t_ok( ! Readiness::set_status( 'nope', 'accepted' ) );
	Readiness::set_status( 'llms_txt', 'open' );
	delete_option( Readiness::STATE );
}

function test_readiness_rest() {
	$a = t_admin();
	$r = t_rest( 'GET', '/readiness', array(), $a );
	t_eq( 200, $r->get_status() );
	t_eq( 18, count( $r->get_data()['checks'] ) );
	t_eq( 400, t_rest( 'POST', '/readiness/llms_txt', array( 'status' => 'bogus' ), $a )->get_status() );
	t_eq( 200, t_rest( 'POST', '/readiness/llms_txt', array( 'status' => 'accepted' ), $a )->get_status() );
	$sub = wp_insert_user( array( 'user_login' => 'sub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	t_eq( 403, t_rest( 'GET', '/readiness', array(), $sub )->get_status() );
	t_eq( 403, t_rest( 'GET', '/ai-files', array(), $sub )->get_status() );
	wp_delete_user( $sub );
	delete_option( Readiness::STATE );
}

// ── llms.txt / ai.txt ──────────────────────────────────────────────────────

function test_llms_request_matching() {
	t_eq( 'llms.txt', Llms::requested( '/llms.txt' ) );
	t_eq( 'llms.txt', Llms::requested( '/llms.txt?ref=x' ) );
	t_eq( 'llms-full.txt', Llms::requested( '/llms-full.txt' ) );
	t_eq( 'ai.txt', Llms::requested( '/ai.txt' ) );
	t_eq( '', Llms::requested( '/blog/llms.txt' ) );
	t_eq( '', Llms::requested( '/robots.txt' ) );
	t_eq( '', Llms::requested( '/llms.txt/../wp-config.php' ) );
}

function test_llms_lists_the_right_pages() {
	global $wpdb;
	t_robots( "User-agent: *\nDisallow: /private-area\n\nUser-agent: OAI-SearchBot\nDisallow: /readiness-blocked\n" );
	list( $a ) = t_ready_page( 'Readiness pricing [2026]', 600, 95 );
	list( $b ) = t_ready_page( 'Readiness noindex page', 600, 90 );
	list( $c ) = t_ready_page( 'Readiness blocked', 600, 85 );
	list( $d ) = t_ready_page( 'Readiness minor note', 600, 5 );
	$wpdb->update( Installer::table( 'pages' ), array( 'noindex' => 1 ), array( 'object_type' => 'post', 'object_id' => $b ) );
	Settings::update( array( 'llms_enabled' => true, 'llms_summary' => "Coffee gear\nreviews.", 'llms_intro' => 'Prices are in EUR.', 'llms_max_links' => 10 ) );
	update_option( 'blogname', 'Test Site' );

	$links = 0;
	$txt   = Llms::build_llms( $links );
	t_ok( 0 === strpos( $txt, "# Test Site\n\n> Coffee gear reviews.\n\nPrices are in EUR.\n" ), 'header, one-line summary, notes' );
	t_ok( false !== strpos( $txt, '- [Readiness pricing (2026)](' . get_permalink( $a ) . '): Readiness pricing [2026] — a short summary.' ), 'link line, brackets escaped in the label' );
	t_ok( false === strpos( $txt, 'Readiness noindex page' ), 'noindex pages are left out' );
	t_ok( false === strpos( $txt, 'Readiness blocked' ), 'pages closed to AI search crawlers are left out' );
	t_ok( false !== strpos( $txt, 'Readiness minor note' ), 'everything else is listed' );
	t_ok( strpos( $txt, 'Readiness pricing' ) < strpos( $txt, 'Readiness minor note' ), 'most important first' );
	t_ok( $links >= 2 );
	t_robots( "User-agent: *\nAllow: /\n" );
}

function test_llms_full_and_ai_txt() {
	list( $a ) = t_ready_page( 'Readiness full text page', 400, 90 );
	wp_set_current_user( t_admin() ); // unfiltered_html: the script tag is really stored
	wp_update_post( array( 'ID' => $a, 'post_content' => '<!-- wp:heading --><h2>How to descale</h2><!-- /wp:heading --><p>' . str_repeat( 'Run vinegar through the machine twice. ', 20 ) . '</p><ul><li>Fill</li><li>Run</li></ul><script>alert(1)</script>' ) );
	wp_set_current_user( 0 );
	t_ok( false !== strpos( get_post( $a )->post_content, '<script>' ) );
	Settings::update( array( 'llms_enabled' => true, 'llms_full' => true ) );
	$full = Llms::build_full();
	t_ok( false !== strpos( $full, '## Readiness full text page' ) );
	t_ok( false !== strpos( $full, '### How to descale' ), 'headings kept' );
	t_ok( false !== strpos( $full, "\n- Fill" ), 'lists kept' );
	t_ok( false === strpos( $full, 'alert(1)' ), 'scripts dropped' );
	t_ok( false !== strpos( Llms::build_llms(), 'llms-full.txt' ), 'llms.txt links the full file' );

	Settings::update( array( 'ai_txt_policy' => 'allow' ) );
	t_ok( false !== strpos( Llms::build_ai_txt(), "User-Agent: *\nAllow: /\n" ) );
	Settings::update( array( 'ai_txt_policy' => 'no_media' ) );
	$t = Llms::build_ai_txt();
	t_ok( false !== strpos( $t, 'Disallow: *.jpg' ) && false !== strpos( $t, "Allow: /\n" ) );
	Settings::update( array( 'ai_txt_policy' => 'no_training' ) );
	t_ok( false !== strpos( Llms::build_ai_txt(), "User-Agent: *\nDisallow: /\n" ) );
	t_eq( 'no_training', Settings::update( array( 'ai_txt_policy' => 'invalid' ) )['ai_txt_policy'], 'unknown policies are refused' );
}

function test_llms_served_only_when_switched_on() {
	t_ready_page( 'Readiness served page', 600, 90 );
	Settings::update( array( 'llms_enabled' => false, 'ai_txt_enabled' => false ) );
	$res = wp_remote_get( home_url( '/llms.txt' ) );
	if ( is_wp_error( $res ) ) {
		echo "       (loopback unavailable; skipped)\n";
		return;
	}
	t_ok( 200 !== (int) wp_remote_retrieve_response_code( $res ) || '' === (string) wp_remote_retrieve_header( $res, 'x-rankyfy-generated' ), 'off: not ours' );

	Settings::update( array( 'llms_enabled' => true, 'ai_txt_enabled' => true ) );
	$res = wp_remote_get( home_url( '/llms.txt' ) );
	t_eq( 200, (int) wp_remote_retrieve_response_code( $res ) );
	t_eq( 'llms.txt', wp_remote_retrieve_header( $res, 'x-rankyfy-generated' ) );
	t_ok( 0 === strpos( (string) wp_remote_retrieve_header( $res, 'content-type' ), 'text/plain' ) );
	t_ok( false !== strpos( wp_remote_retrieve_body( $res ), 'Readiness served page' ) );
	$etag = wp_remote_retrieve_header( $res, 'etag' );
	t_eq( 304, (int) wp_remote_retrieve_response_code( wp_remote_get( home_url( '/llms.txt' ), array( 'headers' => array( 'If-None-Match' => $etag ) ) ) ), 'conditional request' );
	t_eq( 'ai.txt', wp_remote_retrieve_header( wp_remote_get( home_url( '/ai.txt' ) ), 'x-rankyfy-generated' ) );
	t_ok( '' === (string) wp_remote_retrieve_header( wp_remote_get( home_url( '/llms-full.txt' ) ), 'x-rankyfy-generated' ), 'llms-full.txt has its own switch' );

	// The probe sees it, and the readiness check passes.
	$p = Llms::probe();
	t_eq( 'ours', $p['llms.txt']['state'] );
	t_eq( 'pass', t_check( Readiness::compute(), 'llms_txt' )['status'] );
	Settings::update( array( 'llms_enabled' => false, 'ai_txt_enabled' => false ) );
	delete_option( Llms::STATUS );
}
