<?php
/**
 * robots.txt evaluation (RFC 9309) and the per-crawler matrix.
 */

use RankyfyAIB\Robots;

function t_allowed( $txt, $token, $path ) {
	return Robots::check( Robots::parse( $txt ), $token, $path )['allowed'];
}

function test_robots_group_selection() {
	$txt = "User-agent: *\nDisallow: /private/\n\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: Googlebot\nUser-agent: Bingbot\nDisallow: /search\n";
	t_ok( ! t_allowed( $txt, 'GPTBot', '/blog/' ), 'specific group applies' );
	t_ok( t_allowed( $txt, 'ClaudeBot', '/blog/' ), 'falls back to *' );
	t_ok( ! t_allowed( $txt, 'ClaudeBot', '/private/x' ) );
	t_ok( t_allowed( $txt, 'Googlebot', '/private/x' ), 'a matching group replaces * entirely' );
	t_ok( ! t_allowed( $txt, 'bingbot', '/search?q=1' ), 'case-insensitive, grouped user-agent lines' );
	t_ok( ! t_allowed( $txt, 'Googlebot-News', '/search' ), 'product token falls back to its family group' );
	t_ok( t_allowed( "User-agent: *\nDisallow:\n", 'GPTBot', '/' ), 'empty disallow allows everything' );
	t_ok( t_allowed( '', 'GPTBot', '/' ), 'no robots.txt: allowed' );
}

function test_robots_longest_match_and_wildcards() {
	$txt = "User-agent: *\nDisallow: /shop/\nAllow: /shop/products/\nDisallow: /*.pdf$\nDisallow: /tmp*\nAllow: /page\nDisallow: /page\n# comment\nDisallow: /x # trailing comment\n";
	t_ok( t_allowed( $txt, 'GPTBot', '/shop/products/mug' ), 'longer allow wins' );
	t_ok( ! t_allowed( $txt, 'GPTBot', '/shop/cart' ) );
	t_ok( ! t_allowed( $txt, 'GPTBot', '/files/guide.pdf' ), '$ anchor' );
	t_ok( t_allowed( $txt, 'GPTBot', '/files/guide.pdf?download=1' ), '$ requires end of path' );
	t_ok( ! t_allowed( $txt, 'GPTBot', '/tmpfile' ), '* wildcard' );
	t_ok( t_allowed( $txt, 'GPTBot', '/page' ), 'allow wins a tie' );
	t_ok( ! t_allowed( $txt, 'GPTBot', '/x/y' ), 'comments stripped' );
	$r = Robots::check( Robots::parse( $txt ), 'GPTBot', '/shop/cart' );
	t_eq( 'Disallow: /shop/', $r['rule'] );
}

function test_robots_matrix_and_alert_on_change() {
	$prev = Robots::build_matrix( "User-agent: *\nAllow: /\n" );
	$now  = Robots::build_matrix( "User-agent: OAI-SearchBot\nDisallow: /\n\nUser-agent: GPTBot\nDisallow: /\n" );
	t_ok( ! $now['bots']['oai-searchbot']['site_allowed'] );
	t_ok( $now['bots']['claudebot']['site_allowed'] );
	t_ok( isset( $now['bots']['google-extended'] ), 'robots-only tokens are evaluated too' );
	RankyfyAIB\Alerts::robots_changed( $prev, $now );
	global $wpdb;
	$a = $wpdb->get_row( 'SELECT * FROM ' . RankyfyAIB\Installer::table( 'alerts' ), ARRAY_A );
	t_eq( 'critical', $a['severity'], 'blocking an AI search crawler is critical' );
	t_ok( false !== strpos( $a['title'], 'OAI-SearchBot' ) );
	t_ok( $a['why'] && $a['action'], 'alerts say why and what to do' );
}
