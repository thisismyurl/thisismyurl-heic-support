<?php
/**
 * Plugin Name:       HEIC Support by thisismyurl.com
 * Plugin URI:        https://thisismyurl.com/thisismyurl-heic-support/
 * Description:       Non-destructive HEIC/HEIF to WebP conversion with backups, bulk processing, auto-convert on upload, and one-click restoration.
 * Version:           0.6165.0822
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       thisismyurl-heic-support
 * Domain Path:       /languages
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
if ( ! defined( 'TIMU_HEIC_VERSION' ) ) {
    define( 'TIMU_HEIC_VERSION', '0.6165.0822' );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-backup-adapter.php';

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
    const CONVERTED_AT_KEY  = '_heic_converted_at';
    const OPTION_KEY        = 'timu_heic_support_options';
    const SETTINGS_GROUP    = 'timu_heic_support_settings';
    const CRON_HOOK         = 'timu_heic_auto_optimize_event';
    const ENV_OPTION_KEY    = 'timu_heic_environment_status';
    const ADMIN_TICK_LOCK   = 'timu_heic_admin_tick_lock';
    const BATCH_BACKUP_LOCK = 'timu_heic_batch_backup_lock';
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
        add_action( 'admin_notices', array( __CLASS__, 'maybe_show_environment_notice' ) );
        add_action( 'init', array( __CLASS__, 'sync_auto_optimize_schedule' ), 25 );
        add_action( 'admin_init', array( __CLASS__, 'maybe_auto_optimize_on_admin_access' ), 40 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_auto_optimize_cron' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
        add_filter( 'upload_mimes', array( __CLASS__, 'allow_heic_uploads' ) );
        add_filter( 'wp_handle_upload', array( __CLASS__, 'handle_heic_upload' ) );
        add_action( 'wp_ajax_timu_heic_optimize', array( __CLASS__, 'ajax_bulk_optimize' ) );
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
            TIMU_HEIC_VERSION,
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
     * Add Settings and Donate links to plugin row actions.
     *
     * @param array $links Existing plugin row links.
     *
     * @return array
     */
    public static function add_plugin_action_links( $links ) {
        $settings_url = admin_url( 'tools.php?page=heic-optimizer&tab=settings' );
        $donate_url   = self::get_thisismyurl_link( 'https://thisismyurl.com/donate/', 'plugin_row_donate' );

        $custom_links = array(
            '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'thisismyurl-heic-support' ) . '</a>',
            '<a href="' . esc_url( $donate_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Donate', 'thisismyurl-heic-support' ) . '</a>',
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
            'quality'                   => 82,
            'quality_preset'            => 'web',
            'batch_size'                => 10,
            'auto_optimize_batch'       => 3,
            'auto_convert_uploads'      => 1,
            'auto_optimize_enabled'     => 0,
            'auto_optimize_admin'       => 1,
            'auto_optimize_cron'        => 1,
            'auto_optimize_interval'    => 'hourly',
            'list_per_page'             => 25,
            'delete_backups_uninstall'  => 1,
            'report_bandwidth_cost_gb'  => 0.08,
            'report_monthly_image_hits' => 50000,
            'track_outbound_utms'       => 1,
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

        $auto_batch = isset( $input['auto_optimize_batch'] ) ? absint( $input['auto_optimize_batch'] ) : $defaults['auto_optimize_batch'];
        $auto_batch = min( 25, max( 1, $auto_batch ) );

        $allowed_intervals = array( 'fifteen_minutes', 'hourly', 'twicedaily', 'daily' );
        $interval          = isset( $input['auto_optimize_interval'] ) ? sanitize_key( (string) $input['auto_optimize_interval'] ) : 'hourly';
        if ( ! in_array( $interval, $allowed_intervals, true ) ) {
            $interval = 'hourly';
        }

        $report_cost_gb = isset( $input['report_bandwidth_cost_gb'] ) ? (float) $input['report_bandwidth_cost_gb'] : (float) $defaults['report_bandwidth_cost_gb'];
        $report_cost_gb = min( 10, max( 0, $report_cost_gb ) );

        $report_hits = isset( $input['report_monthly_image_hits'] ) ? absint( $input['report_monthly_image_hits'] ) : (int) $defaults['report_monthly_image_hits'];
        $report_hits = min( 100000000, max( 0, $report_hits ) );

        return array(
            'quality'                   => $quality,
            'quality_preset'            => $quality_preset,
            'batch_size'                => $batch_size,
            'auto_optimize_batch'       => $auto_batch,
            'auto_convert_uploads'      => isset( $input['auto_convert_uploads'] ) ? 1 : 0,
            'auto_optimize_enabled'     => isset( $input['auto_optimize_enabled'] ) ? 1 : 0,
            'auto_optimize_admin'       => isset( $input['auto_optimize_admin'] ) ? 1 : 0,
            'auto_optimize_cron'        => isset( $input['auto_optimize_cron'] ) ? 1 : 0,
            'auto_optimize_interval'    => $interval,
            'list_per_page'             => min( 500, max( 5, isset( $input['list_per_page'] ) ? absint( $input['list_per_page'] ) : 25 ) ),
            'delete_backups_uninstall'  => isset( $input['delete_backups_uninstall'] ) ? 1 : 0,
            'report_bandwidth_cost_gb'  => $report_cost_gb,
            'report_monthly_image_hits' => $report_hits,
            'track_outbound_utms'       => isset( $input['track_outbound_utms'] ) ? 1 : 0,
        );
    }

    /**
     * Activation callback. Records environment capability details for admins.
     *
     * @return void
     */
    public static function activate_plugin() {
        $status = array(
            'checked_at'  => time(),
            'has_imagick' => extension_loaded( 'imagick' ) && class_exists( 'Imagick' ),
            'has_heic'    => self::imagick_supports_heic(),
            'php'         => PHP_VERSION,
            'wp_version'  => get_bloginfo( 'version' ),
        );

        update_option( self::ENV_OPTION_KEY, $status, false );
        set_transient( 'timu_heic_activation_status', $status, MINUTE_IN_SECONDS * 5 );
    }

    /**
     * Deactivation callback. Clears scheduled events and locks.
     *
     * @return void
     */
    public static function deactivate_plugin() {
        while ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
            if ( false === $timestamp ) {
                break;
            }
            wp_unschedule_event( (int) $timestamp, self::CRON_HOOK );
        }
        delete_transient( self::ADMIN_TICK_LOCK );
    }

    /**
     * Register custom schedules used by background auto optimization.
     *
     * @param array $schedules Existing schedules.
     *
     * @return array
     */
    public static function register_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['fifteen_minutes'] ) ) {
            $schedules['fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 Minutes', 'thisismyurl-heic-support' ),
            );
        }

        return $schedules;
    }

    /**
     * Show environment notice after activation or when HEIC cannot be decoded.
     *
     * @return void
     */
    public static function maybe_show_environment_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $status = get_transient( 'timu_heic_activation_status' );
        if ( false !== $status ) {
            delete_transient( 'timu_heic_activation_status' );
        } else {
            $status = get_option( self::ENV_OPTION_KEY, array() );
        }

        if ( empty( $status ) || ! is_array( $status ) ) {
            return;
        }

        if ( empty( $status['has_heic'] ) ) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'HEIC Support requires Imagick compiled with libheif. HEIC/HEIF decoding was not detected, so conversions cannot run until it is enabled.', 'thisismyurl-heic-support' );
            echo '</p></div>';
        }
    }

    /**
     * Build a thisismyurl link with optional static, privacy-safe UTM tags.
     *
     * @param string $url      Destination URL.
     * @param string $campaign Campaign identifier.
     *
     * @return string
     */
    private static function get_thisismyurl_link( $url, $campaign ) {
        $options = self::get_options();
        if ( empty( $options['track_outbound_utms'] ) ) {
            return $url;
        }

        return add_query_arg(
            array(
                'utm_source'   => 'wp_plugin',
                'utm_medium'   => 'heic_support',
                'utm_campaign' => sanitize_key( $campaign ),
            ),
            $url
        );
    }

    /**
     * Keep auto-optimization cron scheduling aligned with plugin settings.
     *
     * @return void
     */
    public static function sync_auto_optimize_schedule() {
        $options         = self::get_options();
        $should_schedule = ! empty( $options['auto_optimize_enabled'] ) && ! empty( $options['auto_optimize_cron'] );

        if ( ! $should_schedule ) {
            while ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
                $timestamp = wp_next_scheduled( self::CRON_HOOK );
                if ( false === $timestamp ) {
                    break;
                }
                wp_unschedule_event( (int) $timestamp, self::CRON_HOOK );
            }
            return;
        }

        $interval = isset( $options['auto_optimize_interval'] ) ? $options['auto_optimize_interval'] : 'hourly';
        $event    = wp_get_scheduled_event( self::CRON_HOOK );

        if ( $event && isset( $event->schedule ) && $event->schedule !== $interval ) {
            wp_unschedule_event( (int) $event->timestamp, self::CRON_HOOK );
            $event = false;
        }

        if ( ! $event ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::CRON_HOOK );
        }
    }

    /**
     * Process a small optimization batch when admin pages are accessed.
     *
     * @return void
     */
    public static function maybe_auto_optimize_on_admin_access() {
        if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        $options = self::get_options();
        if ( empty( $options['auto_optimize_enabled'] ) || empty( $options['auto_optimize_admin'] ) ) {
            return;
        }

        if ( get_transient( self::ADMIN_TICK_LOCK ) ) {
            return;
        }

        set_transient( self::ADMIN_TICK_LOCK, 1, MINUTE_IN_SECONDS * 5 );
        self::run_auto_optimize_batch( 'admin' );
    }

    /**
     * Cron callback for background auto optimization.
     *
     * @return void
     */
    public static function run_auto_optimize_cron() {
        self::run_auto_optimize_batch( 'cron' );
    }

    /**
     * Execute one small auto optimization batch.
     *
     * @param string $context Trigger context.
     *
     * @return void
     */
    private static function run_auto_optimize_batch( $context ) {
        if ( ! self::imagick_supports_heic() ) {
            return;
        }

        $options = self::get_options();
        $limit   = isset( $options['auto_optimize_batch'] ) ? (int) $options['auto_optimize_batch'] : 3;
        $limit   = min( 25, max( 1, $limit ) );

        $query = new WP_Query(
            array(
                'post_type'              => 'attachment',
                'post_status'            => 'inherit',
                'posts_per_page'         => $limit,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'post_mime_type'         => self::SOURCE_MIMES,
                'meta_query'             => array(
                    array(
                        'key'     => self::BACKUP_META_KEY,
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            )
        );

        if ( empty( $query->posts ) ) {
            return;
        }

        TIMU_HEIC_Backup_Adapter::snapshot( 'HEIC auto optimize', array() );

        foreach ( $query->posts as $attachment_id ) {
            self::convert_image( (int) $attachment_id, self::get_quality_setting() );
        }
    }

    /**
     * Build reporting metrics for a selected date window.
     *
     * @param string $range_key Date range key.
     *
     * @return array
     */
    private static function get_report_metrics( $range_key ) {
        $now   = time();
        $start = 0;

        switch ( $range_key ) {
            case '30d':
                $start = $now - ( 30 * DAY_IN_SECONDS );
                break;
            case '90d':
                $start = $now - ( 90 * DAY_IN_SECONDS );
                break;
            case '365d':
                $start = $now - ( 365 * DAY_IN_SECONDS );
                break;
            case 'all':
            default:
                $start = 0;
                break;
        }

        $query = new WP_Query(
            array(
                'post_type'              => 'attachment',
                'post_status'            => 'inherit',
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'post_mime_type'         => 'image/webp',
                'meta_query'             => array(
                    array(
                        'key'     => self::BACKUP_META_KEY,
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );

        $converted_count = 0;
        $bytes_saved     = 0;

        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $attachment_id ) {
                $backup_ref = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );
                if ( ! $backup_ref ) {
                    continue;
                }

                $converted_at = (int) get_post_meta( $attachment_id, self::CONVERTED_AT_KEY, true );
                if ( $start > 0 && ( $converted_at <= 0 || $converted_at < $start ) ) {
                    continue;
                }

                ++$converted_count;
                $bytes_saved += (int) get_post_meta( $attachment_id, self::SAVINGS_META_KEY, true );
            }
        }

        $options         = self::get_options();
        $monthly_hits    = isset( $options['report_monthly_image_hits'] ) ? (int) $options['report_monthly_image_hits'] : 0;
        $cost_per_gb     = isset( $options['report_bandwidth_cost_gb'] ) ? (float) $options['report_bandwidth_cost_gb'] : 0.0;
        $avg_saved_bytes = $converted_count > 0 ? ( $bytes_saved / $converted_count ) : 0;
        $gb_per_month    = ( $avg_saved_bytes * $monthly_hits ) / ( 1024 * 1024 * 1024 );
        $monthly_roi     = $gb_per_month * $cost_per_gb;

        return array(
            'range'           => $range_key,
            'converted_count' => $converted_count,
            'bytes_saved'     => $bytes_saved,
            'gb_saved'        => $bytes_saved / ( 1024 * 1024 * 1024 ),
            'avg_saved_kb'    => $avg_saved_bytes / 1024,
            'monthly_hits'    => $monthly_hits,
            'cost_per_gb'     => $cost_per_gb,
            'monthly_roi'     => $monthly_roi,
            'annual_roi'      => $monthly_roi * 12,
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

        // Extra Vault/Shadow safety snapshot of the original before it moves.
        TIMU_HEIC_Backup_Adapter::snapshot( 'HEIC upload convert', array( $source_path ) );

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
                'backup'    => self::relativize_backup_path( $backup_path ),
                'savings'   => (int) $savings,
                'converted' => time(),
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
        update_post_meta( $attachment_id, self::CONVERTED_AT_KEY, isset( $stashed['converted'] ) ? (int) $stashed['converted'] : time() );
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

        // Extra Vault/Shadow safety snapshot before touching the source file.
        TIMU_HEIC_Backup_Adapter::snapshot( 'HEIC optimize #' . $attachment_id, array( $full_path ) );

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
        update_post_meta( $attachment_id, self::CONVERTED_AT_KEY, time() );
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

        // Snapshot the current converted file before restore removes it.
        TIMU_HEIC_Backup_Adapter::snapshot( 'HEIC restore #' . $attachment_id, array( $current_webp ) );

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
        delete_post_meta( $attachment_id, self::CONVERTED_AT_KEY );

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

        // Take one Vault/Shadow safety snapshot per short run window. Re-running
        // full backups for every AJAX chunk can cause long delays/timeouts.
        $backup_lock_key = self::BATCH_BACKUP_LOCK . '_' . get_current_user_id();
        if ( ! get_transient( $backup_lock_key ) ) {
            TIMU_HEIC_Backup_Adapter::snapshot( 'HEIC batch conversion', array() );
            set_transient( $backup_lock_key, 1, 15 * MINUTE_IN_SECONDS );
        }

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
     * AJAX callback: convert one HEIC/HEIF image.
     *
     * @return void
     */
    public static function ajax_bulk_optimize() {
        check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-heic-support' ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
        if ( ! $attachment_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-heic-support' ) );
        }

        $result = self::convert_image( $attachment_id, self::get_quality_setting() );

        if ( true === $result ) {
            wp_send_json_success(
                array(
                    'filename' => basename( (string) get_attached_file( $attachment_id ) ),
                    'thumb'    => wp_get_attachment_image( $attachment_id, array( 50, 50 ) ),
                )
            );
        }

        wp_send_json_error( is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error.', 'thisismyurl-heic-support' ) );
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

        $allowed_tabs = array( 'optimize', 'settings', 'report' );
        $active_tab   = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'optimize'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
            $active_tab = 'optimize';
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
                        'single'  => 'timu_heic_optimize',
                        'batch'   => 'timu_heic_process_batch',
                        'restore' => 'timu_heic_restore_single',
                    ),
                    'batchSize'  => self::get_batch_size_setting(),
                    'perPage'    => (int) $options['list_per_page'],
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

        $base_url        = admin_url( 'tools.php?page=heic-optimizer' );
        $optimize_url    = $base_url . '&tab=optimize';
        $settings_url    = $base_url . '&tab=settings';
        $report_url      = $base_url . '&tab=report';
        $thisismyurl_url = self::get_thisismyurl_link( 'https://thisismyurl.com/', 'plugin_header' );
        $donate_url      = self::get_thisismyurl_link( 'https://thisismyurl.com/donate/', 'plugin_sidebar_donate' );

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'HEIC Support', 'thisismyurl-heic-support' ); ?>
                <span style="font-size:0.5em;font-weight:normal;vertical-align:middle;margin-left:10px;color:#646970;">
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            /* translators: %s: link to thisismyurl.com */
                            __( 'by %s', 'thisismyurl-heic-support' ),
                            '<a href="' . esc_url( $thisismyurl_url ) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:none;color:inherit;">thisismyurl.com</a>'
                        )
                    );
                    ?>
                </span>
            </h1>

            <?php if ( ! $heic_ok ) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'Imagick with HEIC support is not available on this server. Conversion is disabled until libheif-enabled Imagick is installed; uploads are left as HEIC.', 'thisismyurl-heic-support' ); ?></p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper wp-clearfix">
                <a href="<?php echo esc_url( $optimize_url ); ?>" class="nav-tab<?php echo 'optimize' === $active_tab ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Optimize', 'thisismyurl-heic-support' ); ?>
                    <?php if ( ! empty( $pending_ids ) ) : ?>
                        <span class="awaiting-mod" style="margin-left:4px;"><?php echo esc_html( count( $pending_ids ) ); ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url( $settings_url ); ?>" class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'thisismyurl-heic-support' ); ?>
                </a>
                <a href="<?php echo esc_url( $report_url ); ?>" class="nav-tab<?php echo 'report' === $active_tab ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Report', 'thisismyurl-heic-support' ); ?>
                </a>
            </nav>

            <?php if ( 'optimize' === $active_tab ) : ?>

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Conversion Dashboard', 'thisismyurl-heic-support' ); ?></span></h2>
                            <div class="inside">
                                <div style="padding:10px 0;min-height:80px;">
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

                                    <div id="fwo-progress-container"
                                        role="progressbar"
                                        aria-label="<?php esc_attr_e( 'Conversion progress', 'thisismyurl-heic-support' ); ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        aria-valuenow="0"
                                        style="display:none;margin-top:20px;background:#f0f0f1;height:30px;position:relative;border-radius:4px;overflow:hidden;border:1px solid #c3c4c7;">
                                        <div id="fwo-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.2s;"></div>
                                        <div id="fwo-progress-text" style="position:absolute;width:100%;text-align:center;top:0;line-height:30px;font-weight:bold;color:#fff;mix-blend-mode:difference;">0%</div>
                                    </div>
                                    <div id="fwo-progress-status" role="status" aria-live="polite" class="screen-reader-text"></div>
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
                                                        <strong style="color:#d63638;"><span aria-hidden="true">&#9888; </span><?php esc_html_e( 'File Missing', 'thisismyurl-heic-support' ); ?></strong>
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

                    </div><!-- #post-body-content -->

                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'About', 'thisismyurl-heic-support' ); ?></span></h2>
                            <div class="inside">
                                <p><?php esc_html_e( 'Converts HEIC/HEIF images from Apple devices to WebP using Imagick. New uploads can be converted automatically, and existing library images can be converted in bulk. Originals are backed up and can be restored any time.', 'thisismyurl-heic-support' ); ?></p>
                                <?php if ( ! empty( $restorable ) ) : ?>
                                    <hr />
                                    <p><strong><?php esc_html_e( 'Bulk Actions', 'thisismyurl-heic-support' ); ?></strong></p>
                                    <button id="btn-restore-all" class="button button-secondary" style="width:100%;text-align:center;" data-ids="<?php echo esc_attr( wp_json_encode( $restorable ) ); ?>">
                                        <?php esc_html_e( 'Restore All Originals', 'thisismyurl-heic-support' ); ?>
                                    </button>
                                <?php endif; ?>
                                <hr />
                                <p>
                                    <?php
                                    echo wp_kses_post(
                                        sprintf(
                                            /* translators: %s: link to thisismyurl.com */
                                            __( 'Provided free by %s.', 'thisismyurl-heic-support' ),
                                            '<a href="' . esc_url( $thisismyurl_url ) . '" target="_blank" rel="noopener noreferrer">thisismyurl.com</a>'
                                        )
                                    );
                                    ?>
                                </p>
                                <p><a href="<?php echo esc_url( $donate_url ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer" style="width:100%;text-align:center;"><?php esc_html_e( 'Donate to Development', 'thisismyurl-heic-support' ); ?></a></p>
                            </div>
                        </div>
                    </div><!-- #postbox-container-1 -->

                </div><!-- #post-body -->
            </div><!-- #poststuff -->

            <?php elseif ( 'settings' === $active_tab ) : /* settings tab */ ?>

            <div id="poststuff" style="padding-top:10px;">
                <div id="post-body" class="metabox-holder columns-1">
                    <div id="post-body-content">

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Conversion Settings', 'thisismyurl-heic-support' ); ?></span></h2>
                            <div class="inside">
                                <form method="post" action="options.php">
                                    <?php settings_fields( self::SETTINGS_GROUP ); ?>
                                    <table class="form-table" role="presentation">
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Quality Preset', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <fieldset>
                                                <legend class="screen-reader-text"><?php esc_html_e( 'Quality Preset', 'thisismyurl-heic-support' ); ?></legend>
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
                                                </fieldset>
                                                <div id="timu-custom-quality" style="margin-top:8px;<?php echo 'custom' !== $cur_preset ? 'display:none;' : ''; ?>">
                                                    <label for="timu-quality"><?php esc_html_e( 'Custom quality (0–100):', 'thisismyurl-heic-support' ); ?></label>
                                                    <input id="timu-quality" type="number" min="0" max="100"
                                                        name="<?php echo esc_attr( self::OPTION_KEY ); ?>[quality]"
                                                        value="<?php echo esc_attr( $options['quality'] ); ?>"
                                                        class="small-text" style="margin-left:6px;" />
                                                </div>
                                                <p class="description"><?php esc_html_e( 'WebP encoder quality. Web is the balanced default; Lossless preserves every pixel at a larger file size.', 'thisismyurl-heic-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="timu-batch-size"><?php esc_html_e( 'Batch Size', 'thisismyurl-heic-support' ); ?></label></th>
                                            <td>
                                                <input id="timu-batch-size" type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]" value="<?php echo esc_attr( $options['batch_size'] ); ?>" class="small-text" />
                                                <p class="description"><?php esc_html_e( 'Images processed per AJAX request. Lower this if you see timeouts. Default: 10.', 'thisismyurl-heic-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Convert on Upload', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_convert_uploads]" value="1" <?php checked( ! empty( $options['auto_convert_uploads'] ) ); ?> />
                                                    <?php esc_html_e( 'Convert new HEIC/HEIF uploads to WebP automatically.', 'thisismyurl-heic-support' ); ?>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Auto Optimize', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <fieldset>
                                                    <label style="display:block;margin-bottom:6px;">
                                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_enabled]" value="1" <?php checked( ! empty( $options['auto_optimize_enabled'] ) ); ?> />
                                                        <?php esc_html_e( 'Enable automatic background conversion for pending HEIC/HEIF images.', 'thisismyurl-heic-support' ); ?>
                                                    </label>
                                                    <label style="display:block;margin-bottom:6px;">
                                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_admin]" value="1" <?php checked( ! empty( $options['auto_optimize_admin'] ) ); ?> />
                                                        <?php esc_html_e( 'Run a small conversion batch during wp-admin page visits.', 'thisismyurl-heic-support' ); ?>
                                                    </label>
                                                    <label style="display:block;margin-bottom:10px;">
                                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_cron]" value="1" <?php checked( ! empty( $options['auto_optimize_cron'] ) ); ?> />
                                                        <?php esc_html_e( 'Run conversion in WP-Cron.', 'thisismyurl-heic-support' ); ?>
                                                    </label>
                                                    <p>
                                                        <label for="timu-auto-batch" style="margin-right:8px;"><?php esc_html_e( 'Images per auto run:', 'thisismyurl-heic-support' ); ?></label>
                                                        <input id="timu-auto-batch" type="number" min="1" max="25" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_batch]" value="<?php echo esc_attr( $options['auto_optimize_batch'] ); ?>" class="small-text" />
                                                    </p>
                                                    <p>
                                                        <label for="timu-auto-interval" style="margin-right:8px;"><?php esc_html_e( 'WP-Cron interval:', 'thisismyurl-heic-support' ); ?></label>
                                                        <select id="timu-auto-interval" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_interval]">
                                                            <option value="fifteen_minutes" <?php selected( 'fifteen_minutes', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Every 15 minutes', 'thisismyurl-heic-support' ); ?></option>
                                                            <option value="hourly" <?php selected( 'hourly', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Hourly', 'thisismyurl-heic-support' ); ?></option>
                                                            <option value="twicedaily" <?php selected( 'twicedaily', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Twice Daily', 'thisismyurl-heic-support' ); ?></option>
                                                            <option value="daily" <?php selected( 'daily', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Daily', 'thisismyurl-heic-support' ); ?></option>
                                                        </select>
                                                    </p>
                                                    <p class="description"><?php esc_html_e( 'Enable one or both triggers: admin traffic, cron, or both.', 'thisismyurl-heic-support' ); ?></p>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="timu-per-page"><?php esc_html_e( 'Items Per Page', 'thisismyurl-heic-support' ); ?></label></th>
                                            <td>
                                                <input id="timu-per-page" type="number" min="5" max="500" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[list_per_page]" value="<?php echo esc_attr( $options['list_per_page'] ); ?>" class="small-text" />
                                                <p class="description"><?php esc_html_e( 'How many images to show per page in the Pending and Managed Media lists. Default: 25.', 'thisismyurl-heic-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Report Assumptions', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <p>
                                                    <label for="timu-monthly-hits" style="display:inline-block;min-width:240px;"><?php esc_html_e( 'Estimated monthly image requests', 'thisismyurl-heic-support' ); ?></label>
                                                    <input id="timu-monthly-hits" type="number" min="0" max="100000000" step="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[report_monthly_image_hits]" value="<?php echo esc_attr( $options['report_monthly_image_hits'] ); ?>" class="regular-text" style="max-width:180px;" />
                                                </p>
                                                <p>
                                                    <label for="timu-cost-gb" style="display:inline-block;min-width:240px;"><?php esc_html_e( 'Bandwidth cost per GB (USD)', 'thisismyurl-heic-support' ); ?></label>
                                                    <input id="timu-cost-gb" type="number" min="0" max="10" step="0.01" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[report_bandwidth_cost_gb]" value="<?php echo esc_attr( $options['report_bandwidth_cost_gb'] ); ?>" class="regular-text" style="max-width:180px;" />
                                                </p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Outbound UTM Parameters', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[track_outbound_utms]" value="1" <?php checked( ! empty( $options['track_outbound_utms'] ) ); ?> />
                                                    <?php esc_html_e( 'Add privacy-safe UTM parameters to links to thisismyurl.com.', 'thisismyurl-heic-support' ); ?>
                                                </label>
                                                <p class="description"><?php esc_html_e( 'These UTMs include no site IDs, account IDs, user IDs, visitor data, or domain names. They only identify this plugin as the traffic source.', 'thisismyurl-heic-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'On Uninstall', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_backups_uninstall]" value="1" <?php checked( ! empty( $options['delete_backups_uninstall'] ) ); ?> />
                                                    <?php esc_html_e( 'Delete all backup files when the plugin is uninstalled.', 'thisismyurl-heic-support' ); ?>
                                                </label>
                                                <p class="description"><?php esc_html_e( 'Leave unchecked if you want to keep originals in the backup directory even after removing the plugin.', 'thisismyurl-heic-support' ); ?></p>
                                            </td>
                                        </tr>
                                    </table>

                                    <?php submit_button( __( 'Save Settings', 'thisismyurl-heic-support' ) ); ?>
                                </form>
                            </div>
                        </div>

                    </div><!-- #post-body-content -->
                </div><!-- #post-body -->
            </div><!-- #poststuff -->

            <?php else : /* report tab */ ?>

            <?php
            $report_range = isset( $_GET['range'] ) ? sanitize_key( (string) $_GET['range'] ) : '30d'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! in_array( $report_range, array( '30d', '90d', '365d', 'all' ), true ) ) {
                $report_range = '30d';
            }
            $report_data = self::get_report_metrics( $report_range );
            ?>

            <div id="poststuff" style="padding-top:10px;">
                <div id="post-body" class="metabox-holder columns-1">
                    <div id="post-body-content">
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Business ROI Report', 'thisismyurl-heic-support' ); ?></span></h2>
                            <div class="inside">
                                <p class="description"><?php esc_html_e( 'Use these metrics to show the measurable value this plugin has provided over business-friendly time windows.', 'thisismyurl-heic-support' ); ?></p>
                                <p>
                                    <a class="button <?php echo '30d' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => '30d' ), $base_url ) ); ?>"><?php esc_html_e( 'Last 30 Days', 'thisismyurl-heic-support' ); ?></a>
                                    <a class="button <?php echo '90d' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => '90d' ), $base_url ) ); ?>"><?php esc_html_e( 'Last 90 Days', 'thisismyurl-heic-support' ); ?></a>
                                    <a class="button <?php echo '365d' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => '365d' ), $base_url ) ); ?>"><?php esc_html_e( 'Last 12 Months', 'thisismyurl-heic-support' ); ?></a>
                                    <a class="button <?php echo 'all' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => 'all' ), $base_url ) ); ?>"><?php esc_html_e( 'All Time', 'thisismyurl-heic-support' ); ?></a>
                                </p>

                                <table class="widefat striped" style="max-width:960px;">
                                    <tbody>
                                        <tr>
                                            <th style="width:340px;"><?php esc_html_e( 'Images Converted in Period', 'thisismyurl-heic-support' ); ?></th>
                                            <td><?php echo esc_html( number_format_i18n( (int) $report_data['converted_count'] ) ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Total Bandwidth Saved (if each image is requested once)', 'thisismyurl-heic-support' ); ?></th>
                                            <td><?php echo esc_html( size_format( (int) $report_data['bytes_saved'], 2 ) ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Average Savings per Image', 'thisismyurl-heic-support' ); ?></th>
                                            <td><?php echo esc_html( number_format_i18n( (float) $report_data['avg_saved_kb'], 2 ) . ' KB' ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Estimated Monthly ROI', 'thisismyurl-heic-support' ); ?></th>
                                            <td>
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        /* translators: 1: monthly savings, 2: annual savings */
                                                        __( '$%1$s / month (about $%2$s / year)', 'thisismyurl-heic-support' ),
                                                        number_format_i18n( (float) $report_data['monthly_roi'], 2 ),
                                                        number_format_i18n( (float) $report_data['annual_roi'], 2 )
                                                    )
                                                );
                                                ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p class="description" style="margin-top:10px;">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: 1: image hit count, 2: cost per GB */
                                            __( 'ROI estimate uses %1$s image requests/month and $%2$s bandwidth cost per GB from your settings.', 'thisismyurl-heic-support' ),
                                            number_format_i18n( (int) $report_data['monthly_hits'] ),
                                            number_format_i18n( (float) $report_data['cost_per_gb'], 2 )
                                        )
                                    );
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div><!-- .wrap -->
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

register_activation_hook( __FILE__, array( 'TIMU_HEIC_Support', 'activate_plugin' ) );
register_deactivation_hook( __FILE__, array( 'TIMU_HEIC_Support', 'deactivate_plugin' ) );

TIMU_HEIC_Support::init();

// GitHub updater integration.
add_action(
    'plugins_loaded',
    static function () {
        $updater_path = plugin_dir_path( __FILE__ ) . 'github-updater.php';
        if ( ! file_exists( $updater_path ) ) {
            return;
        }

        require_once $updater_path;
        if ( ! class_exists( '\ThisIsMyURL\HEIC\GitHubReleaseUpdater' ) ) {
            return;
        }

        \ThisIsMyURL\HEIC\GitHubReleaseUpdater::boot(
            array(
                'plugin_file' => __FILE__,
                'slug'        => 'thisismyurl-heic-support',
                'repo'        => 'thisismyurl/thisismyurl-heic-support',
            )
        );
    }
);
