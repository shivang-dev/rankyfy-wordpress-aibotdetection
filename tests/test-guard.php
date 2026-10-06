<?php
/**
 * Publish-time guard: checks before publishing, redirects for changed URLs,
 * permissions.
 */

use RankyfyAIB\Guard;
use RankyfyAIB\Installer;
use RankyfyAIB\Robots;
use RankyfyAIB\Settings;
use RankyfyAIB\Util;

function t_guard_status( array $res, $id ) {
	foreach ( $res['checks'] as $c ) {
		if ( $id === $c['id'] ) {
			return $c['status'];
		}
	}
	return null;
}

function t_robots( $body ) {
	update_option( Robots::OPTION, array( 'body' => $body, 'source' => 'http', 'status' => 200, 'fetched_at' => time(), 'hash' => md5( $body ) ), false );
}

function t_redirect_row( $path ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'redirects' ) . ' WHERE source_hash = %s', Util::url_hash( Util::normalize_path( $path ) ) ), ARRAY_A );
}

function test_guard_flags_noindex_thin_content_and_robots() {
	t_robots( "User-agent: *\nAllow: /\n" );
	$long = '<p>' . str_repeat( 'Cold brew coffee steeps coarse grounds in cold water for many hours. ', 40 ) . '</p>';
	$id   = t_post( 'Guard clean page', $long );
	$res  = Guard::check( get_post( $id ) );
	t_eq( 'pass', t_guard_status( $res, 'indexing' ) );
	t_eq( 'pass', t_guard_status( $res, 'robots' ) );
	t_eq( 'pass', t_guard_status( $res, 'content' ) );
	t_eq( 0, $res['counts']['fail'] );

	// Unsaved editor content is what gets judged.
	$res = Guard::check( get_post( $id ), array( 'content' => '<!-- wp:paragraph --><p>Too short.</p><!-- /wp:paragraph -->' ) );
	t_eq( 'warn', t_guard_status( $res, 'content' ), 'thin content from the editor' );

	update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '1' );
	$res = Guard::check( get_post( $id ) );
	t_eq( 'fail', t_guard_status( $res, 'indexing' ), 'Yoast noindex' );
	delete_post_meta( $id, '_yoast_wpseo_meta-robots-noindex' );

	update_post_meta( $id, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
	t_eq( 'fail', t_guard_status( Guard::check( get_post( $id ) ), 'indexing' ), 'Rank Math noindex' );
	delete_post_meta( $id, 'rank_math_robots' );

	update_option( 'blog_public', '0' );
	t_eq( 'fail', t_guard_status( Guard::check( get_post( $id ) ), 'indexing' ), 'site-wide noindex' );
	update_option( 'blog_public', '1' );

	t_robots( "User-agent: OAI-SearchBot\nDisallow: /\n" );
	$res = Guard::check( get_post( $id ) );
	t_eq( 'fail', t_guard_status( $res, 'robots' ), 'AI search crawler blocked' );
	t_ok( false !== strpos( $res['checks'][1]['detail'], 'OAI-SearchBot' ) );

	// Only user-triggered agents blocked: a warning, not a failure.
	t_robots( "User-agent: ChatGPT-User\nDisallow: /\n" );
	t_eq( 'warn', t_guard_status( Guard::check( get_post( $id ) ), 'robots' ) );

	// Confirm mode turns failures into something to acknowledge.
	t_robots( "User-agent: OAI-SearchBot\nDisallow: /\n" );
	Settings::update( array( 'guard_mode' => 'confirm' ) );
	t_ok( Guard::check( get_post( $id ) )['needs_ack'] );
	Settings::update( array( 'guard_mode' => 'warn' ) );
	t_ok( ! Guard::check( get_post( $id ) )['needs_ack'] );
	t_robots( "User-agent: *\nAllow: /\n" );
	wp_delete_post( $id, true );
}

function test_guard_canonical_and_builder_pages() {
	$id    = t_post( 'Guard canonical page', '<p>' . str_repeat( 'word ', 400 ) . '</p>' );
	$other = t_post( 'Guard canonical target', '<p>Target</p>' );
	update_post_meta( $id, 'rank_math_canonical_url', get_permalink( $other ) );
	t_eq( 'warn', t_guard_status( Guard::check( get_post( $id ) ), 'canonical' ) );
	update_post_meta( $id, 'rank_math_canonical_url', get_permalink( $id ) );
	t_eq( 'pass', t_guard_status( Guard::check( get_post( $id ) ), 'canonical' ), 'self-canonical' );

	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	t_eq( 'na', t_guard_status( Guard::check( get_post( $id ) ), 'content' ), 'page builders are measured after publishing' );
	wp_delete_post( $id, true );
	wp_delete_post( $other, true );
}

function test_guard_content_loss_on_important_page() {
	global $wpdb;
	$id = t_post( 'Guard shrinking page', '<p>' . str_repeat( 'Detailed guide text with plenty of facts. ', 120 ) . '</p>' );
	$wpdb->update( Installer::table( 'pages' ), array( 'word_count' => 840, 'importance' => 90 ), array( 'object_type' => 'post', 'object_id' => $id ) );
	$res = Guard::check( get_post( $id ), array( 'content' => '<p>' . str_repeat( 'short ', 320 ) . '</p>' ) );
	t_eq( 'fail', t_guard_status( $res, 'content' ), 'an important page losing most of its text' );
	t_ok( $res['important'] );
	wp_delete_post( $id, true );
}

function test_guard_predicts_url_change_and_redirects_old_url() {
	$id  = t_post( 'Guard slug page', '<p>' . str_repeat( 'word ', 400 ) . '</p>' );
	$old = get_permalink( $id );
	$res = Guard::check( get_post( $id ), array( 'slug' => 'guard-slug-renamed' ) );
	t_eq( 'pass', t_guard_status( $res, 'url_change' ), 'redirect will be added' );
	t_eq( $old, $res['old_url'] );
	t_ok( false !== strpos( $res['url'], 'guard-slug-renamed' ) );

	Settings::update( array( 'guard_redirects' => false ) );
	t_eq( 'warn', t_guard_status( Guard::check( get_post( $id ), array( 'slug' => 'guard-slug-renamed' ) ), 'url_change' ), 'no redirect, never crawled' );
	Settings::update( array( 'guard_redirects' => true ) );

	wp_update_post( array( 'ID' => $id, 'post_name' => 'guard-slug-renamed' ) );
	$row = t_redirect_row( $old );
	t_ok( $row && (int) $row['post_id'] === $id, 'old URL recorded' );
	t_eq( 1, count( Guard::check( get_post( $id ) )['redirects'] ) );

	// Over HTTP: the old URL answers 301 to the new one, keeping the query string.
	$res = wp_remote_get( $old . '?utm_source=chatgpt.com', array( 'redirection' => 0 ) );
	if ( is_wp_error( $res ) ) {
		echo "       (loopback unavailable; HTTP part skipped)\n";
	} else {
		t_eq( 301, (int) wp_remote_retrieve_response_code( $res ) );
		t_eq( get_permalink( $id ) . '?utm_source=chatgpt.com', wp_remote_retrieve_header( $res, 'location' ) );
		t_eq( 1, (int) t_redirect_row( $old )['hits'] );
	}

	// A new post published at the old URL wins: the redirect is dropped.
	$taker = wp_insert_post( array( 'post_title' => 'Guard slug page', 'post_name' => basename( untrailingslashit( $old ) ), 'post_status' => 'publish', 'post_type' => 'post' ) );
	if ( Util::normalize_path( get_permalink( $taker ) ) === Util::normalize_path( $old ) ) {
		t_ok( null === t_redirect_row( $old ), 'redirect removed when the URL is in use again' );
	}
	wp_delete_post( $taker, true );
	wp_delete_post( $id, true );
}

function test_guard_redirects_children_when_a_parent_moves() {
	$parent = wp_insert_post( array( 'post_title' => 'Guard Services', 'post_name' => 'guard-services', 'post_status' => 'publish', 'post_type' => 'page' ) );
	$child  = wp_insert_post( array( 'post_title' => 'Guard Repairs', 'post_name' => 'repairs', 'post_status' => 'publish', 'post_type' => 'page', 'post_parent' => $parent ) );
	$old    = get_permalink( $child );
	wp_update_post( array( 'ID' => $parent, 'post_name' => 'guard-what-we-do' ) );
	$row = t_redirect_row( $old );
	t_ok( $row && (int) $row['post_id'] === $child && 'parent' === $row['reason'], 'child page redirected too' );
	t_ok( null !== t_redirect_row( '/guard-services' ) );
	// Unrelated edits create nothing.
	wp_update_post( array( 'ID' => $child, 'post_content' => 'changed' ) );
	t_eq( 2, count( RankyfyAIB\Guard::redirects()['items'] ) );
	wp_delete_post( $child, true );
	wp_delete_post( $parent, true );
}

function test_guard_rest_permissions() {
	$author = wp_insert_user( array( 'user_login' => 'auth' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
	$sub    = wp_insert_user( array( 'user_login' => 'sub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	$own    = wp_insert_post( array( 'post_title' => 'Author post', 'post_content' => 'x', 'post_status' => 'draft', 'post_author' => $author ) );
	$theirs = t_post( 'Admin post', 'y' );
	t_eq( 200, t_rest( 'POST', '/guard/' . $own, array( 'slug' => 'author-post' ), $author )->get_status(), 'author on own post' );
	t_eq( 403, t_rest( 'POST', '/guard/' . $theirs, array(), $author )->get_status(), 'author on someone else\'s published post' );
	t_eq( 403, t_rest( 'POST', '/guard/' . $own, array(), $sub )->get_status(), 'subscriber' );
	t_eq( 401, t_rest( 'POST', '/guard/' . $own )->get_status(), 'anonymous' );
	// The admin-only redirect list stays admin-only.
	t_eq( 403, t_rest( 'GET', '/redirects', array(), $author )->get_status() );
	t_eq( 200, t_rest( 'GET', '/redirects', array(), t_admin() )->get_status() );
	wp_delete_post( $own, true );
	wp_delete_post( $theirs, true );
	wp_delete_user( $author );
	wp_delete_user( $sub );
}

function test_guard_alerts_on_publishing_a_noindex_page() {
	global $wpdb;
	wp_set_current_user( t_admin() );
	$id = wp_insert_post( array( 'post_title' => 'Guard alert page', 'post_content' => str_repeat( 'word ', 400 ), 'post_status' => 'draft' ) );
	update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '1' );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
	$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::table( 'alerts' ) . " WHERE rule = 'publish_guard'" );
	t_eq( 1, $n, 'first publish with a failing check raises one alert' );
	t_ok( is_array( get_transient( Guard::NOTICE . t_admin() . '_' . $id ) ), 'result kept for the editor notice' );
	wp_set_current_user( 0 );
	wp_delete_post( $id, true );
}
