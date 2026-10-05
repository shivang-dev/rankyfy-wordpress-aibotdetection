<?php
/**
 * Bot detection, classification, verification and false positives.
 */

use RankyfyAIB\Detector;
use RankyfyAIB\Registry;
use RankyfyAIB\Ranges;

function t_match( $ua, $ip = '', $sig = '' ) {
	return Detector::match( $ua, Registry::matcher(), $ip, $sig );
}

function test_known_ai_crawlers_are_identified() {
	$cases = array(
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot' => 'gptbot',
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36; compatible; OAI-SearchBot/1.4; +https://openai.com/searchbot' => 'oai-searchbot',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot' => 'chatgpt-user',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' => 'claudebot',
		'Mozilla/5.0 (compatible; Claude-SearchBot/1.0)' => 'claude-searchbot',
		'Mozilla/5.0 (compatible; Claude-User/1.0)' => 'claude-user',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)' => 'perplexitybot',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)' => 'perplexity-user',
		'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)' => 'meta-externalagent',
		'CCBot/2.0 (https://commoncrawl.org/faq/)' => 'ccbot',
		'Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)' => 'bytespider',
		'Mozilla/5.0 (compatible; MistralAI-User/1.0; +https://docs.mistral.ai/robots)' => 'mistralai-user',
		'Mozilla/5.0 (compatible; Google-CloudVertexBot; +https://cloud.google.com)' => 'google-cloudvertexbot',
		'Mozilla/5.0 (compatible; DuckAssistBot/1.2; +http://duckduckgo.com/duckassistbot.html)' => 'duckassistbot',
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)' => 'applebot',
	);
	foreach ( $cases as $ua => $id ) {
		$r = t_match( $ua );
		t_eq( $id, $r['bot'], $ua );
	}
	t_eq( 'ai', t_match( 'GPTBot/1.4' )['cls'] );
	t_eq( 'search', t_match( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' )['cls'] );
	t_eq( 'known', t_match( 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)' )['cls'] );
	t_eq( 'known', t_match( 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' )['cls'] );
}

function test_matching_is_case_insensitive_and_prefers_ai_tokens() {
	t_eq( 'gptbot', t_match( 'mozilla/5.0 (compatible; gptbot/1.2)' )['bot'] );
	// Both a search token and an AI token: the AI crawler wins.
	t_eq( 'googleother', t_match( 'Mozilla/5.0 (compatible; Googlebot/2.1) GoogleOther' )['bot'] );
	// Longest token wins at the same position.
	t_eq( 'claude-searchbot', t_match( 'Claude-SearchBot/1.0' )['bot'] );
}

function test_robots_only_tokens_never_match_requests() {
	t_ok( 'google-extended' !== t_match( 'Mozilla/5.0 (compatible; Google-Extended)' )['bot'], 'Google-Extended never visits, so it is never matched' );
	foreach ( array( 'google-extended', 'applebot-extended' ) as $id ) {
		t_ok( Registry::get( $id )['robots_only'], "$id is robots-only" );
		t_eq( array(), Registry::get( $id )['patterns'] );
	}
}

function test_browsers_are_not_bots() {
	$browsers = array(
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0',
		'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
		'Mozilla/5.0 (X11; Linux x86_64; rv:142.0) Gecko/20100101 Firefox/142.0',
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
		'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36',
		'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36',
		'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/500.0]',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 OPR/120.0.0.0',
	);
	foreach ( $browsers as $ua ) {
		t_eq( 'human', t_match( $ua )['cls'], $ua );
	}
}

function test_unregistered_and_potential_ai_bots() {
	t_eq( 'unknown', t_match( 'python-requests/2.32.3' )['cls'] );
	t_eq( 'unknown', t_match( 'curl/8.5.0' )['cls'] );
	t_eq( 'unknown', t_match( '' )['cls'], 'empty user agent' );
	t_eq( 'unknown', t_match( 'Mozilla/5.0' )['cls'], 'bare Mozilla/5.0' );
	t_eq( 'unknown', t_match( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/139.0.0.0 Safari/537.36' )['cls'] );
	t_eq( 'potential', t_match( 'SuperNewAIBot/0.1 (+https://example.ai/bot)' )['cls'] );
	t_eq( 'potential', t_match( 'Mozilla/5.0 (compatible; ExampleLLM-crawler/1.0)' )['cls'] );
	t_eq( 'potential', t_match( 'my-rag-agent/2.0 python-httpx' )['cls'] );
}

function test_address_only_agent_is_verified_by_range() {
	t_ranges( 'google-agent', array( '192.0.2.0/24' ) );
	Ranges::compile_ip_only();
	$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
	$r      = t_match( $chrome, '192.0.2.44' );
	t_eq( 'google-agent', $r['bot'] );
	t_ok( ! empty( $r['by_address'] ) );
	t_eq( 'verified', Detector::verify( $r, '192.0.2.44' )['vstate'] );
	t_eq( 'human', t_match( $chrome, '198.51.100.7' )['cls'] );
}

function test_signed_agent_is_queued_for_signature_check() {
	$r = t_match( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '', '"https://chatgpt.com"' );
	t_eq( 'chatgpt-agent', $r['bot'] );
	t_eq( array( 'vstate' => 'pending', 'needs' => 'sig' ), Detector::verify( $r, '203.0.113.5' ) );
	$u = t_match( 'Mozilla/5.0 Chrome/140', '', 'https://agents.example.net' );
	t_eq( '_signed', $u['bot'] );
}

function test_verification_by_published_ranges() {
	t_ranges( 'gptbot', array( '20.171.206.0/24', '2a01:111:f402::/48' ) );
	$r = t_match( 'GPTBot/1.4' );
	t_eq( 'verified', Detector::verify( $r, '20.171.206.9' )['vstate'] );
	t_eq( 'verified', Detector::verify( $r, '2a01:111:f402:12::1' )['vstate'] );
	t_eq( 'failed', Detector::verify( $r, '198.51.100.7' )['vstate'], 'fresh ranges, outside: impersonation' );
	t_ranges( 'gptbot', array( '20.171.206.0/24' ), 30 * DAY_IN_SECONDS );
	t_eq( 'none', Detector::verify( t_match( 'GPTBot/1.4' ), '198.51.100.7' )['vstate'], 'stale ranges never prove an impersonation' );
	t_eq( 'none', Detector::verify( t_match( 'meta-externalagent/1.1' ), '198.51.100.7' )['vstate'], 'operator publishes nothing' );
}

function test_rdns_bots_are_queued_then_cached() {
	t_ranges( 'googlebot', array( '66.249.64.0/27' ) );
	$r = t_match( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
	t_eq( 'verified', Detector::verify( $r, '66.249.64.5' )['vstate'] );
	t_eq( array( 'vstate' => 'pending', 'needs' => 'rdns' ), Detector::verify( $r, '66.249.90.1' ) );
}

function test_reverse_dns_verdicts() {
	$fwd = static function ( $pre, $host ) {
		return array( 'crawl-66-249-90-1.googlebot.com' => array( '66.249.90.1' ), 'evil.example.com' => array( '66.249.90.2' ), 'crawl-66-249-90-3.googlebot.com' => array( '10.0.0.1' ) )[ $host ] ?? array();
	};
	$rev = static function ( $pre, $ip ) {
		return array( '66.249.90.1' => 'crawl-66-249-90-1.googlebot.com', '66.249.90.2' => 'evil.example.com', '66.249.90.3' => 'crawl-66-249-90-3.googlebot.com', '66.249.90.4' => '' )[ $ip ] ?? null;
	};
	add_filter( 'rfaib_forward_dns', $fwd, 10, 2 );
	add_filter( 'rfaib_reverse_dns', $rev, 10, 2 );
	try {
		t_eq( 'verified', RankyfyAIB\Verifier::check_rdns( 'googlebot', '66.249.90.1' )[0] );
		t_eq( 'failed', RankyfyAIB\Verifier::check_rdns( 'googlebot', '66.249.90.2' )[0], 'wrong domain' );
		t_eq( 'failed', RankyfyAIB\Verifier::check_rdns( 'googlebot', '66.249.90.3' )[0], 'forward lookup does not confirm' );
		t_eq( 'failed', RankyfyAIB\Verifier::check_rdns( 'googlebot', '66.249.90.4' )[0], 'no PTR record' );
		// Through the queue: pending events become verified and the verdict is cached.
		t_hit( 'Mozilla/5.0 (compatible; Googlebot/2.1)', '66.249.90.1', '/a/' );
		RankyfyAIB\Settings::update( array( 'track_search_pages' => true ) );
		t_hit( 'Mozilla/5.0 (compatible; Googlebot/2.1)', '66.249.90.1', '/b/' );
		t_hit( 'Mozilla/5.0 (compatible; Googlebot/2.1)', '66.249.90.2', '/c/' );
		RankyfyAIB\Verifier::run( 5 );
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT path, cls, vstate FROM ' . RankyfyAIB\Installer::table( 'events' ) . ' ORDER BY id', ARRAY_A );
		t_eq( array( 'path' => '/b', 'cls' => 'search', 'vstate' => 'verified' ), $rows[0] );
		t_eq( array( 'path' => '/c', 'cls' => 'spoofed', 'vstate' => 'failed' ), $rows[1] );
		t_eq( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . RankyfyAIB\Installer::table( 'verify_queue' ) ), 'queue drained (full addresses discarded)' );
		RankyfyAIB\Verifier::prune();
		t_eq( 'verified', RankyfyAIB\Verifier::cached( 'googlebot', RankyfyAIB\Util::ip_hash( '66.249.90.1' ) ) );
	} finally {
		remove_filter( 'rfaib_forward_dns', $fwd, 10 );
		remove_filter( 'rfaib_reverse_dns', $rev, 10 );
	}
}

function test_web_bot_auth_signatures() {
	$kp  = sodium_crypto_sign_keypair();
	$pk  = sodium_crypto_sign_publickey( $kp );
	$sk  = sodium_crypto_sign_secretkey( $kp );
	$x   = rtrim( strtr( base64_encode( $pk ), '+/', '-_' ), '=' );
	$kid = RankyfyAIB\Verifier::jwk_thumbprint( $x );
	$keys = static function ( $pre, $host ) use ( $kid, $pk ) {
		return 'chatgpt.com' === $host ? array( $kid => base64_encode( $pk ) ) : array();
	};
	add_filter( 'rfaib_signature_keys', $keys, 10, 2 );
	try {
		$now    = time();
		$params = '("@authority" "signature-agent");created=' . ( $now - 5 ) . ';expires=' . ( $now + 60 ) . ';keyid="' . $kid . '";alg="ed25519";tag="web-bot-auth"';
		$req    = array( '@authority' => 'shop.example', 'signature-agent' => '"https://chatgpt.com"' );
		$base   = "\"@authority\": shop.example\n\"signature-agent\": \"https://chatgpt.com\"\n\"@signature-params\": " . $params;
		$sig    = base64_encode( sodium_crypto_sign_detached( $base, $sk ) );
		$row    = static function ( array $over = array() ) use ( $req, $params, $sig, $now ) {
			return array( 'payload' => wp_json_encode( array_merge( $req, array( 'signature-input' => 'sig1=' . $params, 'signature' => 'sig1=:' . $sig . ':' ), $over ) ), 'created_at' => $now );
		};
		t_eq( 'verified', RankyfyAIB\Verifier::check_signature( $row() )[0] );
		t_eq( 'failed', RankyfyAIB\Verifier::check_signature( $row( array( '@authority' => 'other.example' ) ) )[0], 'signature over a different host' );
		$late = $row();
		$late['created_at'] = $now + 3600;
		t_eq( 'failed', RankyfyAIB\Verifier::check_signature( $late )[0], 'expired signature' );
		t_eq( 'none', RankyfyAIB\Verifier::check_signature( $row( array( 'signature-agent' => '"https://unknown.example"' ) ) )[0], 'no published key: cannot decide' );
		t_eq( 'none', RankyfyAIB\Verifier::check_signature( $row( array( 'signature-agent' => '"http://chatgpt.com"' ) ) )[0], 'key directory must be https' );
		t_eq( 'none', RankyfyAIB\Verifier::check_signature( array( 'payload' => '{}', 'created_at' => $now ) )[0] );
	} finally {
		remove_filter( 'rfaib_signature_keys', $keys, 10 );
	}
}

function test_trusted_proxy_header_cannot_be_spoofed() {
	$_SERVER['REMOTE_ADDR']           = '203.0.113.9';
	$_SERVER['HTTP_X_FORWARDED_FOR']  = '20.171.206.9';
	RankyfyAIB\Settings::update( array( 'proxy_header' => 'x-forwarded-for', 'trusted_proxies' => '' ) );
	t_eq( '203.0.113.9', RankyfyAIB\Util::client_ip(), 'header ignored without trusted proxies' );
	RankyfyAIB\Settings::update( array( 'trusted_proxies' => "10.0.0.0/8\n203.0.113.0/24" ) );
	t_eq( '20.171.206.9', RankyfyAIB\Util::client_ip(), 'header honoured from a trusted proxy' );
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 20.171.206.9, 10.1.1.1';
	t_eq( '20.171.206.9', RankyfyAIB\Util::client_ip(), 'right-most untrusted address' );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
	t_eq( '198.51.100.1', RankyfyAIB\Util::client_ip(), 'untrusted connection: header ignored' );
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
}

function test_registry_validation_and_custom_bots() {
	t_ok( null === Registry::validate_bot( array( 'id' => 'Bad Id', 'patterns' => array( 'x' ) ) ) );
	t_ok( null === Registry::validate_bot( array( 'id' => 'nopattern' ) ), 'unrecognisable entry dropped' );
	$b = Registry::validate_bot( array( 'id' => 'x-bot', 'patterns' => array( "evil\nline", 'XBot' ), 'category' => 'nope', 'verify' => array( 'ranges' => array( 'http://insecure.example/x.json', 'https://ok.example/r.json' ) ) ) );
	t_eq( array( 'XBot' ), $b['patterns'], 'control characters rejected' );
	t_eq( 'other', $b['category'] );
	t_eq( array( 'https://ok.example/r.json' ), $b['verify']['ranges'], 'only https range files' );
	t_ok( false === Registry::store_remote( array( 'bots' => array( array( 'id' => 'a1', 'patterns' => array( 'A' ) ) ) ) ), 'a registry that would drop most crawlers is refused' );
	$res = Registry::save_custom( array( 'id' => 'novabot', 'name' => 'NovaBot', 'category' => 'ai_search', 'patterns' => array( 'NovaLLM-Crawler' ) ) );
	t_ok( ! is_wp_error( $res ) );
	t_eq( 'novabot', t_match( 'NovaLLM-Crawler/0.9 (+https://nova.example/bot)' )['bot'] );
	t_eq( 'ai', t_match( 'NovaLLM-Crawler/0.9' )['cls'] );
	Registry::delete_custom( 'novabot' );
	t_eq( 'potential', t_match( 'NovaLLM-Crawler/0.9' )['cls'] );
}

function test_matcher_stays_small_and_fast() {
	$m = get_option( Registry::MATCHER );
	t_ok( strlen( serialize( $m ) ) < 16000, 'autoloaded matcher stays small' );
	$uas = array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'GPTBot/1.4', 'python-requests/2.32' );
	$t0  = hrtime( true );
	for ( $i = 0; $i < 30000; $i++ ) {
		Detector::match( $uas[ $i % 3 ], $m );
	}
	$per = ( hrtime( true ) - $t0 ) / 30000 / 1000;
	printf( "       classification: %.2f µs per request\n", $per );
	t_ok( $per < 50, 'classification under 50 µs' );
}
