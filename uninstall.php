<?php
/**
 * Uninstall: remove every table, option and transient the plugin created.
 * Content is never touched (the plugin never changes content).
 *
 * @package RankyfyAIB
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

define( 'RFYAI_DIR', plugin_dir_path( __FILE__ ) );
require_once RFYAI_DIR . 'includes/class-installer.php';

wp_clear_scheduled_hook( 'rfyai_tick' );

RankyfyAIB\Installer::drop();

global $wpdb;
// Options and transients all carry the plugin's prefix.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rfyai\\_%' OR option_name LIKE '\\_transient\\_rfyai\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rfyai\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_cache_flush();
