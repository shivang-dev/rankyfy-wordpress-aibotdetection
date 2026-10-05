<?php
/**
 * Bootstrap: wire every component.
 *
 * @package RankyfyAIB
 */

namespace RankyfyAIB;

defined( 'ABSPATH' ) || exit;

class Plugin {

	public static function boot() {
		load_plugin_textdomain( 'rankyfy-ai-crawlers', false, dirname( plugin_basename( RFAIB_FILE ) ) . '/languages' );
		Installer::maybe_upgrade();

		Worker::init();
		Inventory::init();
		Rest::init();
		if ( is_admin() ) {
			Admin::init();
		}
		add_action(
			'init',
			static function () {
				Worker::schedule();
			}
		);
	}
}
