<?php
/**
 * Inventory, page analysis, findings lifecycle, scoring, links, alerts and
 * notifications.
 */

use RankyfyAIB\Analyzer;
use RankyfyAIB\Coverage;
use RankyfyAIB\Findings;
use RankyfyAIB\Installer;
use RankyfyAIB\Inventory;

function t_post( $title, $content, $type = 'post' ) {
	return wp_insert_post( array( 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_type' => $type ) );
}
function t_page_row( $post_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'pages' ) . " WHERE object_type = 'post' AND object_id = %d", $post_id ), ARRAY_A );
}

function test_inventory_tracks_publish_edit_unpublish() {
	$id = t_post( 'Inventory test page', '<p>Hello</p>' );
	$row = t_page_row( $id );
	t_ok( $row && 0 === (int) $row['deleted'] && 1 === (int) $row['dirty'], 'new post queued for analysis' );
	wp_update_post( array( 'ID' => $id, 'post_name' => 'renamed-inventory-test' ) );
	$row2 = t_page_row( $id );
	t_eq( (int) $row['id'], (int) $row2['id'], 'same row after a slug change' );
	t_ok( false !== strpos( $row2['path'], 'renamed-inventory-test' ) );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
	t_eq( 1, (int) t_page_row( $id )['deleted'], 'unpublished pages leave the inventory' );
	wp_delete_post( $id, true );
}

function test_analysis_facts_findings_and_score() {
	$content = '<p>' . str_repeat( 'Pour-over coffee needs fresh beans and a burr grinder for even extraction. ', 30 ) . '</p>'
		. '<h2>What grind size should I use?</h2><p>Medium-fine.</p><h2>How hot should the water be?</h2><p>About 94 °C.</p><h2>Which filter?</h2><p>Paper.</p>'
		. '<ul><li>Rinse</li><li>Bloom</li></ul><img src="/a.jpg"><img src="/b.jpg" alt="kettle">';
	$id      = t_post( 'How to brew pour-over coffee', $content );
	$row     = t_page_row( $id );
	$facts   = Analyzer::analyze( $row );
	t_eq( 3, count( $facts['question_headings'] ) );
	t_ok( $facts['faq'], 'three question headings count as an FAQ section' );
	t_eq( 1, (int) $facts['images_no_alt'] );
	t_eq( 'informational', $facts['intent']['intent'] );
	t_ok( $facts['words'] > 300 );
	Coverage::refresh_pages( array( (int) $row['id'] ) );
	$row = t_page_row( $id );
	t_ok( null !== $row['aeo_score'] && (int) $row['aeo_score'] > 0 && (int) $row['aeo_score'] <= 100 );
	$parts = json_decode( $row['aeo'], true );
	t_eq( array( 'access', 'discovery', 'structure', 'depth', 'trust', 'linking' ), array_keys( $parts ) );
	$codes = array_column( Findings::for_page( (int) $row['id'] ), 'code' );
	t_ok( in_array( 'missing_alt', $codes, true ) );
	t_ok( ! in_array( 'no_faq', $codes, true ) && ! in_array( 'thin_content', $codes, true ) );
	$terms = array_column( Analyzer::key_terms( (int) $row['id'] ), 'term' );
	t_ok( (bool) array_intersect( array( 'pour-over', 'pour-over coffee', 'coffee', 'grinder', 'burr grinder' ), $terms ), 'key terms come from the content' );
	wp_delete_post( $id, true );
}

function test_findings_lifecycle() {
	t_eq( array( array( 'thin_content', '' ) ), Findings::sync( 9999, 'content', array( array( 'thin_content', array( 'n' => 10 ) ) ) ) );
	t_eq( array(), Findings::sync( 9999, 'content', array( array( 'thin_content', array( 'n' => 12 ) ) ) ), 'still open: not new' );
	Findings::sync( 9999, 'content', array() );
	global $wpdb;
	$f = $wpdb->get_row( 'SELECT * FROM ' . Installer::table( 'findings' ) . ' WHERE page_id = 9999', ARRAY_A );
	t_eq( 'resolved', $f['status'], 'fixed issues are resolved, with a date' );
	t_ok( (int) $f['resolved_at'] > 0 );
	t_eq( array( array( 'thin_content', '' ) ), Findings::sync( 9999, 'content', array( array( 'thin_content', array() ) ) ), 'a regression reopens it' );
	Findings::set_status( (int) $f['id'], 'ignored' );
	Findings::sync( 9999, 'content', array( array( 'thin_content', array() ) ) );
	t_eq( 'ignored', $wpdb->get_var( 'SELECT status FROM ' . Installer::table( 'findings' ) . ' WHERE page_id = 9999' ), 'ignored stays ignored' );
	// Scopes do not touch each other.
	Findings::sync( 9999, 'coverage', array( array( 'never_crawled', array() ) ) );
	Findings::sync( 9999, 'content', array() );
	t_eq( 'open', $wpdb->get_var( 'SELECT status FROM ' . Installer::table( 'findings' ) . " WHERE page_id = 9999 AND code = 'never_crawled'" ) );
}

function test_findings_text_is_actionable() {
	foreach ( array_keys( RankyfyAIB\Catalog::CODES ) as $code ) {
		$t = RankyfyAIB\Catalog::text( $code, array( 'bot' => 'gptbot', 'n' => 3, 'value' => 'X' ) );
		t_ok( '' !== $t['title'] && '' !== $t['why'] && '' !== $t['action'], "$code has title, why and action" );
		t_ok( '' !== RankyfyAIB\Catalog::label( $code ), "$code has a generic label" );
	}
}

function test_importance_signals() {
	$id   = t_post( 'Cornerstone guide', '<p>x</p>', 'page' );
	update_post_meta( $id, '_yoast_wpseo_is_cornerstone', '1' );
	$row  = t_page_row( $id );
	list( $score, $why ) = Inventory::importance( $row );
	t_ok( in_array( 'cornerstone', $why, true ) && in_array( 'page', $why, true ) );
	$row['pinned'] = 1;
	t_eq( 100, Inventory::importance( $row )[0] );
	$row['pinned'] = -1;
	t_eq( 0, Inventory::importance( $row )[0] );
	wp_delete_post( $id, true );
}

function test_never_crawled_and_blocked_findings() {
	update_option( 'rfaib_monitoring_since', time() - 30 * DAY_IN_SECONDS );
	$id  = t_post( 'Important but ignored', '<p>' . str_repeat( 'word ', 400 ) . '</p>' );
	$row = t_page_row( $id );
	Inventory::set_pin( (int) $row['id'], 1 );
	global $wpdb;
	$wpdb->insert( Installer::table( 'bots_seen' ), array( 'bot' => 'gptbot', 'first_seen' => time() - 20 * DAY_IN_SECONDS, 'last_seen' => time() - 3600, 'hits' => 50 ) );
	Analyzer::analyze( t_page_row( $id ) );
	Coverage::refresh_pages( array( (int) $row['id'] ) );
	$codes = array_column( Findings::for_page( (int) $row['id'] ), 'code' );
	t_ok( in_array( 'never_crawled', $codes, true ), 'AI crawlers active, important page never requested' );
	// robots.txt blocks a priority crawler from this page.
	update_option( RankyfyAIB\Robots::OPTION, array( 'body' => "User-agent: OAI-SearchBot\nDisallow: /\n", 'source' => 'http', 'status' => 200, 'fetched_at' => time(), 'hash' => 'x' ), false );
	Coverage::refresh_pages( array( (int) $row['id'] ) );
	$f = array_values( array_filter( Findings::for_page( (int) $row['id'] ), static function ( $f ) {
		return 'robots_blocked' === $f['code'];
	} ) );
	t_eq( 'oai-searchbot', $f[0]['bot'] ?? null );
	t_eq( 'critical', $f[0]['severity'] );
	delete_option( RankyfyAIB\Robots::OPTION );
	wp_delete_post( $id, true );
}

function test_internal_link_suggestions() {
	$a = t_post( 'Cold brew ratio guide', '<p>' . str_repeat( 'Cold brew ratio and cold brew concentrate steeping time. ', 20 ) . '</p>' );
	$b = t_post( 'Cold brew at home', '<p>' . str_repeat( 'Making cold brew at home with a good cold brew ratio. ', 20 ) . '</p>' );
	$c = t_post( 'Iced coffee drinks', '<p>' . str_repeat( 'Cold brew and iced coffee recipes for summer. ', 20 ) . '</p>' );
	foreach ( array( $a, $b, $c ) as $id ) {
		Analyzer::analyze( t_page_row( $id ) );
	}
	$s = RankyfyAIB\Linker::for_page( (int) t_page_row( $a )['id'] );
	t_ok( count( $s ) >= 1, 'related pages suggested' );
	t_ok( in_array( $s[0]['anchor'], array( 'cold brew', 'brew ratio', 'cold brew ratio', 'cold', 'brew' ), true ), 'anchor taken from terms both pages use: ' . $s[0]['anchor'] );
	t_ok( false !== strpos( $s[0]['anchor'], ' ' ), 'a phrase is preferred as anchor text' );
	foreach ( array( $a, $b, $c ) as $id ) {
		wp_delete_post( $id, true );
	}
}

function test_alerts_dedupe_and_baseline() {
	t_ok( RankyfyAIB\Alerts::raise( 'r', 'k', 'info', 'T', 'W', 'Y', array(), 'A' ) > 0 );
	t_eq( 0, RankyfyAIB\Alerts::raise( 'r', 'k', 'info', 'T', 'W', 'Y', array(), 'A' ), 'same key within cooldown is not repeated' );
	// Page problems found while building the baseline (first inventory pass) are not announced.
	update_option( RankyfyAIB\Inventory::STATE, array( 'running' => true, 'gen' => 1, 'phase' => 'posts', 'cursor' => 0 ), false );
	RankyfyAIB\Alerts::page_findings_opened( array( 'id' => 1, 'title' => 'P', 'path' => '/p' ), array( array( 'noindex', '' ) ) );
	t_eq( 1, RankyfyAIB\Alerts::unread_count() );
	update_option( RankyfyAIB\Alerts::BASELINE, time() );
	RankyfyAIB\Alerts::page_findings_opened( array( 'id' => 1, 'title' => 'P', 'path' => '/p' ), array( array( 'noindex', '' ) ) );
	t_eq( 2, RankyfyAIB\Alerts::unread_count() );
	RankyfyAIB\Alerts::stream( array( 'gptbot' => true ), array() );
	$a = RankyfyAIB\Alerts::list( '', 1 )[0];
	t_eq( 'new_bot', $a['rule'] );
	t_ok( $a['what'] && $a['why'] && $a['action'], 'what happened, why it matters, what to do' );
}

function test_notifications_email_and_webhook() {
	$mails = array();
	$posts = array();
	$mail  = static function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	};
	$http = static function ( $pre, $args, $url ) use ( &$posts ) {
		$posts[] = array( $url, json_decode( $args['body'], true ) );
		return array( 'headers' => array(), 'body' => 'ok', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	};
	add_filter( 'pre_wp_mail', $mail, 10, 2 );
	add_filter( 'pre_http_request', $http, 10, 3 );
	try {
		RankyfyAIB\Settings::update( array( 'notify_mode' => 'critical', 'notify_email' => 'owner@example.com', 'webhook_url' => 'https://hooks.example.com/x', 'webhook_level' => 'warning' ) );
		RankyfyAIB\Alerts::raise( 'a', '1', 'info', 'Info alert', 'w', 'y', array(), 'a' );
		RankyfyAIB\Alerts::raise( 'b', '2', 'critical', 'Critical alert', 'w', 'y', array( array( 'label' => 'Page', 'path' => '/p' ) ), 'do this' );
		RankyfyAIB\Notifier::deliver();
		t_eq( 1, count( $mails ), 'critical-only mode emails the critical alert' );
		t_ok( false !== strpos( $mails[0]['subject'], 'Critical alert' ) );
		t_ok( false !== strpos( $mails[0]['message'], 'What to do:' ) && false !== strpos( $mails[0]['message'], 'Why it matters:' ) );
		t_eq( 1, count( $posts ), 'webhook gets warning and above' );
		t_eq( 'https://hooks.example.com/x', $posts[0][0] );
		t_eq( 'Critical alert', $posts[0][1]['title'] );
		RankyfyAIB\Notifier::deliver();
		t_eq( 1, count( $mails ), 'each alert is delivered once' );
		// Settings refuse non-https webhooks (no plain-http or internal targets).
		RankyfyAIB\Settings::update( array( 'webhook_url' => 'http://127.0.0.1:8080/' ) );
		t_eq( 'https://hooks.example.com/x', RankyfyAIB\Settings::get( 'webhook_url' ) );
	} finally {
		remove_filter( 'pre_wp_mail', $mail, 10 );
		remove_filter( 'pre_http_request', $http, 10 );
	}
}
