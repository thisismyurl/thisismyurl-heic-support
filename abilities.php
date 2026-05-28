<?php
/**
 * WP 7 Abilities API registration for HEIC Support by thisismyurl.com.
 *
 * Exposes the existing-library HEIC/HEIF to WebP batch conversion as a
 * discoverable, REST/AI-invokable ability. The ability wraps
 * TIMU_HEIC_Support::convert_existing_library() — the same function the
 * admin tooling drives — so there is a single conversion code path.
 *
 * @package TIMU_HEIC_Support
 * @since   0.6148
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // Abilities API unavailable (WordPress < 6.9).
		}

		wp_register_ability(
			'thisismyurl-heic-support/convert',
			array(
				'label'               => __( 'Convert HEIC Library to WebP', 'thisismyurl-heic-support' ),
				'description'         => __( 'Converts HEIC/HEIF images already in the Media Library to WebP, archiving each original in a non-destructive backup folder. Use when an agent needs to optimise an existing library of Apple-device uploads. Process the whole library, or pass a limit to convert one batch at a time.', 'thisismyurl-heic-support' ),
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Maximum number of attachments to process this run. Use 0 (the default) to process the entire library.', 'thisismyurl-heic-support' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'converted', 'skipped', 'errors' ),
					'properties'           => array(
						'converted' => array(
							'type'        => 'integer',
							'description' => __( 'Number of attachments converted to WebP.', 'thisismyurl-heic-support' ),
						),
						'skipped'   => array(
							'type'        => 'integer',
							'description' => __( 'Number of attachments skipped (missing file or already converted).', 'thisismyurl-heic-support' ),
						),
						'errors'    => array(
							'type'        => 'integer',
							'description' => __( 'Number of attachments that failed to convert.', 'thisismyurl-heic-support' ),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input = array() ) {
					if ( ! class_exists( 'TIMU_HEIC_Support' ) ) {
						return new WP_Error(
							'heic_unavailable',
							__( 'The HEIC Support conversion engine is not loaded.', 'thisismyurl-heic-support' )
						);
					}

					$input = is_array( $input ) ? $input : array();
					$limit = isset( $input['limit'] ) ? max( 0, absint( $input['limit'] ) ) : 0;

					return TIMU_HEIC_Support::convert_existing_library( $limit );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						// Not destructive: each original HEIC/HEIF is moved into
						// /uploads/heic-backups/ before the WebP is written, and
						// the backup path is recorded in _heic_original_path, so
						// the operation is fully reversible.
						'destructive' => false,
						// Not idempotent: a second run converts any newly added
						// HEIC/HEIF attachments, changing the resulting counts.
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}
);
