<?php
/**
 * Uninstaller for HEIC Support by thisismyurl.com.
 *
 * @package TIMU_HEIC_Support
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wp_filesystem;
if ( empty( $wp_filesystem ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
}

$upload_dir = wp_upload_dir();
$backup_dir = $upload_dir['basedir'] . '/heic-backups/';
$options    = get_option( 'timu_heic_support_options', array() );

if ( ! empty( $options['delete_backups_uninstall'] ) && $wp_filesystem && $wp_filesystem->exists( $backup_dir ) ) {
    $wp_filesystem->delete( $backup_dir, true );
}

delete_metadata( 'post', 0, '_heic_original_path', '', true );
delete_metadata( 'post', 0, '_heic_savings', '', true );
delete_option( 'timu_heic_support_options' );
wp_cache_flush();
