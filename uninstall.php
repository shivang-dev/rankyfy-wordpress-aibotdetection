<?php
/**
 * Uninstall: remove every table, option and transient the plugin created.
 * Content is never touched (the plugin never changes content).
 *
 * @package RankyfyAIB
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

define( 'RFY_DIR', plugin_dir_path( __FILE__ ) );
require_once RFY_DIR . 'includes/class-installer.php';

wp_clear_scheduled_hook( 'rfy_tick' );

RankyfyAIB\Installer::drop();

global $wpdb;
// Options and transients all carry the plugin's prefix.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rfy\\_%' OR option_name LIKE '\\_transient\\_rfy\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rfy\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_cache_flush();
