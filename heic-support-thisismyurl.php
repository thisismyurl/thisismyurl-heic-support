<?php
/**
 * TIMU HEIC Support Plugin
 *
 * This plugin enables support for High Efficiency Image Container (HEIC/HEIF) uploads 
 * within the WordPress Media Library. It utilizes the TIMU Shared Core to facilitate 
 * server-side conversion into raster formats like WebP or AVIF via Imagick.
 *
 * @package    TIMU_HEIC_Support
 * @author     Christopher Ross <https://thisismyurl.com/>
 * @version    1.260102
 * @license    GPL-2.0+
 */

/**
 * Security: Prevent direct file access to mitigate path traversal or unauthorized execution.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version-aware Core Loader
 *
 * Loads the base TIMU_Core_v1 class. The use of class_exists is a defensive programming 
 * measure to ensure the shared library is initialized only once in a multi-plugin suite.
 */
function timu_heic_support_load_core() {
	$core_path = plugin_dir_path( __FILE__ ) . 'core/class-timu-core.php';
	if ( ! class_exists( 'TIMU_Core_v1' ) ) {
		require_once $core_path;
	}
}
timu_heic_support_load_core();

/**
 * Class TIMU_HEIC_Support
 *
 * Extends TIMU_Core_v1 to leverage centralized settings generation and image conversion. 
 * Implements specific logic for HEIC MIME type handling and upload interception.
 */
class TIMU_HEIC_Support extends TIMU_Core_v1 {

	/**
	 * Constructor: Initializes plugin properties and WordPress hooks.
	 *
	 * Passes configuration parameters to the parent core constructor to establish 
	 * uniform admin asset enqueuing and settings groups.
	 */
	public function __construct() {
		parent::__construct(
			'thisismyurl-heic-support',      // Unique plugin slug.
			plugin_dir_url( __FILE__ ),       // Base URL for plugin assets.
			'timu_hs_settings_group',         // Settings API group name.
			'',                               // Custom icon URL (optional).
			'tools.php'                       // Admin menu parent location.
		);

		/**
		 * Hook: Configuration setup for the settings generator.
		 */
		add_action( 'init', array( $this, 'setup_plugin' ) );

		/**
		 * Filters: Lifecycle hooks for expanding MIME support and processing uploads.
		 */
		add_filter( 'upload_mimes', array( $this, 'allow_heic_uploads' ) );
		add_filter( 'wp_handle_upload', array( $this, 'handle_heic_upload' ) );

		/**
		 * Action: Dedicated management page registration.
		 */
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		/**
		 * Activation: Define default options to ensure an immediate working state.
		 */
		register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );
	}

	/**
	 * Default Option Initialization
	 *
	 * Ensures the database contains baseline settings upon plugin activation 
	 * without overwriting existing user configurations.
	 */
	public function activate_plugin_defaults() {
		$option_name = "{$this->plugin_slug}_options";
		if ( false === get_option( $option_name ) ) {
			update_option( $option_name, array(
				'enabled'       => 1,
				'target_format' => 'webp', // Default to widely supported WebP.
			) );
		}
	}

	/**
	 * Configuration Blueprint
	 *
	 * Defines the settings schema for the Core's automated UI engine. 
	 * Utilizes cascading visibility (is_parent/parent) to dynamically 
	 * show/hide conversion settings based on user selection.
	 */
	public function setup_plugin() {
		/** @var bool $webp_active Dependency check for sibling WebP plugin. */
		$webp_active = class_exists( 'TIMU_WebP_Support' );
		/** @var bool $avif_active Dependency check for sibling AVIF plugin. */
		$avif_active = class_exists( 'TIMU_AVIF_Support' );

		/**
		 * Build radio options dynamically based on the presence of sibling plugins.
		 */
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
					'enabled'       => array(
						'type'      => 'switch',
						'label'     => __( 'Enable HEIC Uploads', 'thisismyurl-heic-support' ),
						'desc'      => __( 'Allows .heic files in the Media Library.', 'thisismyurl-heic-support' ),
						'is_parent' => true,
						'default'   => 1,
					),
					'target_format' => array(
						'type'      => 'radio',
						'label'     => __( 'Conversion Format', 'thisismyurl-heic-support' ),
						'parent'    => 'enabled',
						'is_parent' => true,
						'options'   => $format_options,
						'default'   => $webp_active ? 'webp' : 'none',
						'desc'      => ( ! $webp_active || ! $avif_active )
									? __( 'Install <a href="https://thisismyurl.com/thisismyurl-webp-support/">WebP</a> or <a href="https://thisismyurl.com/thisismyurl-avif-support/">AVIF</a> plugins for more options.', 'thisismyurl-heic-support' )
									: __( 'Choose how to process HEIC files upon upload.', 'thisismyurl-heic-support' ),
					),
					'webp_quality'  => array(
						'type'         => 'number',
						'label'        => __( 'WebP Quality', 'thisismyurl-heic-support' ),
						'parent'       => 'target_format',
						'parent_value' => 'webp',
						'default'      => 80,
					),
					'avif_quality'  => array(
						'type'         => 'number',
						'label'        => __( 'AVIF Quality', 'thisismyurl-heic-support' ),
						'parent'       => 'target_format',
						'parent_value' => 'avif',
						'default'      => 60,
					),
				),
			),
		);

		/**
		 * Initialize the Core Settings Generator.
		 */
		$this->init_settings_generator( $blueprint );
	}

	/**
	 * Admin Management Page Entry
	 *
	 * Registers the plugin settings view under the Tools (tools.php) parent menu.
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'HEIC Support Settings', 'thisismyurl-heic-support' ),
			__( 'HEIC Support', 'thisismyurl-heic-support' ),
			'manage_options',
			$this->plugin_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * MIME Type Support Expansion
	 *
	 * Modifies the list of permitted upload formats to include HEIC/HEIF containers.
	 *
	 * @param array $mimes Current allowed MIME types.
	 * @return array Filtered MIME types.
	 */
	public function allow_heic_uploads( $mimes ) {
		if ( 1 === (int) $this->get_plugin_option( 'enabled', 1 ) ) {
			$mimes['heic'] = 'image/heic';
			$mimes['heif'] = 'image/heif';
		}
		return $mimes;
	}

	/**
	 * Upload Handler & Format Routing
	 *
	 * Intercepts the upload lifecycle to determine if an HEIC file should be 
	 * converted to a raster format. Leverages the Core's process_image_conversion 
	 * for memory-safe Imagick processing.
	 *
	 * @param array $upload Standard WordPress upload result array.
	 * @return array Processed upload result.
	 */
	public function handle_heic_upload( $upload ) {
		/**
		 * Validation: Ensure the plugin is active and processing is enabled.
		 */
		if ( 1 !== (int) $this->get_plugin_option( 'enabled', 1 ) ) {
			return $upload;
		}

		$target = $this->get_plugin_option( 'target_format', 'none' );

		/**
		 * Early Exit: If no conversion is desired or the file isn't an HEIC container.
		 */
		if ( 'none' === $target || ! in_array( $upload['type'], array( 'image/heic', 'image/heif' ), true ) ) {
			return $upload;
		}

		/**
		 * Determine compression quality based on the selected target format.
		 */
		$quality = ( 'webp' === $target ) 
			? (int) $this->get_plugin_option( 'webp_quality', 80 ) 
			: (int) $this->get_plugin_option( 'avif_quality', 60 );

		/**
		 * Delegate heavy-lifting to the Shared Core. This centralizes resource 
		 * limits (memory/CPU) and cleanup logic across the suite.
		 */
		return $this->process_image_conversion( $upload, $target, $quality );
	}
}

/**
 * Initialize the HEIC Support plugin.
 */
new TIMU_HEIC_Support();