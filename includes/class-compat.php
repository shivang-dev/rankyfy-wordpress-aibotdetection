<?php
/**
 * What else is installed, and who handles what.
 *
 * Detection is by the constants and classes the plugins define themselves,
 * so it costs nothing and needs no request. The table it produces is shown
 * under Settings → Compatibility and named wherever it matters (robots.txt,
 * the editor sidebar), so the division of labour is visible.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Compat {

	/** Active SEO plugins, by name. */
	public static function seo_plugins() {
		$out = array();
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$out[] = 'Rank Math';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$out[] = 'Yoast SEO';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$out[] = 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$out[] = 'SEOPress';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$out[] = 'The SEO Framework';
		}
		if ( class_exists( '\Rankyfy\Auth' ) ) {
			$out[] = 'RankyFy SEO';
		}
		return $out;
	}

	/** Page caches and CDNs: their cached pages never reach the WordPress hook. */
	public static function caches() {
		$out = array();
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$out[] = 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) ) {
			$out[] = 'LiteSpeed Cache';
		}
		if ( defined( 'W3TC' ) ) {
			$out[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wp_cache_phase2' ) || defined( 'WPCACHEHOME' ) ) {
			$out[] = 'WP Super Cache';
		}
		if ( defined( 'WPFC_WP_CONTENT_BASENAME' ) || class_exists( 'WpFastestCache' ) ) {
			$out[] = 'WP Fastest Cache';
		}
		if ( defined( 'BREEZE_VERSION' ) ) {
			$out[] = 'Breeze';
		}
		if ( defined( 'SiteGround_Optimizer\VERSION' ) || class_exists( 'SiteGround_Optimizer\Loader\Loader' ) ) {
			$out[] = 'SiteGround Optimizer';
		}
		if ( Util::cloudflare_seen() ) {
			$out[] = 'Cloudflare';
		}
		return $out;
	}

	public static function redirect_plugins() {
		$out = array();
		if ( defined( 'REDIRECTION_VERSION' ) ) {
			$out[] = 'Redirection';
		}
		if ( defined( 'RANK_MATH_VERSION' ) && get_option( 'rank_math_modules' ) && in_array( 'redirections', (array) get_option( 'rank_math_modules' ), true ) ) {
			$out[] = 'Rank Math';
		}
		return $out;
	}

	/**
	 * Output → who handles it.
	 *
	 * @return array<int,array{output:string,owner:string,ours:bool,note:string}>
	 */
	public static function table() {
		$seo   = self::seo_plugins();
		$main  = $seo ? $seo[0] : '';
		$rows  = array();
		$add   = static function ( $output, $owner, $ours, $note ) use ( &$rows ) {
			$rows[] = array( 'output' => $output, 'owner' => $owner, 'ours' => $ours, 'note' => $note );
		};
		$defer = __( 'Leaves it to them', 'rankyfy-ai-crawlers' );
		$add( __( 'Title & meta description', 'rankyfy-ai-crawlers' ), $main ? $main : __( 'Your theme', 'rankyfy-ai-crawlers' ), false, $defer );
		$add( __( 'Open Graph / social cards', 'rankyfy-ai-crawlers' ), $main ? $main : __( 'Not found', 'rankyfy-ai-crawlers' ), false, $main ? $defer : __( 'Not handled — install an SEO plugin', 'rankyfy-ai-crawlers' ) );
		$add( __( 'XML sitemap', 'rankyfy-ai-crawlers' ), $main ? $main : 'WordPress', false, __( 'Checks it is listed in robots.txt', 'rankyfy-ai-crawlers' ) );
		$add( __( 'Article & author schema', 'rankyfy-ai-crawlers' ), $main ? $main : __( 'Not found', 'rankyfy-ai-crawlers' ), false, __( 'Checks pages for gaps (Readiness Score)', 'rankyfy-ai-crawlers' ) );
		$add( 'robots.txt', Access::robots_writer(), false, __( 'Adds AI crawler rules only (Access Manager)', 'rankyfy-ai-crawlers' ) );
		$redirects = self::redirect_plugins();
		$add( __( 'Redirects for changed URLs', 'rankyfy-ai-crawlers' ), $redirects ? implode( ', ', $redirects ) : 'RankyFy', ! $redirects, $redirects ? __( 'Also adds its own for published URLs that change', 'rankyfy-ai-crawlers' ) : __( 'RankyFy handles', 'rankyfy-ai-crawlers' ) );
		$add( 'llms.txt / ai.txt', 'RankyFy', true, __( 'RankyFy handles', 'rankyfy-ai-crawlers' ) );
		$add( __( 'AI crawler tracking', 'rankyfy-ai-crawlers' ), 'RankyFy', true, __( 'RankyFy handles', 'rankyfy-ai-crawlers' ) );
		return $rows;
	}

	public static function view() {
		$seo = self::seo_plugins();
		return array(
			'seo'       => $seo,
			'caches'    => self::caches(),
			'redirects' => self::redirect_plugins(),
			'table'     => self::table(),
			'warning'   => count( array_diff( $seo, array( 'RankyFy SEO' ) ) ) > 1,
		);
	}
}
