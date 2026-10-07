<?php
/**
 * Screens from the UI kit: Access Manager, AI Crawlers by page, URL detail,
 * AI Referrals, llms.txt override, the editor's redirect choice, the posts
 * list column, and REST permissions on the new routes.
 */

use RankyfyAIB\Access;
use RankyfyAIB\Aggregator;
use RankyfyAIB\Analytics;
use RankyfyAIB\Guard;
use RankyfyAIB\Installer;
use RankyfyAIB\Llms;
use RankyfyAIB\Robots;
use RankyfyAIB\Settings;
use RankyfyAIB\Util;

/** Verified AI crawler requests for a path, folded into the rollups. */
function t_crawl( $bot, $ua, $cidr, $path, $n, $status = 200 ) {
	t_ranges( $bot, array( $cidr ) );
	$ip = substr( $cidr, 0, strrpos( $cidr, '.' ) ) . '.7';
	for ( $i = 0; $i < $n; $i++ ) {
		t_hit( $ua, $ip, $path, time() - 3600 - $i * 60, $status );
	}
}
const T_UA_GPTBOT    = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';
const T_UA_CLAUDEBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';

function test_access_manager_writes_only_its_fenced_block() {
	delete_option( Access::OPTION );
	update_option( 'blog_public', '1' );
	t_eq( '', Access::block(), 'nothing chosen, nothing written' );
	$base = apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", '1' );
	t_ok( false === strpos( $base, 'RankyFy' ) );

	$saved = Access::save( array( 'bytespider' => 'block', 'gptbot' => 'allow', 'not-a-bot' => 'block', 'claudebot' => 'evil' ) );
	t_eq( array( 'bytespider' => 'block', 'gptbot' => 'allow' ), $saved, 'unknown bots and states are refused' );
	$txt = apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", '1' );
	t_ok( 0 === strpos( $txt, "User-agent: *\nDisallow: /wp-admin/" ), 'other rules come first, untouched' );
	t_ok( false !== strpos( $txt, Access::BEGIN . "\nUser-agent: Bytespider\nDisallow: /\n" ), 'fenced block appended' );
	t_ok( false === strpos( $txt, 'GPTBot' ), 'allowed means no rule' );
	$parsed = Robots::parse( $txt );
	t_ok( ! Robots::check( $parsed, 'Bytespider', '/any-page' )['allowed'], 'Bytespider is blocked' );
	t_ok( Robots::check( $parsed, 'GPTBot', '/any-page' )['allowed'], 'GPTBot still allowed' );
	t_ok( false === strpos( apply_filters( 'robots_txt', "User-agent: *\nDisallow: /\n", '0' ), 'RankyFy' ), 'not added when the site is closed anyway' );

	// Several tokens for one crawler, and unreviewed again ('').
	Access::save( array( 'anthropic-legacy' => 'block', 'bytespider' => '' ) );
	$block = Access::block();
	t_ok( false !== strpos( $block, "User-agent: anthropic-ai\nUser-agent: Claude-Web\nDisallow: /" ) );
	t_ok( false === strpos( $block, 'Bytespider' ) );
	delete_option( Access::OPTION );
}

function test_access_manager_view_and_consequences() {
	delete_option( Access::OPTION );
	update_option( Robots::OPTION, array( 'body' => "User-agent: Google-Extended\nDisallow: /\n\nUser-agent: *\nAllow: /\n", 'source' => 'http', 'status' => 200, 'fetched_at' => time(), 'hash' => '' ), false );
	$v    = Access::view();
	$byid = array();
	foreach ( $v['groups'] as $g ) {
		foreach ( $g['bots'] as $b ) {
			$byid[ $b['id'] ] = $b + array( 'operator' => $g['operator'] );
		}
	}
	t_eq( 'OpenAI', $v['groups'][0]['operator'], 'grouped by operator, OpenAI first' );
	t_ok( $byid['google-extended']['blocked_elsewhere'], 'a block outside RankyFy is reported, not overridden' );
	t_ok( false !== strpos( $byid['google-extended']['elsewhere_rule'], 'Disallow: /' ) );
	t_ok( ! $byid['gptbot']['blocked_elsewhere'] );
	t_ok( ! $byid['gptbot']['serious'] && $byid['oai-searchbot']['serious'] && $byid['chatgpt-user']['serious'], 'training is not search' );
	t_ok( false !== strpos( $byid['oai-searchbot']['consequence'], 'ChatGPT' ) );
	t_ok( false !== strpos( $byid['google-extended']['consequence'], 'AI Overviews are unaffected' ) );
	t_ok( false !== strpos( $byid['bytespider']['consequence'], 'nothing visible' ), 'no search product, nothing to lose' );

	// Our own block is not "elsewhere".
	Access::save( array( 'gptbot' => 'block' ) );
	$body = (string) apply_filters( 'robots_txt', "User-agent: *\nAllow: /\n", '1' );
	update_option( Robots::OPTION, array( 'body' => $body, 'source' => 'http', 'status' => 200, 'fetched_at' => time(), 'hash' => '' ), false );
	foreach ( Access::view()['groups'][0]['bots'] as $b ) {
		if ( 'gptbot' === $b['id'] ) {
			t_ok( ! $b['blocked_elsewhere'] && 'block' === $b['choice'] );
		}
	}
	delete_option( Access::OPTION );
}

function test_crawler_pages_pivot_filters_and_detail() {
	t_crawl( 'gptbot', T_UA_GPTBOT, '198.51.100.0/24', '/pivot-a', 5 );
	t_crawl( 'claudebot', T_UA_CLAUDEBOT, '198.51.101.0/24', '/pivot-a', 2 );
	t_crawl( 'gptbot', T_UA_GPTBOT, '198.51.100.0/24', '/pivot-100%-b', 3 );
	t_crawl( 'claudebot', T_UA_CLAUDEBOT, '198.51.101.0/24', '/old-page-gone', 4, 404 );
	Aggregator::run( 20 );

	$all = Analytics::crawler_pages( array( 'days' => 30, 'bot' => '', 'status' => '', 'search' => '', 'page' => 1 ) );
	t_eq( 3, $all['total'] );
	t_eq( array( 'gptbot', 'claudebot' ), array_column( $all['columns'], 'id' ), 'columns: crawlers with most requests' );
	$a = $all['items'][0];
	t_eq( '/pivot-a', $a['path'] );
	t_eq( 7, $a['hits'] );
	t_eq( array( 'gptbot' => 5, 'claudebot' => 2 ), $a['counts'] );
	t_eq( 0, $a['other'] );

	$only = Analytics::crawler_pages( array( 'days' => 30, 'bot' => 'claudebot', 'status' => '', 'search' => '', 'page' => 1 ) );
	t_eq( array( '/pivot-a', '/old-page-gone' ), array_column( $only['items'], 'path' ), 'pages that bot read' );
	$nf = Analytics::crawler_pages( array( 'days' => 30, 'bot' => '', 'status' => '4xx', 'search' => '', 'page' => 1 ) );
	t_eq( array( '/old-page-gone' ), array_column( $nf['items'], 'path' ) );
	t_eq( 404, $nf['items'][0]['status'] );
	$s = Analytics::crawler_pages( array( 'days' => 30, 'bot' => '', 'status' => '', 'search' => '100%', 'page' => 1 ) );
	t_eq( 1, $s['total'], 'a "%" in the search is literal, and LIMIT still works' );

	$d = Analytics::url_detail( $a['hash'] );
	t_eq( 7, $d['total'] );
	t_eq( 2, count( $d['bots'] ) );
	t_eq( 'gptbot', $d['bots'][0]['id'] );
	t_ok( $d['first'] && 'live' === $d['recent'][0]['source'] );
	t_eq( 404, Analytics::url_detail( Util::url_hash( '/old-page-gone' ) )['status'] );
	t_ok( null === Analytics::url_detail( str_repeat( 'a', 32 ) ) );
}

function test_referrals_view_and_crawled_vs_cited() {
	global $wpdb;
	$read  = t_post( 'Referral read and cited', '<p>' . str_repeat( 'word ', 400 ) . '</p>' );
	$read2 = t_post( 'Referral read not cited', '<p>' . str_repeat( 'word ', 400 ) . '</p>' );
	$p1    = Util::normalize_path( get_permalink( $read ) );
	$p2    = Util::normalize_path( get_permalink( $read2 ) );
	t_crawl( 'gptbot', T_UA_GPTBOT, '198.51.100.0/24', $p1, 3 );
	t_crawl( 'gptbot', T_UA_GPTBOT, '198.51.100.0/24', $p2, 2 );
	Aggregator::run( 20 );
	foreach ( array( 'chatgpt' => 6, 'perplexity' => 2 ) as $src => $n ) {
		$wpdb->insert( Installer::table( 'referrals' ), array( 'day' => Util::day(), 'source' => $src, 'url_hash' => Util::url_hash( $p1 ), 'path' => $p1, 'hits' => $n ) );
	}
	Analytics::bust();
	$r = Analytics::referrals_view( array( 'days' => 30, 'engine' => '', 'search' => '', 'page' => 1 ) );
	t_eq( 8, $r['visits'] );
	t_eq( 'chatgpt', $r['engines'][0]['source'] );
	t_eq( 1, $r['total'] );
	t_eq( array( 'chatgpt' => 6, 'perplexity' => 2 ), $r['items'][0]['counts'] );
	t_eq( 3, $r['items'][0]['bot_hits'], 'bot hits beside the visits' );
	t_eq( 1, $r['cited']['cited'] );
	t_ok( $r['cited']['read_only'] >= 1, 'read but never cited' );
	t_eq( 0, Analytics::referrals_view( array( 'days' => 30, 'engine' => 'gemini', 'search' => '', 'page' => 1 ) )['total'], 'engine filter' );
	wp_delete_post( $read, true );
	wp_delete_post( $read2, true );
}

function test_llms_override_and_minimum_words() {
	global $wpdb;
	$long  = t_post( 'Llms long guide', '<p>' . str_repeat( 'Useful sentence about brewing. ', 120 ) . '</p>' );
	$short = t_post( 'Llms short stub', '<p>Tiny.</p>' );
	foreach ( array( $long => 600, $short => 2 ) as $id => $w ) {
		$wpdb->update( Installer::table( 'pages' ), array( 'word_count' => $w, 'analyzed_at' => time(), 'importance' => 50 ), array( 'object_type' => 'post', 'object_id' => $id ) );
	}
	Settings::update( array( 'llms_enabled' => true, 'llms_min_words' => 300 ) );
	$txt = Llms::build_llms();
	t_ok( false !== strpos( $txt, 'Llms long guide' ) && false === strpos( $txt, 'Llms short stub' ), 'minimum words' );
	Settings::update( array( 'llms_min_words' => 0 ) );
	t_ok( false !== strpos( Llms::build_llms(), 'Llms short stub' ) );

	Llms::set_override( "# My own file\n\n> Hand written.\n" );
	t_eq( "# My own file\n\n> Hand written.\n", Llms::override() );
	$res = wp_remote_get( home_url( '/llms.txt' ) );
	if ( ! is_wp_error( $res ) ) {
		t_eq( "# My own file\n\n> Hand written.\n", wp_remote_retrieve_body( $res ), 'the override is what is served' );
	}
	Llms::stale();
	Llms::rebuild();
	t_eq( "# My own file\n\n> Hand written.\n", Llms::override(), 'a rebuild never touches it' );
	t_ok( Llms::status()['override'] );
	Llms::set_override( '' );
	t_eq( '', Llms::override(), 'revert' );
	Settings::update( array( 'llms_enabled' => false ) );
	wp_delete_post( $long, true );
	wp_delete_post( $short, true );
}

function test_editor_redirect_choice() {
	$id  = t_post( 'Choice page', '<p>x</p>' );
	$old = get_permalink( $id );
	// Setting on, editor chose "Change anyway": no redirect, and the choice does not stick.
	Settings::update( array( 'guard_redirects' => true ) );
	Guard::set_choice( $id, 'no' );
	wp_update_post( array( 'ID' => $id, 'post_name' => 'choice-page-two' ) );
	t_ok( null === t_redirect_row( $old ), 'change anyway' );
	t_eq( false, get_transient( Guard::CHOICE . $id ), 'one answer, one save' );
	// Setting off, editor chose "Create 301 redirect".
	Settings::update( array( 'guard_redirects' => false ) );
	$old2 = get_permalink( $id );
	Guard::set_choice( $id, 'yes' );
	wp_update_post( array( 'ID' => $id, 'post_name' => 'choice-page-three' ) );
	t_ok( null !== t_redirect_row( $old2 ), 'created on request' );
	// Setting off, no choice: nothing.
	$old3 = get_permalink( $id );
	wp_update_post( array( 'ID' => $id, 'post_name' => 'choice-page-four' ) );
	t_ok( null === t_redirect_row( $old3 ) );
	Settings::update( array( 'guard_redirects' => true ) );
	wp_delete_post( $id, true );
}

function test_posts_list_sorts_by_ai_requests() {
	$a = t_post( 'Column busy', '<p>a</p>' );
	$b = t_post( 'Column quiet', '<p>b</p>' );
	t_crawl( 'gptbot', T_UA_GPTBOT, '198.51.100.0/24', Util::normalize_path( get_permalink( $a ) ), 4 );
	Aggregator::run( 20 );
	// The clauses the list screen's main query gets when sorted by the column (only in wp-admin).
	set_current_screen( 'edit-post' );
	$clauses = RankyfyAIB\Columns::sort( array( 'join' => '', 'orderby' => '' ), new class() {
		public function is_main_query() {
			return true;
		}
		public function get( $k ) {
			return 'orderby' === $k ? RankyfyAIB\Columns::KEY : 'DESC';
		}
	} );
	t_ok( false !== strpos( $clauses['join'], 'daily_pages' ) && 0 === strpos( $clauses['orderby'], 'COALESCE(rfaib_h.hits, 0) DESC' ) );
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} {$clauses['join']} WHERE {$wpdb->posts}.ID IN ({$a}, {$b}) ORDER BY {$clauses['orderby']}" ); // phpcs:ignore
	t_eq( array( (string) $a, (string) $b ), $ids, 'most-crawled first' );
	unset( $GLOBALS['current_screen'] );
	wp_delete_post( $a, true );
	wp_delete_post( $b, true );
}

function test_design_routes_permissions() {
	$a   = t_admin();
	$sub = wp_insert_user( array( 'user_login' => 'sub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	foreach ( array( array( 'GET', '/crawlers/pages' ), array( 'GET', '/referrals' ), array( 'GET', '/access' ), array( 'GET', '/compat' ) ) as $r ) {
		t_eq( 403, t_rest( $r[0], $r[1], array(), $sub )->get_status(), 'subscriber ' . $r[1] );
		t_eq( 200, t_rest( $r[0], $r[1], array(), $a )->get_status(), 'admin ' . $r[1] );
	}
	t_eq( 403, t_rest( 'POST', '/access', array( 'rules' => array( 'gptbot' => 'block' ) ), $sub )->get_status() );
	t_eq( 400, t_rest( 'POST', '/access', array( 'rules' => 'x' ), $a )->get_status() );
	t_eq( 404, t_rest( 'GET', '/crawlers/url/' . str_repeat( 'b', 32 ), array(), $a )->get_status() );
	t_eq( 400, t_rest( 'POST', '/redirects', array( 'source' => '/x', 'post_id' => 999999 ), $a )->get_status() );
	t_eq( 403, t_rest( 'POST', '/llms/override', array( 'text' => 'x' ), $sub )->get_status() );
	wp_delete_user( $sub );
	delete_option( Access::OPTION );
}
