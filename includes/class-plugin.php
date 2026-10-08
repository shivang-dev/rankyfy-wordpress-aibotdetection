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
		load_plugin_textdomain( 'rankyfy-ai-seo', false, dirname( plugin_basename( RFY_FILE ) ) . '/languages' );
		Installer::maybe_upgrade();

		Worker::init();
		Inventory::init();
		Guard::init();
		Llms::init();
		Access::init();
		Rest::init();
		if ( is_admin() ) {
			Admin::init();
			Columns::init();
		}
		add_action(
			'init',
			static function () {
				Worker::schedule();
			}
		);
	}
}
