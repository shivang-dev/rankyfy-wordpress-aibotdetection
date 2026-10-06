<?php
/**
 * Plugin Name:       RankyFy AI Crawler Monitor
 * Plugin URI:        https://rankyfy.com/
 * Description:       See which AI crawlers and assistants visit your site, what they read and what they miss, which pages are blocked from them, and what to change so AI search can find and cite your content.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            RankyFy
 * License:           GPL-2.0-or-later
 * Text Domain:       rankyfy-ai-crawlers
 *
 * @package RankyfyAIB
 */

defined( 'ABSPATH' ) || exit;

define( 'RFAIB_VERSION', '1.0.0' );
define( 'RFAIB_DB_VERSION', '4' );
define( 'RFAIB_FILE', __FILE__ );
define( 'RFAIB_DIR', plugin_dir_path( __FILE__ ) );
define( 'RFAIB_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'RankyfyAIB\\' ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, strlen( 'RankyfyAIB\\' ) ) ) );
		$file = RFAIB_DIR . 'includes/class-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'RankyfyAIB\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RankyfyAIB\\Installer', 'deactivate' ) );

// Classify the request as early as possible: for an ordinary visitor this is
// one regular-expression test and nothing else, so it adds no measurable cost.
RankyfyAIB\Tracker::boot();

add_action( 'plugins_loaded', array( 'RankyfyAIB\\Plugin', 'boot' ) );
