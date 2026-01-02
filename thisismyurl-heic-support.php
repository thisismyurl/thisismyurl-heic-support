<?php
/**
 * Author:              Christopher Ross
 * Author URI:          https://thisismyurl.com/?source=thisismyurl-heic-support
 * Plugin Name:         HEIC Support by thisismyurl.com
 * Plugin URI:          https://thisismyurl.com/thisismyurl-heic-support/?source=thisismyurl-heic-support
 * Donate link:         https://thisismyurl.com/donate/?source=thisismyurl-heic-support
 * 
 * Description:         Safely enable HEIC uploads and convert them to WebP or AVIF format with a centralized settings dashboard.
 * Tags:                heic, uploads, media library, conversion
 * 
 * Version:             1.260101
 * Requires at least:   5.3
 * Requires PHP:        7.4
 * 
 * Update URI:          https://github.com/thisismyurl/thisismyurl-heic-support
 * GitHub Plugin URI:   https://github.com/thisismyurl/thisismyurl-heic-support
 * Primary Branch:      main
 * Text Domain:         thisismyurl-heic-support
 * 
 * License:             GPL2
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * * @package TIMU_HEIC_Support
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function timu_heic_support_load_core() {
    $core_path = plugin_dir_path( __FILE__ ) . 'core/class-timu-core.php';
    if ( ! class_exists( 'TIMU_Core_v1' ) ) {
        require_once $core_path;
    }
}
timu_heic_support_load_core();

class TIMU_HEIC_Support extends TIMU_Core_v1 {

    public function __construct() {
        parent::__construct( 
            'thisismyurl-heic-support', 
            plugin_dir_url( __FILE__ ), 
            'timu_hs_settings_group', 
            '', 
            'tools.php'
        );

        add_action( 'init', array( $this, 'setup_plugin' ) );
        add_filter( 'upload_mimes', array( $this, 'allow_heic_uploads' ) );
        add_filter( 'wp_handle_upload', array( $this, 'handle_heic_upload' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

        register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );
    }

    public function activate_plugin_defaults() {
        $option_name = $this->plugin_slug . '_options';
        if ( false === get_option( $option_name ) ) {
            update_option( $option_name, array( 
                'enabled'       => 1,
                'target_format' => 'webp' // Default choice
            ) );
        }
    }

public function setup_plugin() {
    // Check for sibling plugin availability
    $webp_active = class_exists( 'TIMU_WebP_Support' );
    $avif_active = class_exists( 'TIMU_AVIF_Support' );

    // Build options dynamically based on active plugins
    $format_options = array(
        'none' => __( 'Upload as .heic files.', 'thisismyurl-heic-support' ),
    );

    if ( $webp_active ) {
        $format_options['webp'] = __( 'Convert to .webp file format.', 'thisismyurl-heic-support' );
    }

    if ( $avif_active ) {
        $format_options['avif'] = __( 'Convert to .avif file format.', 'thisismyurl-heic-support' );
    }

    $blueprint = array(
        'config' => array(
            'title'  => __( 'HEIC Configuration', 'thisismyurl-heic-support' ),
            'fields' => array(
                'enabled' => array(
                    'type'      => 'switch',
                    'label'     => __( 'Enable HEIC Uploads', 'thisismyurl-heic-support' ),
                    'desc'      => __( 'Allows .heic files in the Media Library.', 'thisismyurl-heic-support' ),
                    'is_parent' => true,
                    'default'   => 1
                ),
                'target_format' => array(
                    'type'      => 'radio',
                    'label'     => __( 'Conversion Format', 'thisismyurl-heic-support' ),
                    'parent'    => 'enabled',
                    'is_parent' => true,
                    'options'   => $format_options,
                    'default'   => $webp_active ? 'webp' : 'none',
                    'desc'      => ( !$webp_active || !$avif_active ) 
                                   ? __( 'Install  <a href="https://thisismyurl.com/thisismyurl-webp-support/">WebP Support</a> or  <a href="https://thisismyurl.com/thisismyurl-avif-support/">AVIF Support</a> plugins to enable the formats.', 'thisismyurl-heic-support' ) 
                                   : ''
                ),
                'webp_quality' => array(
                    'type'         => 'number',
                    'label'        => __( 'WebP Quality', 'thisismyurl-heic-support' ),
                    'parent'       => 'target_format',
                    'parent_value' => 'webp',
                    'default'      => 80
                ),
                'avif_quality' => array(
                    'type'         => 'number',
                    'label'        => __( 'AVIF Quality', 'thisismyurl-heic-support' ),
                    'parent'       => 'target_format',
                    'parent_value' => 'avif',
                    'default'      => 60
                ),
            )
        )
    );

    $this->init_settings_generator( $blueprint );
}

    public function allow_heic_uploads( $mimes ) {
        if ( 1 == $this->get_plugin_option( 'enabled', 1 ) ) {
            $mimes['heic'] = 'image/heic';
            $mimes['heif'] = 'image/heif';
        }
        return $mimes;
    }

    public function add_admin_menu() {
        add_management_page(
            __( 'HEIC Support', 'thisismyurl-heic-support' ),
            __( 'HEIC Support', 'thisismyurl-heic-support' ),
            'manage_options',
            $this->plugin_slug,
            array( $this, 'render_settings_page' )
        );
    }

    public function handle_heic_upload( $upload ) {
        $target = $this->get_plugin_option( 'target_format', 'webp' );
        
        if ( 'none' === $target || 1 != $this->get_plugin_option( 'enabled', 1 ) ) {
            return $upload;
        }

        if ( in_array( $upload['type'], array( 'image/heic', 'image/heif' ) ) ) {
            // Check $target (webp or avif) and run specific Imagick logic here
        }

        return $upload;
    }
}

new TIMU_HEIC_Support();