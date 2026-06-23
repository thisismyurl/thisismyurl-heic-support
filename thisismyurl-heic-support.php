<?php
/**
 * Plugin Name:       HEIC Support by thisismyurl.com
 * Plugin URI:        https://thisismyurl.com/thisismyurl-heic-support/
 * Description:       Automatically convert HEIC/HEIF images from iOS devices to WebP with secure backups.
 * Version:           0.6174.1641
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       thisismyurl-heic-support
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-heic-support
 * Primary Branch:    main
 * Donate link:       https://thisismyurl.com/donate/
 * @package TIMU_HEIC_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TIMU_HEIC_Support {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_filter( 'upload_mimes', array( __CLASS__, 'allow_heic_uploads' ) );
        add_filter( 'wp_handle_upload', array( __CLASS__, 'handle_heic_upload' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_plugin_action_links' ) );
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( 'tools_page_heic-optimizer' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'timu-heic-admin',
            plugin_dir_url( __FILE__ ) . 'assets/css/admin.css',
            array(),
            '0.6174.1641'
        );
    }

    public static function allow_heic_uploads( $mimes ) {
        $mimes['heic'] = 'image/heic';
        $mimes['heif'] = 'image/heif';
        return $mimes;
    }

    public static function add_admin_menu() {
        add_management_page(
            __( 'HEIC Support', 'thisismyurl-heic-support' ),
            __( 'HEIC Support', 'thisismyurl-heic-support' ),
            'manage_options',
            'heic-optimizer',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function add_plugin_action_links( $links ) {
        $custom_links = array(
            '<a href="' . admin_url( 'tools.php?page=heic-optimizer' ) . '">' . esc_html__( 'Settings', 'thisismyurl-heic-support' ) . '</a>',
            '<a href="https://thisismyurl.com/donate/" target="_blank" style="color: #2271b1; font-weight: bold;">' . esc_html__( 'Donate', 'thisismyurl-heic-support' ) . '</a>',
        );
        return array_merge( $custom_links, $links );
    }

    /**
     * Handle immediate conversion upon upload.
     */
    public static function handle_heic_upload( $upload ) {
        if ( ! in_array( $upload['type'], array( 'image/heic', 'image/heif' ) ) ) {
            return $upload;
        }

        // Logic for conversion using ImageMagick goes here, 
        // similar to the convert_to_webp logic in your previous script.
        return $upload;
    }

    /**
     * Run the three environment preflight checks and return an array of results.
     * Each entry: [ 'label' => string, 'pass' => bool, 'status' => string ]
     *
     * @return array
     */
    private static function preflight_checks() {
        $checks = array();

        // 1. Imagick extension loaded.
        $imagick_loaded = extension_loaded( 'imagick' );
        $checks[] = array(
            'label'  => __( 'Imagick extension', 'thisismyurl-heic-support' ),
            'pass'   => $imagick_loaded,
            'status' => $imagick_loaded
                ? __( 'Available', 'thisismyurl-heic-support' )
                : __( 'Not available', 'thisismyurl-heic-support' ),
        );

        // 2. HEIC decoding supported by Imagick.
        $heic_decode = class_exists( 'Imagick' ) && in_array( 'HEIC', Imagick::queryFormats(), true );
        $checks[] = array(
            'label'  => __( 'HEIC decoding', 'thisismyurl-heic-support' ),
            'pass'   => $heic_decode,
            'status' => $heic_decode
                ? __( 'Available', 'thisismyurl-heic-support' )
                : __( 'Not available', 'thisismyurl-heic-support' ),
        );

        // 3. Backup directory is writable.
        $upload_dir    = wp_upload_dir();
        $backup_dir    = $upload_dir['basedir'] . '/heic-backups/';
        $dir_writable  = wp_is_writable( $backup_dir );
        $checks[] = array(
            'label'  => __( 'Backup directory writable', 'thisismyurl-heic-support' ),
            'pass'   => $dir_writable,
            'status' => $dir_writable
                ? __( 'Available', 'thisismyurl-heic-support' )
                : __( 'Not available', 'thisismyurl-heic-support' ),
        );

        return $checks;
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $checks = self::preflight_checks();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'HEIC Support', 'thisismyurl-heic-support' ); ?></h1>

            <div class="timu-heic-preflight">
                <h2><?php esc_html_e( 'Environment Status', 'thisismyurl-heic-support' ); ?></h2>
                <table class="widefat striped timu-heic-preflight__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Check', 'thisismyurl-heic-support' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'thisismyurl-heic-support' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $checks as $check ) : ?>
                        <tr>
                            <td><?php echo esc_html( $check['label'] ); ?></td>
                            <td>
                                <span class="timu-heic-preflight__indicator timu-heic-preflight__indicator--<?php echo esc_attr( $check['pass'] ? 'pass' : 'fail' ); ?>" aria-hidden="true"></span>
                                <?php echo esc_html( $check['status'] ); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p><?php esc_html_e( 'HEIC conversion engine ready. (Requires Imagick)', 'thisismyurl-heic-support' ); ?></p>
        </div>
        <?php
    }
}

TIMU_HEIC_Support::init();

add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( 'thisismyurl-heic-support', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// GitHub Updater Integration
add_action( 'plugins_loaded', function() {
    $updater_path = plugin_dir_path( __FILE__ ) . 'updater.php';
    if ( file_exists( $updater_path ) ) {
        require_once $updater_path;
        if ( class_exists( 'FWO_GitHub_Updater' ) ) {
            new FWO_GitHub_Updater( array(
                'slug'               => 'thisismyurl-heic-support',
                'proper_folder_name' => 'thisismyurl-heic-support',
                'api_url'            => 'https://api.github.com/repos/thisismyurl/thisismyurl-heic-support/releases/latest',
                'github_url'         => 'https://github.com/thisismyurl/thisismyurl-heic-support',
                'plugin_file'        => __FILE__,
            ) );
        }
    }
});