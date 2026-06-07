<?php
/**
 * NPX Menu Icons — Uninstall
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes all icon meta saved against nav menu item posts.
 *
 * @package NPX_Menu_Icons
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove all nav menu icon meta entries.
$wpdb->delete(
	$wpdb->postmeta,
	array( 'meta_key' => '_npx_menu_icon' ),
	array( '%s' )
);
