<?php
/**
 * HEIC Support by thisismyurl.com - Uninstaller
 * This script runs automatically when a user deletes the plugin via the WordPress dashboard.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Determine the plugin slug.
 * The core uses this slug to namespace all database entries.
 */
$plugin_slug = 'thisismyurl-heic-support';

// 1. Delete the primary plugin options
// These include the 'enabled' toggle and the registration key.
delete_option( $plugin_slug . '_options' );

// 2. Delete licensing and status transients
// The core library caches license status and messages here.
delete_transient( $plugin_slug . '_license_status' );
delete_transient( $plugin_slug . '_license_msg' );

// 3. Clear the shared tools cache
// This ensures the "Other Tools" sidebar is refreshed for any other active TIMU plugins.
delete_transient( 'timu_tools_cache' );

/**
 * NOTE: This plugin does not currently store specific post metadata.
 * Files uploaded while the plugin was active remain in the Media Library, 
 * but the automated conversion filtering will cease once the plugin is removed.
 */