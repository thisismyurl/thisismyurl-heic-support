<?php
/**
 * Plugin Name:       HEIC Support by Christopher Ross
 * Plugin URI:        https://thisismyurl.com/thisismyurl-heic-support/
 * Description:       Convert HEIC/HEIF images from iOS devices to WebP automatically with non-destructive backups and one-click restore.
 * Version:           1.6190.1640
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       thisismyurl-heic-support
 * Domain Path:       /languages
 * Donate link:       https://github.com/sponsors/thisismyurl
 * @package TIMU_HEIC_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TIMU_HEIC_VERSION' ) ) {
	define( 'TIMU_HEIC_VERSION', '1.6190.1640' );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-backup-adapter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-timu-suite-core.php';

class TIMU_HEIC_Support {

	const AJAX_NONCE_ACTION = 'timu_heic_nonce';
	const BACKUP_META_KEY   = '_timu_heic_original_path';
	const SAVINGS_META_KEY  = '_timu_heic_savings';
	const CONVERTED_AT_KEY  = '_timu_heic_converted_at';
	const OPTION_KEY        = 'timu_heic_support_options';
	const SETTINGS_GROUP    = 'timu_heic_support_settings';
	const BATCH_BACKUP_LOCK = 'timu_heic_batch_backup_lock';
	const TARGET_MIME       = 'image/webp';
	const TARGET_EXT        = 'webp';

	/** MIME types this plugin processes. */
	private static $source_mimes = array( 'image/heic', 'image/heif' );

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_filter( 'upload_mimes', array( __CLASS__, 'allow_heic_uploads' ) );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'allow_heic_filetype' ), 10, 4 );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'maybe_convert_on_upload' ), 99, 2 );
		add_action( 'wp_ajax_timu_heic_process_batch', array( __CLASS__, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_timu_heic_restore_single', array( __CLASS__, 'ajax_restore_single' ) );
		add_action( 'wp_ajax_timu_heic_restore_all', array( __CLASS__, 'ajax_restore_all' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_plugin_action_links' ) );
		add_action( 'admin_post_timu_heic_vortops_save', array( __CLASS__, 'handle_vortops_save' ) );
		TIMU_Suite_Settings::register_ajax_handlers();
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
				'default'           => self::get_default_options(),
				'autoload'          => false,
				'show_in_rest'      => false,
			)
		);
	}

	private static function get_default_options() {
		return array(
			'convert_on_upload'    => 1,
			'batch_size'           => 10,
			'per_page'             => 25,
			'track_utm'            => 1,
			'delete_backups'       => 0,
		);
	}

	public static function get_options() {
		return wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), self::get_default_options() );
	}

	public static function sanitize_options( $input ) {
		$defaults = self::get_default_options();
		$clean    = array();

		$clean['convert_on_upload'] = ! empty( $input['convert_on_upload'] ) ? 1 : 0;
		$clean['track_utm']         = ! empty( $input['track_utm'] ) ? 1 : 0;
		$clean['delete_backups']    = ! empty( $input['delete_backups'] ) ? 1 : 0;

		$clean['batch_size'] = isset( $input['batch_size'] )
			? min( 100, max( 1, (int) $input['batch_size'] ) )
			: $defaults['batch_size'];

		$clean['per_page'] = isset( $input['per_page'] )
			? min( 200, max( 5, (int) $input['per_page'] ) )
			: $defaults['per_page'];

		return $clean;
	}

	public static function handle_vortops_save() {
		check_admin_referer( 'timu_heic_vortops_save', 'timu_heic_vortops_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'thisismyurl-heic-support' ) );
		}
		$api_key = isset( $_POST['timu_vortops_api_key'] )
			? sanitize_text_field( wp_unslash( $_POST['timu_vortops_api_key'] ) )
			: '';
		if ( '' !== $api_key ) {
			update_option( TIMU_Vortops_Client::OPTION_KEY, $api_key, false );
		} else {
			delete_option( TIMU_Vortops_Client::OPTION_KEY );
		}
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'heic-support', 'tab' => 'settings', 'vortops-saved' => '1' ),
			admin_url( 'tools.php' )
		) );
		exit;
	}

	/**
	 * Whether this server can convert HEIC files locally via Imagick + libheif.
	 *
	 * @return bool
	 */
	public static function local_conversion_available() {
		return extension_loaded( 'imagick' )
			&& class_exists( 'Imagick' )
			&& in_array( 'HEIC', Imagick::queryFormats(), true );
	}

	// -------------------------------------------------------------------------
	// Upload handling
	// -------------------------------------------------------------------------

	public static function allow_heic_uploads( $mimes ) {
		$mimes['heic'] = 'image/heic';
		$mimes['heif'] = 'image/heif';
		return $mimes;
	}

	public static function allow_heic_filetype( $data, $file, $filename, $mimes ) {
		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}

		$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'heic', 'heif' ), true ) ) {
			return $data;
		}

		$data['ext']  = $ext;
		$data['type'] = ( 'heif' === $ext ) ? 'image/heif' : 'image/heic';
		return $data;
	}

	public static function maybe_convert_on_upload( $metadata, $attachment_id ) {
		$options = self::get_options();
		if ( empty( $options['convert_on_upload'] ) ) {
			return $metadata;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::$source_mimes, true ) ) {
			return $metadata;
		}

		self::convert_heic_to_webp( (int) $attachment_id );
		return $metadata;
	}

	// -------------------------------------------------------------------------
	// Core conversion
	// -------------------------------------------------------------------------

	public static function convert_heic_to_webp( $attachment_id ) {
		$local_ok = self::local_conversion_available();

		if ( ! $local_ok && ! TIMU_Vortops_Client::is_connected() ) {
			return new WP_Error(
				'no_converter',
				__( 'HEIC conversion requires either Imagick with libheif (a server-level PHP extension) or a Vortops cloud account. Your server does not have Imagick with HEIC support installed — this is a server-level limitation, not a plugin restriction. Connect a free Vortops account in Settings to enable cloud conversion.', 'thisismyurl-heic-support' )
			);
		}

		$full_path = get_attached_file( $attachment_id );
		if ( ! $full_path || ! file_exists( $full_path ) ) {
			return new WP_Error( 'missing', __( 'File does not exist.', 'thisismyurl-heic-support' ) );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::$source_mimes, true ) ) {
			return new WP_Error( 'mime', __( 'Only HEIC/HEIF source files can be converted.', 'thisismyurl-heic-support' ) );
		}

		// Safety snapshot before touching anything.
		TIMU_HEIC_Backup_Adapter::snapshot( 'HEIC convert #' . $attachment_id, array( $full_path ) );

		$original_size = (int) filesize( $full_path );
		$new_path      = preg_replace( '/\.(heic|heif)$/i', '.webp', $full_path );
		$rel_path      = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$new_rel_path  = preg_replace( '/\.(heic|heif)$/i', '.webp', $rel_path );

		// Try local Imagick first (faster, no network); fall back to Vortops if connected.
		$blob   = false;
		$source = 'local';

		if ( $local_ok ) {
			// Imagick directly — wp_get_image_editor doesn't handle HEIC natively.
			try {
				$im = new Imagick( $full_path );
				$im->setImageFormat( 'WEBP' );
				$im->setCompressionQuality( 82 );
				$blob = $im->getImageBlob();
				$im->destroy();
			} catch ( Exception $e ) {
				if ( TIMU_Vortops_Client::is_connected() ) {
					$cloud = TIMU_Vortops_Client::convert( $full_path, $mime );
					if ( is_wp_error( $cloud ) ) {
						return new WP_Error(
							'conversion_failed',
							sprintf(
								/* translators: 1: local error message, 2: cloud error message */
								__( 'Local conversion failed (%1$s) and cloud conversion also failed (%2$s).', 'thisismyurl-heic-support' ),
								$e->getMessage(),
								$cloud->get_error_message()
							)
						);
					}
					$blob   = $cloud;
					$source = 'vortops';
				} else {
					return new WP_Error( 'imagick_error', $e->getMessage() );
				}
			}
		} else {
			$cloud = TIMU_Vortops_Client::convert( $full_path, $mime );
			if ( is_wp_error( $cloud ) ) {
				return $cloud;
			}
			$blob   = $cloud;
			$source = 'vortops';
		}

		if ( false === $blob || '' === $blob ) {
			return new WP_Error( 'empty_blob', __( 'Conversion produced empty output.', 'thisismyurl-heic-support' ) );
		}

		if ( false === file_put_contents( $new_path, $blob ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			return new WP_Error( 'write', __( 'Failed to write WebP output file.', 'thisismyurl-heic-support' ) );
		}

		TIMU_Suite_Event::record( $attachment_id, 'convert', 'heic-to-webp', $source, 'heic-support' );

		// Move original to backup.
		$upload_dir = wp_upload_dir();
		$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'heic-backups/' . $attachment_id . '/';
		if ( ! wp_mkdir_p( $backup_dir ) ) {
			wp_delete_file( $new_path );
			return new WP_Error( 'mkdir', __( 'Unable to create backup directory.', 'thisismyurl-heic-support' ) );
		}

		$backup_path = $backup_dir . basename( $full_path );
		if ( ! rename( $full_path, $backup_path ) ) {
			wp_delete_file( $new_path );
			return new WP_Error( 'move', __( 'Failed to archive the original HEIC file.', 'thisismyurl-heic-support' ) );
		}

		update_post_meta( $attachment_id, self::BACKUP_META_KEY, $backup_path );
		update_post_meta( $attachment_id, self::SAVINGS_META_KEY, max( 0, $original_size - (int) filesize( $new_path ) ) );
		update_post_meta( $attachment_id, self::CONVERTED_AT_KEY, time() );
		update_post_meta( $attachment_id, '_wp_attached_file', $new_rel_path );

		wp_update_post( array(
			'ID'             => $attachment_id,
			'post_mime_type' => self::TARGET_MIME,
		) );

		// Update attachment metadata with new file.
		$meta = wp_generate_attachment_metadata( $attachment_id, $new_path );
		wp_update_attachment_metadata( $attachment_id, $meta );

		return true;
	}

	// -------------------------------------------------------------------------
	// Restore
	// -------------------------------------------------------------------------

	public static function restore_image( $attachment_id ) {
		$backup_path = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );
		if ( ! $backup_path || ! file_exists( $backup_path ) ) {
			return false;
		}

		$current_path = get_attached_file( $attachment_id );
		if ( ! $current_path ) {
			return false;
		}

		$ext           = strtolower( (string) pathinfo( $backup_path, PATHINFO_EXTENSION ) );
		$restored_path = preg_replace( '/\.webp$/i', '.' . $ext, $current_path );

		if ( ! rename( $backup_path, $restored_path ) ) {
			return false;
		}

		if ( $restored_path !== $current_path && file_exists( $current_path ) ) {
			wp_delete_file( $current_path );
		}

		$rel_path = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$new_rel  = preg_replace( '/\.webp$/i', '.' . $ext, $rel_path );
		update_post_meta( $attachment_id, '_wp_attached_file', $new_rel );

		$mime = ( 'heif' === $ext ) ? 'image/heif' : 'image/heic';
		wp_update_post( array(
			'ID'             => $attachment_id,
			'post_mime_type' => $mime,
		) );

		delete_post_meta( $attachment_id, self::BACKUP_META_KEY );
		delete_post_meta( $attachment_id, self::SAVINGS_META_KEY );
		delete_post_meta( $attachment_id, self::CONVERTED_AT_KEY );

		$meta = wp_generate_attachment_metadata( $attachment_id, $restored_path );
		wp_update_attachment_metadata( $attachment_id, $meta );

		return true;
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public static function ajax_process_batch() {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-heic-support' ) );
		}

		$options     = self::get_options();
		$batch_limit = max( 1, min( 100, (int) $options['batch_size'] ) );
		$ids         = isset( $_POST['attachment_ids'] ) ? (array) wp_unslash( $_POST['attachment_ids'] ) : array();
		$ids         = array_slice( array_values( array_filter( array_map( 'absint', $ids ) ) ), 0, $batch_limit );

		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No attachments provided.', 'thisismyurl-heic-support' ) );
		}

		// One Vault/Shadow snapshot per batch window.
		$lock_key = self::BATCH_BACKUP_LOCK . '_' . get_current_user_id();
		if ( ! get_transient( $lock_key ) ) {
			TIMU_HEIC_Backup_Adapter::snapshot( 'HEIC batch conversion', array() );
			set_transient( $lock_key, 1, 15 * MINUTE_IN_SECONDS );
		}

		$processed_ids = array();
		$failed_ids    = array();
		$errors        = array();

		foreach ( $ids as $id ) {
			$result = self::convert_heic_to_webp( $id );
			if ( true === $result ) {
				$processed_ids[] = $id;
			} else {
				$failed_ids[] = $id;
				$errors[]     = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error.', 'thisismyurl-heic-support' );
			}
		}

		wp_send_json_success( array(
			'processed_ids' => $processed_ids,
			'failed_ids'    => $failed_ids,
			'errors'        => array_values( array_unique( $errors ) ),
		) );
	}

	public static function ajax_restore_single() {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-heic-support' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-heic-support' ) );
		}

		if ( self::restore_image( $attachment_id ) ) {
			wp_send_json_success();
		}

		wp_send_json_error( __( 'Image could not be restored.', 'thisismyurl-heic-support' ) );
	}

	public static function ajax_restore_all() {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-heic-support' ) );
		}

		$ids = self::get_managed_ids();
		foreach ( $ids as $id ) {
			self::restore_image( (int) $id );
		}

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Queries
	// -------------------------------------------------------------------------

	private static function get_pending_ids( $limit = 500 ) {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 WHERE p.post_type   = 'attachment'
			   AND p.post_status = 'inherit'
			   AND p.post_mime_type IN ('image/heic','image/heif')
			   AND p.ID NOT IN (
			       SELECT pm.post_id FROM {$wpdb->postmeta} pm
			       WHERE pm.meta_key = %s
			   )
			 ORDER BY p.ID ASC
			 LIMIT %d",
			self::BACKUP_META_KEY,
			absint( $limit )
		) );
	}

	private static function get_managed_ids( $limit = 500 ) {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND p.post_type   = 'attachment'
			   AND p.post_status = 'inherit'
			 ORDER BY pm.post_id ASC
			 LIMIT %d",
			self::BACKUP_META_KEY,
			absint( $limit )
		) );
	}

	// -------------------------------------------------------------------------
	// Report metrics
	// -------------------------------------------------------------------------

	private static function get_report_metrics( $range_key ) {
		global $wpdb;

		$now   = time();
		$since = 0;

		switch ( $range_key ) {
			case '30d':
				$since = $now - 30 * DAY_IN_SECONDS;
				break;
			case '90d':
				$since = $now - 90 * DAY_IN_SECONDS;
				break;
			case '365d':
				$since = $now - 365 * DAY_IN_SECONDS;
				break;
			default:
				$since = 0;
		}

		$where  = $since > 0
			? $wpdb->prepare( ' AND pm_time.meta_value >= %d', $since )
			: '';

		$rows = $wpdb->get_results(
			"SELECT
			    pm_savings.meta_value AS savings,
			    pm_time.meta_value    AS converted_at
			 FROM {$wpdb->postmeta} pm_savings
			 INNER JOIN {$wpdb->postmeta} pm_time
			         ON pm_time.post_id  = pm_savings.post_id
			        AND pm_time.meta_key = '" . esc_sql( self::CONVERTED_AT_KEY ) . "'
			 INNER JOIN {$wpdb->posts} p
			         ON p.ID = pm_savings.post_id
			        AND p.post_type   = 'attachment'
			        AND p.post_status = 'inherit'
			 WHERE pm_savings.meta_key = '" . esc_sql( self::SAVINGS_META_KEY ) . "'"
			. $where
		);

		$count   = count( $rows );
		$savings = 0;
		foreach ( $rows as $row ) {
			$savings += max( 0, (int) $row->savings );
		}

		return array(
			'count'   => $count,
			'savings' => $savings,
		);
	}

	// -------------------------------------------------------------------------
	// Environment preflight
	// -------------------------------------------------------------------------

	private static function preflight_checks() {
		$checks = array();

		$imagick_ok = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$checks[] = array(
			'label'    => __( 'Imagick extension', 'thisismyurl-heic-support' ),
			'pass'     => $imagick_ok,
			'optional' => false,
			'note'     => $imagick_ok ? '' : __( 'Not available on this server. Vortops cloud conversion can be used as an alternative.', 'thisismyurl-heic-support' ),
		);

		$heic_ok = $imagick_ok && in_array( 'HEIC', Imagick::queryFormats(), true );
		$checks[] = array(
			'label'    => __( 'HEIC decoding (libheif)', 'thisismyurl-heic-support' ),
			'pass'     => $heic_ok,
			'optional' => false,
			'note'     => $heic_ok ? '' : __( 'libheif not compiled into Imagick on this server. Vortops cloud conversion can be used as an alternative.', 'thisismyurl-heic-support' ),
		);

		$upload_dir = wp_upload_dir();
		$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'heic-backups/';
		wp_mkdir_p( $backup_dir );
		$dir_ok = wp_is_writable( $backup_dir );
		$checks[] = array(
			'label'    => __( 'Backup directory writable', 'thisismyurl-heic-support' ),
			'pass'     => $dir_ok,
			'optional' => false,
			'note'     => $dir_ok ? '' : sprintf( __( '%s is not writable.', 'thisismyurl-heic-support' ), $backup_dir ),
		);

		$vortops_ok = TIMU_Vortops_Client::is_connected();
		$checks[] = array(
			'label'    => __( 'Vortops cloud conversion', 'thisismyurl-heic-support' ),
			'pass'     => $vortops_ok,
			'optional' => true,
			'note'     => $vortops_ok ? '' : __( 'Optional. Connect a Vortops account in Settings to enable cloud conversion when local Imagick support is unavailable.', 'thisismyurl-heic-support' ),
		);

		return $checks;
	}

	// -------------------------------------------------------------------------
	// Admin menu + assets
	// -------------------------------------------------------------------------

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
		$settings_url = admin_url( 'tools.php?page=heic-optimizer&tab=settings' );
		$sponsor_url  = 'https://github.com/sponsors/thisismyurl';

		$custom = array(
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'thisismyurl-heic-support' ) . '</a>',
			'<a href="' . esc_url( $sponsor_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sponsor', 'thisismyurl-heic-support' ) . '</a>',
		);

		return array_merge( $custom, $links );
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( 'tools_page_heic-optimizer' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'timu-heic-admin',
			plugin_dir_url( __FILE__ ) . 'assets/css/admin.css',
			array(),
			TIMU_HEIC_VERSION
		);

		wp_enqueue_script(
			'timu-heic-admin',
			plugin_dir_url( __FILE__ ) . 'assets/js/admin.js',
			array( 'jquery' ),
			TIMU_HEIC_VERSION,
			true
		);

		$options      = self::get_options();
		$pending_ids  = self::get_pending_ids();
		$managed_ids  = self::get_managed_ids();

		wp_localize_script( 'timu-heic-admin', 'TIMUHeicSupportData', array(
			'pendingIds' => array_values( array_map( 'intval', $pending_ids ) ),
			'nonce'      => wp_create_nonce( self::AJAX_NONCE_ACTION ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'batchSize'  => (int) $options['batch_size'],
			'perPage'    => (int) $options['per_page'],
			'actions'    => array(
				'batch'   => 'timu_heic_process_batch',
				'restore' => 'timu_heic_restore_single',
			),
			'strings'    => array(
				'processing'       => __( 'Processing…', 'thisismyurl-heic-support' ),
				'confirmRestoreAll' => __( 'Restore all images to their original HEIC/HEIF files? Converted WebP files will be removed.', 'thisismyurl-heic-support' ),
				'restoring'        => __( 'Restoring…', 'thisismyurl-heic-support' ),
				'failedPrefix'     => __( 'Some images could not be converted:', 'thisismyurl-heic-support' ),
			),
		) );
	}

	// -------------------------------------------------------------------------
	// Admin page render
	// -------------------------------------------------------------------------

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$base_url    = admin_url( 'tools.php?page=heic-optimizer' );
		$active_tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'optimize';
		$report_range = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30d';
		$valid_tabs  = array( 'optimize', 'settings', 'report' );

		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'optimize';
		}

		$options     = self::get_options();
		$pending_ids = self::get_pending_ids();
		$managed_ids = self::get_managed_ids();
		$sponsor_url = 'https://github.com/sponsors/thisismyurl';
		$checks      = self::preflight_checks();
		$local_ok    = self::local_conversion_available();
		// The Convert button is enabled when at least one conversion path works.
		$env_ok      = $local_ok || TIMU_Vortops_Client::is_connected();

		// Save settings.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['timu_heic_settings_nonce'] ) ) {
			check_admin_referer( 'timu_heic_save_settings', 'timu_heic_settings_nonce' );
			$clean = self::sanitize_options( wp_unslash( $_POST[ self::OPTION_KEY ] ?? array() ) );
			update_option( self::OPTION_KEY, $clean, false );
			$options = $clean;

			add_settings_error( 'timu_heic_support', 'saved', __( 'Settings saved.', 'thisismyurl-heic-support' ), 'updated' );
		}

		settings_errors( 'timu_heic_support' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HEIC Support', 'thisismyurl-heic-support' ); ?></h1>

			<nav class="nav-tab-wrapper wp-clearfix">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'optimize', $base_url ) ); ?>"
				   class="nav-tab <?php echo 'optimize' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Optimize', 'thisismyurl-heic-support' ); ?>
					<?php if ( ! empty( $pending_ids ) ) : ?>
					<span class="timu-count-badge"><?php echo esc_html( count( $pending_ids ) ); ?></span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_url ) ); ?>"
				   class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'thisismyurl-heic-support' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'report', $base_url ) ); ?>"
				   class="nav-tab <?php echo 'report' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Report', 'thisismyurl-heic-support' ); ?>
				</a>
			</nav>

			<?php if ( 'optimize' === $active_tab ) : ?>
			<!-- ── Optimize tab ─────────────────────────────────────────── -->
			<?php if ( ! $local_ok ) : ?>
			<div class="notice <?php echo TIMU_Vortops_Client::is_connected() ? 'notice-info' : 'notice-warning'; ?> inline" style="margin:12px 0 0;">
				<p>
					<?php if ( TIMU_Vortops_Client::is_connected() ) : ?>
					<strong><?php esc_html_e( 'Using Vortops cloud conversion.', 'thisismyurl-heic-support' ); ?></strong>
					<?php esc_html_e( "Your server's Imagick extension does not support HEIC files — this is a server-level limitation, not a plugin restriction. Vortops cloud conversion is connected and will be used for all conversions.", 'thisismyurl-heic-support' ); ?>
					<?php else : ?>
					<strong><?php esc_html_e( 'Local HEIC conversion is not available on this server.', 'thisismyurl-heic-support' ); ?></strong>
					<?php esc_html_e( "Your server's Imagick extension is not compiled with HEIC support (libheif). This is a server-level limitation — this plugin does not restrict it. To convert HEIC files, ask your host to enable libheif, or ", 'thisismyurl-heic-support' ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_url ) ); ?>"><?php esc_html_e( 'connect a free Vortops account', 'thisismyurl-heic-support' ); ?></a>
					<?php esc_html_e( 'to convert in the cloud instead.', 'thisismyurl-heic-support' ); ?>
					<?php endif; ?>
				</p>
			</div>
			<?php endif; ?>
			<div id="post-body" class="metabox-holder columns-2">

				<div id="post-body-content">

					<!-- Conversion dashboard -->
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Conversion Dashboard', 'thisismyurl-heic-support' ); ?></span></h2>
						<div class="inside">
							<div class="fwo-controls" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
								<button id="btn-start" class="button button-primary button-large" <?php disabled( empty( $pending_ids ) || ! $env_ok ); ?>>
									<?php
									/* translators: %d: number of pending HEIC files */
									printf( esc_html( _n( 'Convert All %d HEIC File', 'Convert All %d HEIC Files', count( $pending_ids ), 'thisismyurl-heic-support' ) ), count( $pending_ids ) );
									?>
								</button>
								<button id="btn-cancel" class="button button-secondary button-large" style="display:none;color:#d63638;">
									<?php esc_html_e( 'Cancel', 'thisismyurl-heic-support' ); ?>
								</button>
							</div>
							<?php if ( ! $env_ok ) : ?>
							<p class="description" style="color:#d63638;">
								<?php esc_html_e( 'Environment check failed. See the sidebar for details.', 'thisismyurl-heic-support' ); ?>
							</p>
							<?php endif; ?>
							<div id="fwo-progress-container" style="display:none;margin-top:12px;">
								<div style="background:#e0e0e0;border-radius:4px;height:20px;overflow:hidden;">
									<div id="fwo-progress-bar" style="height:100%;background:#2271b1;width:0%;transition:width 0.3s;"></div>
								</div>
								<p id="fwo-progress-text" style="margin:4px 0 0;color:#646970;font-size:12px;">0%</p>
							</div>
							<p style="margin-top:6px;">
								<?php esc_html_e( 'Pending:', 'thisismyurl-heic-support' ); ?>
								<strong id="p-cnt"><?php echo esc_html( count( $pending_ids ) ); ?></strong>
								&nbsp;|&nbsp;
								<?php esc_html_e( 'Converted:', 'thisismyurl-heic-support' ); ?>
								<strong><?php echo esc_html( count( $managed_ids ) ); ?></strong>
							</p>
						</div>
					</div>

					<!-- Pending files -->
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Pending HEIC/HEIF Files', 'thisismyurl-heic-support' ); ?></span></h2>
						<div class="inside">
							<?php if ( empty( $pending_ids ) ) : ?>
							<p class="no-images"><?php esc_html_e( 'No HEIC/HEIF files pending conversion.', 'thisismyurl-heic-support' ); ?></p>
							<?php else : ?>
							<table id="fwo-pending-table" class="widefat striped">
								<thead><tr>
									<th><?php esc_html_e( 'Preview', 'thisismyurl-heic-support' ); ?></th>
									<th><?php esc_html_e( 'ID', 'thisismyurl-heic-support' ); ?></th>
									<th><?php esc_html_e( 'File', 'thisismyurl-heic-support' ); ?></th>
									<th><?php esc_html_e( 'Size', 'thisismyurl-heic-support' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( array_slice( $pending_ids, 0, 500 ) as $id ) :
										$id        = (int) $id;
										$file      = get_attached_file( $id );
										$filename  = $file ? basename( $file ) : '—';
										$filesize  = $file && file_exists( $file ) ? size_format( filesize( $file ) ) : '—';
										$thumb_url = wp_get_attachment_image_url( $id, 'thumbnail' );
									?>
									<tr id="fwo-row-<?php echo esc_attr( $id ); ?>">
										<td style="width:60px;">
											<?php if ( $thumb_url ) : ?>
											<img src="<?php echo esc_url( $thumb_url ); ?>" style="width:50px;height:50px;object-fit:cover;" alt="">
											<?php else : ?>
											<span class="dashicons dashicons-format-image" style="font-size:40px;color:#646970;"></span>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $id ); ?></td>
										<td><?php echo esc_html( $filename ); ?></td>
										<td><?php echo esc_html( $filesize ); ?></td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<?php endif; ?>
						</div>
					</div>

					<!-- Converted files -->
					<?php if ( ! empty( $managed_ids ) ) : ?>
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Converted Files', 'thisismyurl-heic-support' ); ?></span></h2>
						<div class="inside">
							<table id="fwo-media-table" class="widefat striped">
								<thead><tr>
									<th><?php esc_html_e( 'Preview', 'thisismyurl-heic-support' ); ?></th>
									<th><?php esc_html_e( 'ID', 'thisismyurl-heic-support' ); ?></th>
									<th><?php esc_html_e( 'File', 'thisismyurl-heic-support' ); ?></th>
									<th><?php esc_html_e( 'Saved', 'thisismyurl-heic-support' ); ?></th>
									<th><?php esc_html_e( 'Action', 'thisismyurl-heic-support' ); ?></th>
								</tr></thead>
								<tbody>
									<?php foreach ( array_slice( $managed_ids, 0, 500 ) as $id ) :
										$id        = (int) $id;
										$file      = get_attached_file( $id );
										$filename  = $file ? basename( $file ) : '—';
										$savings   = (int) get_post_meta( $id, self::SAVINGS_META_KEY, true );
										$thumb_url = wp_get_attachment_image_url( $id, 'thumbnail' );
									?>
									<tr id="fwo-row-managed-<?php echo esc_attr( $id ); ?>">
										<td style="width:60px;">
											<?php if ( $thumb_url ) : ?>
											<img src="<?php echo esc_url( $thumb_url ); ?>" style="width:50px;height:50px;object-fit:cover;" alt="">
											<?php else : ?>
											<span class="dashicons dashicons-format-image" style="font-size:40px;color:#646970;"></span>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $id ); ?></td>
										<td><?php echo esc_html( $filename ); ?></td>
										<td><?php echo $savings > 0 ? esc_html( size_format( $savings ) ) : '—'; ?></td>
										<td>
											<button type="button" class="button button-secondary restore-btn"
											        data-id="<?php echo esc_attr( $id ); ?>">
												<?php esc_html_e( 'Restore', 'thisismyurl-heic-support' ); ?>
											</button>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
					<?php endif; ?>

				</div><!-- #post-body-content -->

				<!-- Sidebar -->
				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'About', 'thisismyurl-heic-support' ); ?></span></h2>
						<div class="inside">
							<p><?php esc_html_e( 'Converts HEIC/HEIF uploads to WebP automatically. Originals are backed up and can be restored at any time.', 'thisismyurl-heic-support' ); ?></p>

							<?php if ( ! empty( $managed_ids ) ) : ?>
							<p><strong><?php esc_html_e( 'Bulk Actions', 'thisismyurl-heic-support' ); ?></strong></p>
							<button id="btn-restore-all" class="button button-secondary"
							        style="width:100%;text-align:center;margin-bottom:6px;"
							        data-ids="<?php echo esc_attr( wp_json_encode( array_values( array_map( 'intval', $managed_ids ) ) ) ); ?>">
								<?php esc_html_e( 'Restore All Originals', 'thisismyurl-heic-support' ); ?>
							</button>
							<?php endif; ?>

							<p style="margin-top:12px;">
								<a href="<?php echo esc_url( $sponsor_url ); ?>"
								   class="button button-secondary"
								   target="_blank" rel="noopener noreferrer"
								   style="width:100%;text-align:center;">
									<?php esc_html_e( 'Sponsor development', 'thisismyurl-heic-support' ); ?>
								</a>
							</p>
						</div>
					</div>

					<!-- Environment status -->
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Environment', 'thisismyurl-heic-support' ); ?></span></h2>
						<div class="inside">
							<table class="widefat striped timu-heic-preflight__table">
								<tbody>
									<?php foreach ( $checks as $check ) : ?>
									<tr>
										<td><?php echo esc_html( $check['label'] ); ?>
											<?php if ( ! empty( $check['optional'] ) ) : ?>
											<span style="color:#646970;font-size:11px;"> (<?php esc_html_e( 'optional', 'thisismyurl-heic-support' ); ?>)</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="timu-heic-preflight__indicator timu-heic-preflight__indicator--<?php echo esc_attr( $check['pass'] ? 'pass' : ( ! empty( $check['optional'] ) ? 'info' : 'fail' ) ); ?>" aria-hidden="true"></span>
											<?php echo $check['pass'] ? esc_html__( 'OK', 'thisismyurl-heic-support' ) : esc_html__( 'Not connected', 'thisismyurl-heic-support' ); ?>
											<?php if ( ! $check['pass'] && ! empty( $check['note'] ) ) : ?>
											<p class="description" style="margin:2px 0 0;"><?php echo esc_html( $check['note'] ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div><!-- #postbox-container-1 -->

			</div><!-- #post-body -->

			<?php elseif ( 'settings' === $active_tab ) : ?>
			<!-- ── Settings tab ─────────────────────────────────────────── -->
			<div id="post-body" class="metabox-holder columns-1">
				<div id="post-body-content">
					<form method="post" action="">
						<?php wp_nonce_field( 'timu_heic_save_settings', 'timu_heic_settings_nonce' ); ?>

						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'HEIC Settings', 'thisismyurl-heic-support' ); ?></span></h2>
							<div class="inside">
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><?php esc_html_e( 'Convert on upload', 'thisismyurl-heic-support' ); ?></th>
										<td>
											<input type="checkbox" id="timu_heic_convert_on_upload"
											       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[convert_on_upload]"
											       value="1" <?php checked( 1, $options['convert_on_upload'] ); ?> />
											<label for="timu_heic_convert_on_upload">
												<?php esc_html_e( 'Automatically convert new HEIC/HEIF uploads to WebP.', 'thisismyurl-heic-support' ); ?>
											</label>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="timu_heic_batch_size"><?php esc_html_e( 'Batch size', 'thisismyurl-heic-support' ); ?></label></th>
										<td>
											<input type="number" id="timu_heic_batch_size"
											       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]"
											       value="<?php echo esc_attr( $options['batch_size'] ); ?>"
											       min="1" max="100" class="small-text" />
											<p class="description"><?php esc_html_e( 'Images to convert per AJAX request. Lower values prevent timeouts on slow servers.', 'thisismyurl-heic-support' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="timu_heic_per_page"><?php esc_html_e( 'Items per page', 'thisismyurl-heic-support' ); ?></label></th>
										<td>
											<input type="number" id="timu_heic_per_page"
											       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[per_page]"
											       value="<?php echo esc_attr( $options['per_page'] ); ?>"
											       min="5" max="200" class="small-text" />
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'On uninstall', 'thisismyurl-heic-support' ); ?></th>
										<td>
											<input type="checkbox" id="timu_heic_delete_backups"
											       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_backups]"
											       value="1" <?php checked( 1, $options['delete_backups'] ); ?> />
											<label for="timu_heic_delete_backups">
												<?php esc_html_e( 'Delete backup files when the plugin is uninstalled.', 'thisismyurl-heic-support' ); ?>
											</label>
											<p class="description"><?php esc_html_e( 'Leave unchecked to keep backups even after uninstalling. Backups are in uploads/heic-backups/.', 'thisismyurl-heic-support' ); ?></p>
										</td>
									</tr>
								</table>
								<?php submit_button( __( 'Save settings', 'thisismyurl-heic-support' ) ); ?>
							</div>
						</div>

					</form>
					<?php TIMU_Suite_Settings::render_vortops_postbox( array(
						'save_action'     => 'timu_heic_vortops_save',
						'nonce_action'    => 'timu_heic_vortops_save',
						'nonce_name'      => 'timu_heic_vortops_nonce',
						'redirect_page'   => 'heic-support',
						'field_id'        => 'timu_vortops_api_key_heic',
						'btn_id'          => 'btn-vortops-test-heic',
						'result_id'       => 'vortops-test-result-heic',
						'local_available' => self::local_conversion_available(),
						'local_ok_msg'    => __( 'Your server supports HEIC conversion locally using Imagick. Vortops is optional — connect an account for a cloud backup path.', 'thisismyurl-heic-support' ),
						'gap_msg'         => __( "Your server's Imagick extension does not include libheif support for HEIC files. This is a server-level limitation — this plugin does not restrict it. Connecting a Vortops account enables cloud HEIC-to-WebP conversion as a complete alternative.", 'thisismyurl-heic-support' ),
					) ); ?>
				</div>
			</div>

			<?php elseif ( 'report' === $active_tab ) : ?>
			<!-- ── Report tab ───────────────────────────────────────────── -->
			<div id="post-body" class="metabox-holder columns-1">
				<div id="post-body-content">
					<div class="postbox">
						<h2 class="hndle"><span><?php esc_html_e( 'Conversion Report', 'thisismyurl-heic-support' ); ?></span></h2>
						<div class="inside">
							<p>
								<?php
								$ranges = array(
									'30d'  => __( 'Last 30 days', 'thisismyurl-heic-support' ),
									'90d'  => __( 'Last 90 days', 'thisismyurl-heic-support' ),
									'365d' => __( 'Last 12 months', 'thisismyurl-heic-support' ),
									'all'  => __( 'All time', 'thisismyurl-heic-support' ),
								);
								foreach ( $ranges as $key => $label ) :
									$url = add_query_arg( array( 'tab' => 'report', 'range' => $key ), $base_url );
									$class = 'button ' . ( $key === $report_range ? 'button-primary' : 'button-secondary' );
								?>
								<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
								<?php endforeach; ?>
							</p>

							<?php
							$metrics = self::get_report_metrics( $report_range );
							$count   = $metrics['count'];
							$savings = $metrics['savings'];
							$avg     = $count > 0 ? round( $savings / $count ) : 0;
							?>
							<table class="widefat striped" style="max-width:500px;">
								<tbody>
									<tr>
										<th><?php esc_html_e( 'Files converted', 'thisismyurl-heic-support' ); ?></th>
										<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Total space saved', 'thisismyurl-heic-support' ); ?></th>
										<td><?php echo esc_html( $savings > 0 ? size_format( $savings ) : '—' ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Average savings per file', 'thisismyurl-heic-support' ); ?></th>
										<td><?php echo esc_html( $avg > 0 ? size_format( $avg ) : '—' ); ?></td>
									</tr>
								</tbody>
							</table>

							<?php if ( 0 === $count ) : ?>
							<p class="description" style="margin-top:12px;">
								<?php esc_html_e( 'No conversions recorded in this window. Use the Optimize tab to convert HEIC/HEIF images.', 'thisismyurl-heic-support' ); ?>
							</p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>

		</div><!-- .wrap -->
		<?php
	}
}

TIMU_HEIC_Support::init();

add_action( 'plugins_loaded', function() {
	load_plugin_textdomain( 'thisismyurl-heic-support', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );
