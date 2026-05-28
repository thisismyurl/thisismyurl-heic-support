<?php
/**
 * Plugin Name:       HEIC Support by thisismyurl.com
 * Plugin URI:        https://thisismyurl.com/thisismyurl-heic-support/
 * Description:       Automatically convert HEIC/HEIF images from iOS devices to WebP with secure backups.
 * Version:           0.6147
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * License:           GPLv2 or later
 * Text Domain:       thisismyurl-heic-support
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-heic-support
 * Primary Branch:    main
 * Update URI:        https://github.com/thisismyurl/thisismyurl-heic-support
 * Donate link:       https://thisismyurl.com/donate/
 * * @package TIMU_HEIC_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TIMU_HEIC_Support {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_filter( 'upload_mimes', array( __CLASS__, 'allow_heic_uploads' ) );
        add_filter( 'wp_handle_upload', array( __CLASS__, 'handle_heic_upload' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_plugin_action_links' ) );
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
            '<a href="' . admin_url( 'admin.php?page=heic-optimizer' ) . '">' . esc_html__( 'Settings', 'thisismyurl-heic-support' ) . '</a>',
            '<a href="https://thisismyurl.com/donate/" target="_blank" style="color: #2271b1; font-weight: bold;">' . esc_html__( 'Donate', 'thisismyurl-heic-support' ) . '</a>',
        );
        return array_merge( $custom_links, $links );
    }

    /**
     * Handle immediate conversion upon upload.
     *
     * @param array $upload Result of wp_handle_upload(): file path, url, type.
     * @return array The (possibly rewritten) upload array.
     */
    public static function handle_heic_upload( $upload ) {
        if ( ! isset( $upload['type'], $upload['file'] ) ) {
            return $upload;
        }

        if ( ! in_array( $upload['type'], array( 'image/heic', 'image/heif' ), true ) ) {
            return $upload;
        }

        $result = self::convert_file_to_webp( $upload['file'] );

        if ( is_wp_error( $result ) ) {
            return $upload; // Leave the original upload untouched on failure.
        }

        $upload['file'] = $result['file'];
        $upload['url']  = str_replace( wp_basename( $upload['url'] ), wp_basename( $result['file'] ), $upload['url'] );
        $upload['type'] = 'image/webp';

        return $upload;
    }

    /**
     * Whether the server can convert HEIC/HEIF via Imagick.
     *
     * @return bool
     */
    public static function imagick_supports_heic() {
        if ( ! class_exists( 'Imagick' ) ) {
            return false;
        }

        $formats = array_map( 'strtoupper', Imagick::queryFormats() );

        return in_array( 'HEIC', $formats, true ) || in_array( 'HEIF', $formats, true );
    }

    /**
     * Convert a single HEIC/HEIF file to WebP, backing up the original.
     *
     * Non-destructive: the source file is moved (not deleted) into
     * /uploads/heic-backups/ before the WebP is written in its place. The
     * backup path is recorded so a restore can reverse the operation.
     *
     * @param string $source_path Absolute path to the HEIC/HEIF source file.
     * @return array|WP_Error { file: webp path, backup: original path } or error.
     */
    public static function convert_file_to_webp( $source_path ) {
        if ( ! self::imagick_supports_heic() ) {
            return new WP_Error( 'heic_no_imagick', __( 'Imagick with HEIC support is not available on this server.', 'thisismyurl-heic-support' ) );
        }

        if ( ! is_string( $source_path ) || ! file_exists( $source_path ) ) {
            return new WP_Error( 'heic_missing_source', __( 'The source image could not be found.', 'thisismyurl-heic-support' ) );
        }

        $backup_path = self::backup_original( $source_path );
        if ( is_wp_error( $backup_path ) ) {
            return $backup_path;
        }

        $webp_path = preg_replace( '/\.(heic|heif)$/i', '.webp', $source_path );

        try {
            $image = new Imagick( $backup_path );
            $image->setImageFormat( 'webp' );
            $image->setImageCompressionQuality( 82 );
            $image->writeImage( $webp_path );
            $image->clear();
            $image->destroy();
        } catch ( Exception $e ) {
            return new WP_Error( 'heic_convert_failed', __( 'The image could not be converted to WebP.', 'thisismyurl-heic-support' ) );
        }

        return array(
            'file'   => $webp_path,
            'backup' => $backup_path,
        );
    }

    /**
     * Move an original file into the non-destructive backup directory.
     *
     * @param string $source_path Absolute path to the original file.
     * @return string|WP_Error Absolute path to the backed-up original, or error.
     */
    protected static function backup_original( $source_path ) {
        global $wp_filesystem;

        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'heic-backups/';

        if ( ! $wp_filesystem->is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
            return new WP_Error( 'heic_backup_dir', __( 'The backup directory could not be created.', 'thisismyurl-heic-support' ) );
        }

        $backup_path = $backup_dir . wp_unique_filename( $backup_dir, wp_basename( $source_path ) );

        if ( ! $wp_filesystem->move( $source_path, $backup_path, true ) ) {
            return new WP_Error( 'heic_backup_move', __( 'The original image could not be backed up.', 'thisismyurl-heic-support' ) );
        }

        return $backup_path;
    }

    /**
     * Convert HEIC/HEIF attachments already in the Media Library to WebP.
     *
     * Walks attachments in pages so a large library cannot exhaust memory.
     * Each successfully converted attachment has its file, metadata, and
     * post-MIME updated, and records the backup path in `_heic_original_path`.
     *
     * @param int $limit Maximum attachments to process this run. 0 = unlimited.
     * @return array { converted: int, skipped: int, errors: int }
     */
    public static function convert_existing_library( $limit = 0 ) {
        $limit     = max( 0, (int) $limit );
        $per_page  = $limit > 0 ? min( $limit, 50 ) : 50;
        $converted = 0;
        $skipped   = 0;
        $errors    = 0;
        $paged     = 1;

        do {
            $query = new WP_Query(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'post_mime_type' => array( 'image/heic', 'image/heif' ),
                    'fields'         => 'ids',
                    'posts_per_page' => $per_page,
                    'paged'          => $paged,
                    'no_found_rows'  => true,
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $attachment_id ) {
                if ( $limit > 0 && ( $converted + $skipped + $errors ) >= $limit ) {
                    break 2;
                }

                $outcome = self::convert_attachment( (int) $attachment_id );

                if ( true === $outcome ) {
                    ++$converted;
                } elseif ( is_wp_error( $outcome ) && 'heic_skip' === $outcome->get_error_code() ) {
                    ++$skipped;
                } else {
                    ++$errors;
                }
            }

            ++$paged;
        } while ( $limit <= 0 || ( $converted + $skipped + $errors ) < $limit );

        return array(
            'converted' => $converted,
            'skipped'   => $skipped,
            'errors'    => $errors,
        );
    }

    /**
     * Convert a single Media Library attachment to WebP.
     *
     * @param int $attachment_id Attachment post ID.
     * @return true|WP_Error True on success; WP_Error (code heic_skip) when
     *                       already converted; other WP_Error on failure.
     */
    protected static function convert_attachment( $attachment_id ) {
        $source_path = get_attached_file( $attachment_id );

        if ( ! $source_path || ! file_exists( $source_path ) ) {
            return new WP_Error( 'heic_skip', __( 'Attachment file is missing.', 'thisismyurl-heic-support' ) );
        }

        $result = self::convert_file_to_webp( $source_path );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        update_post_meta( $attachment_id, '_heic_original_path', $result['backup'] );
        update_attached_file( $attachment_id, $result['file'] );
        wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => 'image/webp',
            )
        );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $result['file'] ) );

        return true;
    }

    public static function render_admin_page() {
        // Reuse your professional Metabox Holder UI from the WebP plugin here.
        echo '<div class="wrap"><h1>' . esc_html__( 'HEIC Support', 'thisismyurl-heic-support' ) . '</h1><p>' . esc_html__( 'HEIC conversion engine ready. (Requires Imagick)', 'thisismyurl-heic-support' ) . '</p></div>';
    }
}

TIMU_HEIC_Support::init();

require_once plugin_dir_path( __FILE__ ) . 'abilities.php';

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