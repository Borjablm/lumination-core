<?php
/**
 * Lumination Core Uninstall
 *
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes Core options and the shared analytics table.
 *
 * NOTE: Extensions have their own uninstall.php files that remove their
 * own options. Core's table is removed last (here), so extensions should
 * be deactivated first if clean removal of all data is desired.
 *
 * @package    LuminationCore
 * @since      1.0.0
 * @license    GPL-3.0-or-later
 */

// Only run when WordPress itself triggers uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove Core options.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- uninstall.php; prefix avoids collision with other uninstall scripts.
$lumination_core_options = array(
	'lumination_api_key',
	'lumination_api_base_url',
	'lumination_core_db_version',
	'lumination_core_activated',
	'lumination_primary_color',
	'lumination_primary_hover_color',
	'lumination_button_text_color',
	'lumination_tool_background_color',
);
foreach ( $lumination_core_options as $lumination_core_option ) {
	delete_option( $lumination_core_option );
}

// Drop the shared analytics table.
// Table name is built from $wpdb->prefix — no user input involved.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$lumination_core_table = $wpdb->prefix . 'lumination_usage';
$wpdb->query( "DROP TABLE IF EXISTS `{$lumination_core_table}`" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
