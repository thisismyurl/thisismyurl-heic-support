<?php
/**
 * Plugin Name:       HEIC Support by thisismyurl.com
 * Plugin URI:        https://thisismyurl.com/thisismyurl-heic-support/
 * Description:       Non-destructive HEIC/HEIF to WebP conversion with backups, bulk processing, auto-convert on upload, and one-click restoration.
 * Version:           0.6147
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       thisismyurl-heic-support
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/thisismyurl/thisismyurl-heic-support
 * Primary Branch:    main
 * Update URI:        https://github.com/thisismyurl/thisismyurl-heic-support
 * Donate link:       https://thisismyurl.com/donate/
 *
 * @package TIMU_HEIC_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TIMU_HEIC_SUPPORT_DIR' ) ) {
    define( 'TIMU_HEIC_SUPPORT_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'TIMU_HEIC_SUPPORT_BASENAME' ) ) {
    define( 'TIMU_HEIC_SUPPORT_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * HEIC/HEIF to WebP conversion with non-destructive backups.
 *
 * The conversion engine has a single core routine, encode_to_webp(), shared by
 * three entry points: the upload prefilter (Part B auto-convert), the batch
 * converter that walks the existing library, and the WP 7 abilities. No path
 * re-implements the Imagick decode or the backup move.
 */
class TIMU_HEIC_Support {

    const AJAX_NONCE_ACTION = 'timu_heic_nonce';
    const BACKUP_META_KEY   = '_heic_original_path';
    const SAVINGS_META_KEY  = '_heic_savings';
    const OPTION_KEY        = 'timu_heic_support_options';
    const SETTINGS_GROUP    = 'timu_heic_support_settings';
    const LOCK_PREFIX       = 'timu_heic_lock_';
    const LOCK_TTL_SECONDS  = 300;
    const BACKUP_SUBDIR     = 'heic-backups';

    /**
     * Source mime types this plugin converts.
     *
     * @var string[]
     */
    const SOURCE_MIMES = array( 'image/heic', 'image/heif' );

    /**
     * Initialize plugin hooks.
     *
     * @return void
     */
    public static function init() {
        add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_filter( 'upload_mimes', array( __CLASS__, 'allow_heic_uploads' ) );
        add_filter( 'wp_handle_upload', array( __CLASS__, 'handle_heic_upload' ) );
        add_action( 'wp_ajax_timu_heic_process_batch', array( __CLASS__, 'ajax_process_batch' ) );
        add_action( 'wp_ajax_timu_heic_restore_single', array( __CLASS__, 'ajax_restore_single' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_plugin_action_links' ) );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'heic', 'TIMU_HEIC_Support_CLI' );
        }
    }

    /**
     * Load plugin translations.
     *
     * @return void
     */
    public static function load_textdomain() {
        load_plugin_textdomain(
            'thisismyurl-heic-support',
            false,
            dirname( TIMU_HEIC_SUPPORT_BASENAME ) . '/languages'
        );
    }

    /**
     * Allow HEIC/HEIF uploads through the media uploader.
     *
     * @param array $mimes Existing allowed mime types.
     *
     * @return array
     */
    public static function allow_heic_uploads( $mimes ) {
        $mimes['heic']      = 'image/heic';
        $mimes['heif']      = 'image/heif';
        $mimes['heic|heif'] = 'image/heic';

        return $mimes;
    }

    /**
     * Register plugin settings.
     *
     * @return void
     */
    public static function register_settings() {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
                'default'           => self::get_default_options(),
            )
        );
    }

    /**
     * Enqueue admin assets for the tools page.
     *
     * @param string $hook_suffix Current admin page suffix.
     *
     * @return void
     */
    public static function enqueue_admin_assets( $hook_suffix ) {
        if ( 'tools_page_heic-optimizer' !== $hook_suffix ) {

            return;
        }

        wp_enqueue_script(
            'timu-heic-support-admin',
            plugin_dir_url( __FILE__ ) . 'assets/js/admin.js',
            array( 'jquery' ),
            '0.6147',
            true
        );
    }

    /**
     * Register the Tools submenu page.
     *
     * @return void
     */
    public static function add_admin_menu() {
        add_management_page(
            __( 'HEIC Support', 'thisismyurl-heic-support' ),
            __( 'HEIC Support', 'thisismyurl-heic-support' ),
            'manage_options',
            'heic-optimizer',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    /**
     * Add Settings and Sponsor links to plugin row actions.
     *
     * @param array $links Existing plugin row links.
     *
     * @return array
     */
    public static function add_plugin_action_links( $links ) {
        $custom_links = array(
            '<a href="' . esc_url( admin_url( 'tools.php?page=heic-optimizer' ) ) . '">' . esc_html__( 'Settings', 'thisismyurl-heic-support' ) . '</a>',
            '<a href="' . esc_url( 'https://github.com/sponsors/thisismyurl' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sponsor', 'thisismyurl-heic-support' ) . '</a>',
        );

        return array_merge( $custom_links, $links );
    }

    /**
     * Return default plugin options.
     *
     * @return array
     */
    private static function get_default_options() {
        return array(
            'quality'                  => 82,
            'quality_preset'           => 'web',
            'batch_size'               => 10,
            'auto_convert_uploads'     => 1,
            'delete_backups_uninstall' => 1,
        );
    }

    /**
     * Retrieve plugin options merged with defaults.
     *
     * @return array
     */
    private static function get_options() {
        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, self::get_default_options() );
    }

    /**
     * Sanitize plugin options.
     *
     * @param array $input Unsanitized option values.
     *
     * @return array
     */
    public static function sanitize_options( $input ) {
        $defaults = self::get_default_options();
        $input    = is_array( $input ) ? $input : array();

        $quality = isset( $input['quality'] ) ? absint( $input['quality'] ) : $defaults['quality'];
        $quality = min( 100, max( 0, $quality ) );

        $allowed_presets = array( 'web', 'print', 'lossless', 'custom' );
        $quality_preset  = isset( $input['quality_preset'] ) ? sanitize_key( $input['quality_preset'] ) : $defaults['quality_preset'];
        if ( ! in_array( $quality_preset, $allowed_presets, true ) ) {
            $quality_preset = 'web';
        }

        $batch_size = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $defaults['batch_size'];
        $batch_size = min( 100, max( 1, $batch_size ) );

        return array(
            'quality'                  => $quality,
            'quality_preset'           => $quality_preset,
            'batch_size'               => $batch_size,
            'auto_convert_uploads'     => isset( $input['auto_convert_uploads'] ) ? 1 : 0,
            'delete_backups_uninstall' => isset( $input['delete_backups_uninstall'] ) ? 1 : 0,
        );
    }

    /**
     * Get active conversion quality.
     *
     * @return int
     */
    private static function get_quality_setting() {
        $options = self::get_options();
        $preset  = isset( $options['quality_preset'] ) ? $options['quality_preset'] : 'web';

        switch ( $preset ) {
            case 'print':
                return 95;
            case 'lossless':
                return 100;
            case 'custom':
                return (int) $options['quality'];
            case 'web':
            default:
                return 82;
        }
    }

    /**
     * Get active processing batch size.
     *
     * @return int
     */
    private static function get_batch_size_setting() {
        $options = self::get_options();
        return (int) $options['batch_size'];
    }

    /**
     * Whether uploads should be auto-converted.
     *
     * @return bool
     */
    private static function auto_convert_enabled() {
        $options = self::get_options();
        return ! empty( $options['auto_convert_uploads'] );
    }

    /**
     * Whether the server can decode HEIC/HEIF via Imagick.
     *
     * Probes Imagick's compiled format list rather than trusting the upload
     * mime map, because an Imagick build without `libheif` will still accept
     * the file through the uploader but fail at decode time.
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
     * Initialize the WordPress Filesystem API.
     *
     * Falls back to a thin direct-PHP shim when WP_Filesystem refuses to
     * initialise (typically because the host wants FTP/SSH credentials and we
     * have no UI surface to prompt). The batch and restore entry points are all
     * gated by `current_user_can( 'manage_options' )`; the upload path runs
     * under the uploader's own already-checked capability. Either way, dropping
     * to direct file ops is acceptable here.
     *
     * @return WP_Filesystem_Base|TIMU_HEIC_Direct_FS
     */
    private static function init_fs() {
        global $wp_filesystem;

        if ( ! empty( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
            return $wp_filesystem;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        if ( ! empty( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
            return $wp_filesystem;
        }

        return new TIMU_HEIC_Direct_FS();
    }

    /**
     * Replace a file extension while preserving the path.
     *
     * @param string $path      File path.
     * @param string $extension New extension without dot.
     *
     * @return string
     */
    private static function swap_extension( $path, $extension ) {
        return preg_replace( '/\.[^.]+$/', '.' . ltrim( $extension, '.' ), $path );
    }

    /**
     * Build the backup directory path for an attachment, mirroring its
     * uploads subdirectory so backups don't collide across folders.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return string
     */
    private static function get_backup_dir( $attachment_id ) {
        $upload_dir = wp_upload_dir();
        $rel_path   = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $subdir     = dirname( (string) $rel_path );

        if ( '.' === $subdir || '' === $subdir ) {
            $subdir = '';
        }

        return trailingslashit( $upload_dir['basedir'] . '/' . self::BACKUP_SUBDIR . '/' . $subdir );
    }

    /**
     * Resolve a stored backup-path meta value to an absolute filesystem path.
     *
     * Backups are persisted relative to `uploads/basedir/` so dev↔prod database
     * copies and host migrations don't orphan them. Legacy absolute values are
     * still honoured by leaf-checking for an absolute marker.
     *
     * @param string $stored Raw meta value from BACKUP_META_KEY.
     *
     * @return string Absolute path or empty string.
     */
    private static function resolve_backup_path( $stored ) {
        if ( '' === $stored ) {
            return '';
        }

        // Legacy absolute path — Unix `/foo`, Windows `C:\foo`, or UNC `\\server\foo`.
        if ( '/' === $stored[0] || '\\' === $stored[0] || ( isset( $stored[1] ) && ':' === $stored[1] ) ) {
            return $stored;
        }

        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . ltrim( $stored, '/\\' );
    }

    /**
     * Convert an absolute backup path to the uploads-relative form persisted to
     * postmeta. Paths outside uploads fall back to the absolute value so the
     * data round-trips losslessly.
     *
     * @param string $absolute_path Absolute filesystem path.
     *
     * @return string
     */
    private static function relativize_backup_path( $absolute_path ) {
        $upload_dir = wp_upload_dir();
        $basedir    = trailingslashit( $upload_dir['basedir'] );

        if ( 0 === strpos( $absolute_path, $basedir ) ) {
            return substr( $absolute_path, strlen( $basedir ) );
        }

        return $absolute_path;
    }

    /**
     * Acquire a per-attachment lock to prevent concurrent conversion or
     * restoration of the same file.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return bool True if the lock was acquired, false if already held.
     */
    private static function acquire_lock( $attachment_id ) {
        $key = self::LOCK_PREFIX . (int) $attachment_id;
        if ( false !== get_transient( $key ) ) {
            return false;
        }

        return (bool) set_transient( $key, time(), self::LOCK_TTL_SECONDS );
    }

    /**
     * Release a per-attachment lock acquired via acquire_lock().
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return void
     */
    private static function release_lock( $attachment_id ) {
        delete_transient( self::LOCK_PREFIX . (int) $attachment_id );
    }

    /**
     * Encode a HEIC/HEIF file to WebP on disk.
     *
     * This is the single Imagick decode + WebP encode routine. Every conversion
     * path — upload prefilter, batch library walk, and the abilities — funnels
     * its pixel work through here so there is exactly one engine. It writes the
     * WebP next to the source and returns its absolute path; it does not touch
     * postmeta, attachment records, or the backup move (callers own those steps,
     * because the upload path has no attachment ID yet).
     *
     * @param string $source_path Absolute path to a readable HEIC/HEIF file.
     * @param int    $quality     WebP encoder quality (0–100).
     *
     * @return string|WP_Error Absolute path to the written WebP, or WP_Error.
     */
    public static function encode_to_webp( $source_path, $quality ) {
        if ( ! self::imagick_supports_heic() ) {
            return new WP_Error( 'heic_no_imagick', __( 'Imagick with HEIC support is not available on this server.', 'thisismyurl-heic-support' ) );
        }

        if ( ! is_string( $source_path ) || ! file_exists( $source_path ) ) {
            return new WP_Error( 'heic_missing_source', __( 'The source image could not be found.', 'thisismyurl-heic-support' ) );
        }

        $webp_path = self::swap_extension( $source_path, 'webp' );

        try {
            $image = new Imagick( $source_path );
            $image->setImageFormat( 'webp' );
            $image->setImageCompressionQuality( (int) $quality );
            $written = $image->writeImage( $webp_path );
            $image->clear();
            $image->destroy();
        } catch ( Exception $e ) {
            return new WP_Error( 'heic_convert_failed', __( 'The image could not be converted to WebP.', 'thisismyurl-heic-support' ) );
        }

        if ( ! $written || ! file_exists( $webp_path ) ) {
            return new WP_Error( 'heic_convert_failed', __( 'The image could not be converted to WebP.', 'thisismyurl-heic-support' ) );
        }

        return $webp_path;
    }

    /**
     * Auto-convert a HEIC/HEIF upload to WebP (Part B).
     *
     * Runs on `wp_handle_upload`, before the attachment row exists, so it
     * operates on the file path directly: the original is moved into the
     * backup directory, the WebP is encoded from that archived original, and
     * the upload array is rewritten to point at the WebP. The backup path is
     * stashed in a transient keyed by the WebP path so add_attachment can carry
     * it onto the new attachment's postmeta — preserving restore parity with
     * batch-converted images.
     *
     * Fails open: any error leaves the original upload untouched so an upload
     * never fatals on a host without HEIC decode support.
     *
     * @param array $upload Result of wp_handle_upload(): file, url, type.
     *
     * @return array The (possibly rewritten) upload array.
     */
    public static function handle_heic_upload( $upload ) {
        if ( ! isset( $upload['type'], $upload['file'] ) ) {
            return $upload;
        }

        if ( ! in_array( $upload['type'], self::SOURCE_MIMES, true ) ) {
            return $upload;
        }

        if ( ! self::auto_convert_enabled() || ! self::imagick_supports_heic() ) {
            return $upload; // Leave HEIC in place; the batch tool can convert later.
        }

        $fs = self::init_fs();
        if ( ! $fs ) {
            return $upload;
        }

        $source_path = $upload['file'];
        $backup_dir  = trailingslashit( wp_upload_dir()['basedir'] . '/' . self::BACKUP_SUBDIR );

        if ( ! wp_mkdir_p( $backup_dir ) ) {
            return $upload;
        }

        $backup_path = $backup_dir . wp_unique_filename( $backup_dir, wp_basename( $source_path ) );
        if ( ! $fs->move( $source_path, $backup_path, true ) ) {
            return $upload;
        }

        $original_size = (int) filesize( $backup_path );
        $webp_path     = self::encode_to_webp( $backup_path, self::get_quality_setting() );

        if ( is_wp_error( $webp_path ) ) {
            // Restore the original to its upload location so the upload survives.
            $fs->move( $backup_path, $source_path, true );
            return $upload;
        }

        // The WebP was written beside the backup; move it to the upload location.
        $final_webp = self::swap_extension( $source_path, 'webp' );
        if ( ! $fs->move( $webp_path, $final_webp, true ) ) {
            $fs->move( $backup_path, $source_path, true );
            return $upload;
        }

        $savings = max( 0, $original_size - (int) filesize( $final_webp ) );
        self::stash_upload_backup( $final_webp, $backup_path, $savings );

        $upload['file'] = $final_webp;
        $upload['url']  = str_replace( wp_basename( $upload['url'] ), wp_basename( $final_webp ), $upload['url'] );
        $upload['type'] = 'image/webp';

        return $upload;
    }

    /**
     * Stash an upload's backup metadata in a short-lived transient so it can be
     * attached to the post once the attachment row is created.
     *
     * @param string $webp_path   Absolute path to the final WebP file.
     * @param string $backup_path Absolute path to the archived original.
     * @param int    $savings     Bytes saved by the conversion.
     *
     * @return void
     */
    private static function stash_upload_backup( $webp_path, $backup_path, $savings ) {
        set_transient(
            'timu_heic_pending_' . md5( $webp_path ),
            array(
                'backup'  => self::relativize_backup_path( $backup_path ),
                'savings' => (int) $savings,
            ),
            self::LOCK_TTL_SECONDS
        );

        if ( ! has_action( 'add_attachment', array( __CLASS__, 'persist_upload_backup' ) ) ) {
            add_action( 'add_attachment', array( __CLASS__, 'persist_upload_backup' ) );
        }
    }

    /**
     * Persist stashed upload-backup metadata onto a freshly created attachment.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return void
     */
    public static function persist_upload_backup( $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return;
        }

        $key     = 'timu_heic_pending_' . md5( $file );
        $stashed = get_transient( $key );
        if ( ! is_array( $stashed ) || empty( $stashed['backup'] ) ) {
            return;
        }

        update_post_meta( $attachment_id, self::BACKUP_META_KEY, $stashed['backup'] );
        update_post_meta( $attachment_id, self::SAVINGS_META_KEY, (int) $stashed['savings'] );
        delete_transient( $key );
    }

    /**
     * Convert a single Media Library attachment from HEIC/HEIF to WebP and back
     * up the original.
     *
     * Backward-compatible alias retained for the pre-rebuild callers that
     * reached for `convert_attachment()`. New code should call convert_image().
     *
     * @param int      $attachment_id Attachment ID.
     * @param int|null $quality       Encoder quality, or null for plugin settings.
     *
     * @return true|WP_Error
     */
    public static function convert_attachment( $attachment_id, $quality = null ) {
        return self::convert_image( $attachment_id, $quality );
    }

    /**
     * Convert a Media Library attachment from HEIC/HEIF to WebP and back up the
     * original. Locked against concurrent conversion of the same attachment.
     *
     * @param int      $attachment_id Attachment ID.
     * @param int|null $quality       Encoder quality, or null for plugin settings.
     *
     * @return true|WP_Error
     */
    public static function convert_image( $attachment_id, $quality = null ) {
        $attachment_id = (int) $attachment_id;

        if ( ! self::acquire_lock( $attachment_id ) ) {
            return new WP_Error( 'locked', __( 'Another process is already converting this image.', 'thisismyurl-heic-support' ) );
        }

        try {
            return self::convert_image_locked( $attachment_id, $quality );
        } finally {
            self::release_lock( $attachment_id );
        }
    }

    /**
     * Inner conversion routine. Caller must hold the per-attachment lock.
     *
     * @param int      $attachment_id Attachment ID.
     * @param int|null $quality       Encoder quality, or null for plugin settings.
     *
     * @return true|WP_Error
     */
    private static function convert_image_locked( $attachment_id, $quality = null ) {
        $fs        = self::init_fs();
        $full_path = get_attached_file( $attachment_id );

        if ( null === $quality ) {
            $quality = self::get_quality_setting();
        }

        if ( ! $fs || ! $full_path || ! $fs->exists( $full_path ) ) {
            return new WP_Error( 'missing', __( 'File does not exist.', 'thisismyurl-heic-support' ) );
        }

        $mime = get_post_mime_type( $attachment_id );
        if ( ! in_array( $mime, self::SOURCE_MIMES, true ) ) {
            return new WP_Error( 'mime', __( 'Attachment is not a HEIC/HEIF image.', 'thisismyurl-heic-support' ) );
        }

        $original_size = (int) filesize( $full_path );
        $rel_path      = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $new_rel_path  = self::swap_extension( $rel_path, 'webp' );

        // Archive the original first, then encode from the archived copy so the
        // source pixels survive even if the WebP write fails mid-stream.
        $backup_dir = self::get_backup_dir( $attachment_id );
        if ( ! wp_mkdir_p( $backup_dir ) ) {
            return new WP_Error( 'mkdir', __( 'Unable to create backup directory.', 'thisismyurl-heic-support' ) );
        }

        $backup_path = $backup_dir . wp_unique_filename( $backup_dir, basename( $full_path ) );
        if ( ! $fs->move( $full_path, $backup_path, true ) ) {
            return new WP_Error( 'move', __( 'Failed to archive original file.', 'thisismyurl-heic-support' ) );
        }

        $webp_path = self::encode_to_webp( $backup_path, $quality );
        if ( is_wp_error( $webp_path ) ) {
            // Roll the original back into place; nothing was committed.
            $fs->move( $backup_path, $full_path, true );
            return $webp_path;
        }

        $new_path = self::swap_extension( $full_path, 'webp' );
        if ( $webp_path !== $new_path && ! $fs->move( $webp_path, $new_path, true ) ) {
            $fs->delete( $webp_path );
            $fs->move( $backup_path, $full_path, true );
            return new WP_Error( 'place', __( 'Failed to place the converted WebP file.', 'thisismyurl-heic-support' ) );
        }

        update_post_meta( $attachment_id, self::BACKUP_META_KEY, self::relativize_backup_path( $backup_path ) );
        update_post_meta( $attachment_id, self::SAVINGS_META_KEY, max( 0, $original_size - (int) filesize( $new_path ) ) );
        update_post_meta( $attachment_id, '_wp_attached_file', $new_rel_path );

        wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => 'image/webp',
            )
        );

        self::regenerate_metadata( $attachment_id, $new_path );

        return true;
    }

    /**
     * Regenerate image metadata after file replacement.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $absolute_path Absolute file path.
     *
     * @return void
     */
    private static function regenerate_metadata( $attachment_id, $absolute_path ) {
        if ( ! file_exists( $absolute_path ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $metadata = wp_generate_attachment_metadata( $attachment_id, $absolute_path );
        if ( ! is_wp_error( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }
    }

    /**
     * Restore an original HEIC/HEIF image from backup.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return bool
     */
    public static function restore_image( $attachment_id ) {
        $attachment_id = (int) $attachment_id;

        if ( ! self::acquire_lock( $attachment_id ) ) {
            return false;
        }

        try {
            return self::restore_image_locked( $attachment_id );
        } finally {
            self::release_lock( $attachment_id );
        }
    }

    /**
     * Inner restoration routine. Caller must hold the per-attachment lock.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return bool
     */
    private static function restore_image_locked( $attachment_id ) {
        $fs          = self::init_fs();
        $backup_path = self::resolve_backup_path( get_post_meta( $attachment_id, self::BACKUP_META_KEY, true ) );

        if ( ! $fs || ! $backup_path || ! $fs->exists( $backup_path ) ) {
            return false;
        }

        $current_webp = get_attached_file( $attachment_id );
        if ( ! $current_webp ) {
            return false;
        }

        $extension     = strtolower( pathinfo( $backup_path, PATHINFO_EXTENSION ) );
        $restored_path = self::swap_extension( $current_webp, $extension );

        if ( ! $fs->move( $backup_path, $restored_path, true ) ) {
            return false;
        }

        if ( $restored_path !== $current_webp && $fs->exists( $current_webp ) ) {
            $fs->delete( $current_webp );
        }

        $rel_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $new_rel  = self::swap_extension( $rel_path, $extension );
        update_post_meta( $attachment_id, '_wp_attached_file', $new_rel );

        $mime = 'heif' === $extension ? 'image/heif' : 'image/heic';

        wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => $mime,
            )
        );

        self::regenerate_metadata( $attachment_id, $restored_path );

        delete_post_meta( $attachment_id, self::BACKUP_META_KEY );
        delete_post_meta( $attachment_id, self::SAVINGS_META_KEY );

        return true;
    }

    /**
     * Return lists of pending and managed media items.
     *
     * Walks the attachment table in pages of 200 IDs to keep memory bounded on
     * large libraries. Only IDs are hydrated by the query; full WP_Post objects
     * are warmed on demand inside the loop.
     *
     * @return array {
     *     @type WP_Post[] $pending HEIC/HEIF attachments awaiting conversion.
     *     @type WP_Post[] $media   Attachments already converted (with a backup).
     * }
     */
    public static function get_media_lists() {
        $mime_filter = array_merge( self::SOURCE_MIMES, array( 'image/webp' ) );

        $pending  = array();
        $media    = array();
        $page     = 1;
        $per_page = 200;

        do {
            $query = new WP_Query(
                array(
                    'post_type'              => 'attachment',
                    'post_status'            => 'inherit',
                    'posts_per_page'         => $per_page,
                    'paged'                  => $page,
                    'fields'                 => 'ids',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'post_mime_type'         => $mime_filter,
                )
            );

            if ( empty( $query->posts ) ) {
                break;
            }

            foreach ( $query->posts as $attachment_id ) {
                $post = get_post( $attachment_id );
                if ( ! $post ) {
                    continue;
                }

                $file      = get_attached_file( $post->ID );
                $mime      = get_post_mime_type( $post->ID );
                $orig_path = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );

                if ( ! $file || ! file_exists( $file ) ) {
                    $post->timu_heic_status = 'missing';
                    $media[]                = $post;
                    continue;
                }

                if ( $orig_path ) {
                    // Converted by this plugin and reversible.
                    $media[] = $post;
                } elseif ( in_array( $mime, self::SOURCE_MIMES, true ) ) {
                    $pending[] = $post;
                }
                // WebP attachments without our backup meta are externally
                // managed; we neither list nor touch them.
            }

            $fetched = count( $query->posts );
            ++$page;
        } while ( $fetched === $per_page );

        return array(
            'pending' => $pending,
            'media'   => $media,
        );
    }

    /**
     * Convert HEIC/HEIF attachments already in the Media Library to WebP.
     *
     * Thin batch wrapper over convert_image() used by the abilities and any
     * caller that wants a count-shaped summary across the whole library.
     *
     * @param int $limit Maximum attachments to process this run. 0 = unlimited.
     *
     * @return array { converted: int, skipped: int, errors: int, space_saved_bytes: int, error_messages: string[] }
     */
    public static function convert_existing_library( $limit = 0 ) {
        $limit = max( 0, (int) $limit );
        $lists = self::get_media_lists();
        $ids   = array_map(
            static function ( $post ) {
                return (int) $post->ID;
            },
            $lists['pending']
        );

        $skipped = 0;
        if ( $limit > 0 && count( $ids ) > $limit ) {
            $skipped = count( $ids ) - $limit;
            $ids     = array_slice( $ids, 0, $limit );
        }

        $converted         = 0;
        $errors            = 0;
        $space_saved_bytes = 0;
        $error_messages    = array();

        foreach ( $ids as $id ) {
            $result = self::convert_image( $id );
            if ( true === $result ) {
                ++$converted;
                $space_saved_bytes += (int) get_post_meta( $id, self::SAVINGS_META_KEY, true );
                continue;
            }

            ++$errors;
            $error_messages[] = is_wp_error( $result )
                ? sprintf( '#%d: %s', $id, $result->get_error_message() )
                : sprintf( '#%d: %s', $id, __( 'unknown error', 'thisismyurl-heic-support' ) );
        }

        return array(
            'converted'         => $converted,
            'skipped'           => $skipped,
            'errors'            => $errors,
            'space_saved_bytes' => $space_saved_bytes,
            'error_messages'    => $error_messages,
        );
    }

    /**
     * AJAX callback: process a chunk of attachments.
     *
     * @return void
     */
    public static function ajax_process_batch() {
        check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-heic-support' ) );
        }

        $batch_limit = self::get_batch_size_setting();
        $ids         = isset( $_POST['attachment_ids'] ) ? (array) wp_unslash( $_POST['attachment_ids'] ) : array();
        $ids         = array_slice( array_values( array_filter( array_map( 'absint', $ids ) ) ), 0, $batch_limit );

        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'No attachments were provided for batch processing.', 'thisismyurl-heic-support' ) );
        }

        $processed_ids = array();
        $failed_ids    = array();
        $errors        = array();

        foreach ( $ids as $attachment_id ) {
            $result = self::convert_image( $attachment_id, self::get_quality_setting() );
            if ( true === $result ) {
                $processed_ids[] = $attachment_id;
            } else {
                $failed_ids[] = $attachment_id;
                $errors[]     = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown conversion error.', 'thisismyurl-heic-support' );
            }
        }

        wp_send_json_success(
            array(
                'processed_ids' => $processed_ids,
                'failed_ids'    => $failed_ids,
                'errors'        => array_values( array_unique( $errors ) ),
            )
        );
    }

    /**
     * AJAX callback: restore one converted image.
     *
     * @return void
     */
    public static function ajax_restore_single() {
        check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-heic-support' ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
        if ( ! $attachment_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-heic-support' ) );
        }

        if ( self::restore_image( $attachment_id ) ) {
            wp_send_json_success();
        }

        wp_send_json_error( __( 'Image could not be restored.', 'thisismyurl-heic-support' ) );
    }

    /**
     * Render the admin page.
     *
     * @return void
     */
    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'thisismyurl-heic-support' ) );
        }

        $lists       = self::get_media_lists();
        $options     = self::get_options();
        $heic_ok     = self::imagick_supports_heic();
        $pending_ids = array_map(
            static function ( $post ) {
                return (int) $post->ID;
            },
            $lists['pending']
        );

        $restorable = array();
        foreach ( $lists['media'] as $post ) {
            $orig = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );
            if ( $orig ) {
                $restorable[] = (int) $post->ID;
            }
        }

        $pending_bytes = 0;
        foreach ( $lists['pending'] as $post ) {
            $f = get_attached_file( $post->ID );
            if ( $f && file_exists( $f ) ) {
                $pending_bytes += (int) filesize( $f );
            }
        }

        $managed_savings       = 0;
        $managed_savings_count = 0;
        foreach ( $lists['media'] as $post ) {
            $sv = (int) get_post_meta( $post->ID, self::SAVINGS_META_KEY, true );
            if ( $sv > 0 ) {
                $managed_savings += $sv;
                ++$managed_savings_count;
            }
        }

        wp_add_inline_script(
            'timu-heic-support-admin',
            'window.TIMUHeicSupportData = ' . wp_json_encode(
                array(
                    'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                    'nonce'      => wp_create_nonce( self::AJAX_NONCE_ACTION ),
                    'actions'    => array(
                        'batch'   => 'timu_heic_process_batch',
                        'restore' => 'timu_heic_restore_single',
                    ),
                    'batchSize'  => self::get_batch_size_setting(),
                    'pendingIds' => $pending_ids,
                    'strings'    => array(
                        'processing'        => __( 'Processing...', 'thisismyurl-heic-support' ),
                        'restoring'         => __( 'Restoring...', 'thisismyurl-heic-support' ),
                        'confirmRestoreAll' => __( 'Restore all images? This cannot be undone.', 'thisismyurl-heic-support' ),
                        'failedPrefix'      => __( 'Some images failed:', 'thisismyurl-heic-support' ),
                    ),
                )
            ) . ';',
            'before'
        );

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'HEIC Support', 'thisismyurl-heic-support' ); ?>
                <span style="font-size:0.5em;font-weight:normal;vertical-align:middle;margin-left:10px;color:#646970;">
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            /* translators: %s: linked site name. */
                            __( 'by %s', 'thisismyurl-heic-support' ),
                            '<a href="https://thisismyurl.com/" target="_blank" rel="noopener noreferrer" style="text-decoration:none;color:inherit;">thisismyurl.com</a>'
                        )
                    );
                    ?>
                </span>
            </h1>
            <p><?php esc_html_e( 'Non-destructive HEIC/HEIF to WebP conversion with backups and one-click restoration.', 'thisismyurl-heic-support' ); ?></p>

            <?php if ( ! $heic_ok ) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'Imagick with HEIC support is not available on this server. Conversion is disabled until libheif-enabled Imagick is installed; uploads are left as HEIC.', 'thisismyurl-heic-support' ); ?></p>
                </div>
            <?php endif; ?>

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Conversion Dashboard', 'thisismyurl-heic-support' ); ?></span></h2>
                            <div class="inside">
                                <div class="welcome-panel-content" style="padding:10px 0;min-height:100px;">
                                    <div class="fwo-controls" style="display:flex;gap:10px;align-items:center;">
                                        <button id="btn-start" class="button button-primary button-large" <?php disabled( empty( $pending_ids ) || ! $heic_ok ); ?>>
                                            <?php
                                            printf(
                                                /* translators: %d: number of pending images. */
                                                esc_html__( 'Convert All %d Images', 'thisismyurl-heic-support' ),
                                                count( $pending_ids )
                                            );
                                            ?>
                                        </button>
                                        <button id="btn-cancel" class="button button-secondary button-large" style="display:none;color:#d63638;">
                                            <?php esc_html_e( 'Cancel Batch', 'thisismyurl-heic-support' ); ?>
                                        </button>
                                    </div>

                                    <div id="fwo-progress-container" style="display:none;margin-top:20px;background:#f0f0f1;height:30px;position:relative;border-radius:4px;overflow:hidden;border:1px solid #c3c4c7;">
                                        <div id="fwo-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.2s;"></div>
                                        <div id="fwo-progress-text" style="position:absolute;width:100%;text-align:center;top:0;line-height:30px;font-weight:bold;color:#fff;mix-blend-mode:difference;">0%</div>
                                    </div>
                                    <?php if ( $pending_bytes > 0 || $managed_savings > 0 ) : ?>
                                    <p class="description" style="margin-top:14px;">
                                        <?php
                                        if ( $pending_bytes > 0 ) {
                                            printf(
                                                /* translators: 1: number of files, 2: total size formatted. */
                                                esc_html__( 'Pending: %1$d file(s), %2$s total.', 'thisismyurl-heic-support' ),
                                                count( $lists['pending'] ),
                                                esc_html( size_format( $pending_bytes, 2 ) )
                                            );
                                        }
                                        if ( $managed_savings > 0 ) {
                                            if ( $pending_bytes > 0 ) {
                                                echo '&ensp;';
                                            }
                                            printf(
                                                /* translators: 1: savings size formatted, 2: image count. */
                                                esc_html__( 'Saved: %1$s across %2$d image(s).', 'thisismyurl-heic-support' ),
                                                esc_html( size_format( $managed_savings, 2 ) ),
                                                (int) $managed_savings_count
                                            );
                                        }
                                        ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Conversion Settings', 'thisismyurl-heic-support' ); ?></span></h2>
                            <div class="inside">
                                <form method="post" action="options.php">
                                    <?php settings_fields( self::SETTINGS_GROUP ); ?>
                                    <table class="form-table" role="presentation">
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Quality Preset', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <?php
                                                $quality_presets = array(
                                                    'web'      => __( 'Web (82) — balanced size/quality', 'thisismyurl-heic-support' ),
                                                    'print'    => __( 'Print (95) — high fidelity', 'thisismyurl-heic-support' ),
                                                    'lossless' => __( 'Lossless (100)', 'thisismyurl-heic-support' ),
                                                    'custom'   => __( 'Custom', 'thisismyurl-heic-support' ),
                                                );
                                                $cur_preset = isset( $options['quality_preset'] ) ? $options['quality_preset'] : 'web';
                                                foreach ( $quality_presets as $val => $label ) :
                                                    ?>
                                                    <label style="display:block;margin-bottom:4px;">
                                                        <input type="radio"
                                                            name="<?php echo esc_attr( self::OPTION_KEY ); ?>[quality_preset]"
                                                            value="<?php echo esc_attr( $val ); ?>"
                                                            <?php checked( $cur_preset, $val ); ?>
                                                            class="timu-preset-radio" />
                                                        <?php echo esc_html( $label ); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                                <div id="timu-custom-quality" style="margin-top:8px;<?php echo 'custom' !== $cur_preset ? 'display:none;' : ''; ?>">
                                                    <label for="timu-quality"><?php esc_html_e( 'Custom quality (0–100):', 'thisismyurl-heic-support' ); ?></label>
                                                    <input id="timu-quality" type="number" min="0" max="100"
                                                        name="<?php echo esc_attr( self::OPTION_KEY ); ?>[quality]"
                                                        value="<?php echo esc_attr( $options['quality'] ); ?>"
                                                        class="small-text" style="margin-left:6px;" />
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="timu-batch-size"><?php esc_html_e( 'Batch Size', 'thisismyurl-heic-support' ); ?></label></th>
                                            <td>
                                                <input id="timu-batch-size" type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]" value="<?php echo esc_attr( $options['batch_size'] ); ?>" class="small-text" />
                                                <p class="description"><?php esc_html_e( 'Number of images processed per AJAX request.', 'thisismyurl-heic-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Auto-Convert Uploads', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_convert_uploads]" value="1" <?php checked( ! empty( $options['auto_convert_uploads'] ) ); ?> />
                                                    <?php esc_html_e( 'Convert new HEIC/HEIF uploads to WebP automatically.', 'thisismyurl-heic-support' ); ?>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Uninstall Behavior', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_backups_uninstall]" value="1" <?php checked( ! empty( $options['delete_backups_uninstall'] ) ); ?> />
                                                    <?php esc_html_e( 'Delete backup files when plugin is uninstalled.', 'thisismyurl-heic-support' ); ?>
                                                </label>
                                            </td>
                                        </tr>
                                    </table>

                                    <?php submit_button( __( 'Save Settings', 'thisismyurl-heic-support' ) ); ?>
                                </form>
                            </div>
                        </div>

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Pending Conversions', 'thisismyurl-heic-support' ); ?> (<span id="p-cnt"><?php echo esc_html( count( $pending_ids ) ); ?></span>)</span></h2>
                            <div class="inside">
                                <table class="widefat striped" id="fwo-pending-table" style="border:none;box-shadow:none;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'ID', 'thisismyurl-heic-support' ); ?></th>
                                            <th><?php esc_html_e( 'File Name', 'thisismyurl-heic-support' ); ?></th>
                                            <th><?php esc_html_e( 'Size', 'thisismyurl-heic-support' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ( ! empty( $lists['pending'] ) ) : ?>
                                            <?php foreach ( $lists['pending'] as $post ) : ?>
                                                <?php
                                                $pf  = get_attached_file( $post->ID );
                                                $psz = ( $pf && file_exists( $pf ) ) ? size_format( (int) filesize( $pf ), 1 ) : '—';
                                                ?>
                                                <tr id="fwo-row-<?php echo esc_attr( $post->ID ); ?>">
                                                    <td>#<?php echo esc_html( $post->ID ); ?></td>
                                                    <td><?php echo esc_html( basename( (string) $pf ) ); ?></td>
                                                    <td><?php echo esc_html( $psz ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <tr class="no-images"><td colspan="3"><?php esc_html_e( 'No HEIC/HEIF images pending conversion.', 'thisismyurl-heic-support' ); ?></td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Managed Media', 'thisismyurl-heic-support' ); ?> (<span id="m-cnt"><?php echo esc_html( count( $lists['media'] ) ); ?></span>)</span></h2>
                            <div class="inside">
                                <table class="widefat striped" id="fwo-media-table" style="border:none;box-shadow:none;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'ID', 'thisismyurl-heic-support' ); ?></th>
                                            <th><?php esc_html_e( 'File Name', 'thisismyurl-heic-support' ); ?></th>
                                            <th><?php esc_html_e( 'Saved', 'thisismyurl-heic-support' ); ?></th>
                                            <th><?php esc_html_e( 'Action', 'thisismyurl-heic-support' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $lists['media'] as $post ) : ?>
                                            <?php
                                            $orig     = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );
                                            $status   = isset( $post->timu_heic_status ) ? $post->timu_heic_status : '';
                                            $savings  = (int) get_post_meta( $post->ID, self::SAVINGS_META_KEY, true );
                                            $saved_sz = $savings > 0 ? size_format( $savings, 1 ) : '—';
                                            ?>
                                            <tr id="fwo-media-row-<?php echo esc_attr( $post->ID ); ?>">
                                                <td>#<?php echo esc_html( $post->ID ); ?></td>
                                                <td><?php echo esc_html( basename( (string) get_attached_file( $post->ID ) ) ); ?></td>
                                                <td><?php echo esc_html( $saved_sz ); ?></td>
                                                <td>
                                                    <?php if ( 'missing' === $status ) : ?>
                                                        <span style="color:#d63638;"><?php esc_html_e( 'File Missing', 'thisismyurl-heic-support' ); ?></span>
                                                    <?php elseif ( $orig ) : ?>
                                                        <button class="restore-btn button button-small" data-id="<?php echo esc_attr( $post->ID ); ?>">
                                                            <?php esc_html_e( 'Restore', 'thisismyurl-heic-support' ); ?>
                                                        </button>
                                                    <?php else : ?>
                                                        <span class="description"><?php esc_html_e( 'Converted', 'thisismyurl-heic-support' ); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>

                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Documentation', 'thisismyurl-heic-support' ); ?></span></h2>
                            <div class="inside">
                                <p><?php esc_html_e( 'This plugin converts HEIC/HEIF images from Apple devices to WebP using Imagick. New uploads can be converted automatically, and existing library images can be converted in bulk. Originals are moved to a backup directory and can be restored at any time.', 'thisismyurl-heic-support' ); ?></p>
                                <hr />
                                <?php if ( ! empty( $restorable ) ) : ?>
                                    <p><strong><?php esc_html_e( 'Bulk Actions', 'thisismyurl-heic-support' ); ?></strong></p>
                                    <button id="btn-restore-all" class="button button-secondary" style="width:100%;text-align:center;" data-ids="<?php echo esc_attr( wp_json_encode( $restorable ) ); ?>">
                                        <?php esc_html_e( 'Restore All Originals', 'thisismyurl-heic-support' ); ?>
                                    </button>
                                    <hr />
                                <?php endif; ?>
                                <p>
                                    <?php
                                    echo wp_kses_post(
                                        sprintf(
                                            /* translators: %s: linked site name. */
                                            __( 'Provided free by %s.', 'thisismyurl-heic-support' ),
                                            '<a href="https://thisismyurl.com/" target="_blank" rel="noopener noreferrer">thisismyurl.com</a>'
                                        )
                                    );
                                    ?>
                                </p>
                                <p><a href="<?php echo esc_url( 'https://github.com/sponsors/thisismyurl' ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer" style="width:100%;text-align:center;"><?php esc_html_e( 'Sponsor development', 'thisismyurl-heic-support' ); ?></a></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * Minimal direct-PHP filesystem shim used when WP_Filesystem refuses to
 * initialise (typically a host that wants FTP/SSH credentials and gives us no
 * UI to surface them). Implements the methods this plugin actually calls —
 * `exists`, `delete`, `move`, `is_dir` — with the same return shapes
 * WP_Filesystem uses, so callers don't branch on which backend they got.
 *
 * Only acceptable here because every filesystem entry point is capability- or
 * uploader-permission-gated before it runs.
 */
class TIMU_HEIC_Direct_FS {

    /**
     * Whether a path exists.
     *
     * @param string $path Absolute filesystem path.
     *
     * @return bool
     */
    public function exists( $path ) {
        return file_exists( $path );
    }

    /**
     * Whether a path is a directory.
     *
     * @param string $path Absolute filesystem path.
     *
     * @return bool
     */
    public function is_dir( $path ) {
        return is_dir( $path );
    }

    /**
     * Delete a file or directory.
     *
     * @param string $path      Absolute filesystem path.
     * @param bool   $recursive Recurse into directories.
     *
     * @return bool
     */
    public function delete( $path, $recursive = false ) {
        if ( ! file_exists( $path ) ) {
            return false;
        }

        if ( is_file( $path ) || is_link( $path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WP_Filesystem unavailable; see init_fs().
            return @unlink( $path );
        }

        if ( ! $recursive ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- WP_Filesystem unavailable; see init_fs().
            return @rmdir( $path );
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $child ) {
            if ( $child->isDir() ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- WP_Filesystem unavailable; see init_fs().
                @rmdir( $child->getPathname() );
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WP_Filesystem unavailable; see init_fs().
                @unlink( $child->getPathname() );
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- WP_Filesystem unavailable; see init_fs().
        return @rmdir( $path );
    }

    /**
     * Move a file, optionally overwriting the destination.
     *
     * @param string $source      Absolute source path.
     * @param string $destination Absolute destination path.
     * @param bool   $overwrite   Overwrite if destination exists.
     *
     * @return bool
     */
    public function move( $source, $destination, $overwrite = false ) {
        if ( ! file_exists( $source ) ) {
            return false;
        }

        if ( file_exists( $destination ) ) {
            if ( ! $overwrite ) {
                return false;
            }
            $this->delete( $destination );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem unavailable; see init_fs().
        return @rename( $source, $destination );
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-timu-heic-cli.php';
}

require_once plugin_dir_path( __FILE__ ) . 'abilities.php';

TIMU_HEIC_Support::init();

// GitHub updater integration.
add_action(
    'plugins_loaded',
    static function () {
        $updater_path = plugin_dir_path( __FILE__ ) . 'updater.php';
        if ( ! file_exists( $updater_path ) ) {
            return;
        }

        require_once $updater_path;
        if ( ! class_exists( 'FWO_GitHub_Updater' ) ) {
            return;
        }

        new FWO_GitHub_Updater(
            array(
                'slug'               => 'thisismyurl-heic-support',
                'proper_folder_name' => 'thisismyurl-heic-support',
                'api_url'            => 'https://api.github.com/repos/thisismyurl/thisismyurl-heic-support/releases/latest',
                'github_url'         => 'https://github.com/thisismyurl/thisismyurl-heic-support',
                'plugin_file'        => __FILE__,
            )
        );
    }
);
