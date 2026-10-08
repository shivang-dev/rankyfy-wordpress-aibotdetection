<?php
/**
 * Plugin Name:       RankyFy AI SEO
 * Plugin URI:        https://rankyfy.com/
 * Description:       See which AI crawlers and assistants read your site, where AI traffic lands, and what keeps your content out of AI search — beside your SEO plugin.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            RankyFy
 * Author URI:        https://rankyfy.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rankyfy-ai-seo
 *
 * @package RankyfyAIB
 */

defined( 'ABSPATH' ) || exit;

define( 'RFY_VERSION', '1.1.0' );
define( 'RFY_DB_VERSION', '4' );
define( 'RFY_FILE', __FILE__ );
define( 'RFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'RFY_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'RankyfyAIB\\' ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, strlen( 'RankyfyAIB\\' ) ) ) );
		$file = RFY_DIR . 'includes/class-' . $name . '.php';
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
