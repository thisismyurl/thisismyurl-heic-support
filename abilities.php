<?php
/**
 * WP 7 Abilities API registration for HEIC Support by thisismyurl.com.
 *
 * Exposes the plugin's non-destructive batch conversion and one-click restore
 * operations as discoverable, REST/AI-invokable abilities. Both wrap the same
 * static methods the WP-CLI commands and admin tooling call
 * (TIMU_HEIC_Support::convert_existing_library() / ::restore_image()), so there
 * is one code path per operation regardless of the invoking surface.
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
				'description'         => __( 'Batch-converts HEIC/HEIF images already in the Media Library to WebP, archiving each original in a non-destructive backup folder before replacing it. The operation is reversible via the restore ability. Process the whole library, or pass a limit to convert one batch at a time.', 'thisismyurl-heic-support' ),
				'category'            => 'site',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Maximum number of pending attachments to convert this run. Use 0 (the default) to convert every pending attachment.', 'thisismyurl-heic-support' ),
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'converted', 'skipped', 'errors', 'space_saved_bytes' ),
					'properties'           => array(
						'converted'         => array(
							'type'        => 'integer',
							'description' => __( 'Number of attachments successfully converted to WebP.', 'thisismyurl-heic-support' ),
						),
						'skipped'           => array(
							'type'        => 'integer',
							'description' => __( 'Number of pending attachments left unprocessed because the limit was reached.', 'thisismyurl-heic-support' ),
						),
						'errors'            => array(
							'type'        => 'integer',
							'description' => __( 'Number of attachments that failed to convert.', 'thisismyurl-heic-support' ),
						),
						'space_saved_bytes' => array(
							'type'        => 'integer',
							'description' => __( 'Total bytes saved across the attachments converted in this batch.', 'thisismyurl-heic-support' ),
						),
						'error_messages'    => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
							),
							'description' => __( 'Human-readable failure messages, one per failed attachment.', 'thisismyurl-heic-support' ),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function ( $input = array() ) {
					if ( ! class_exists( 'TIMU_HEIC_Support' ) ) {
						return new WP_Error( 'heic_unavailable', __( 'The HEIC Support conversion engine is not loaded.', 'thisismyurl-heic-support' ) );
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
						'readonly'    => false, // Writes new WebP files and post meta.
						'destructive' => false, // Originals are backed up before conversion; restore reverses it. Non-destructive by design.
						'idempotent'  => false, // Each call converts whatever is pending; new HEIC uploads mean a second run does more work.
					),
					'show_in_rest' => true, // Cap-guarded and non-destructive (originals are preserved).
				),
			)
		);

		wp_register_ability(
			'thisismyurl-heic-support/restore',
			array(
				'label'               => __( 'Restore original HEIC images from backups', 'thisismyurl-heic-support' ),
				'description'         => __( 'Restores every managed Media Library attachment that has a backup on disk, replacing the converted WebP file with the original HEIC/HEIF. Use this to roll back a conversion across the library.', 'thisismyurl-heic-support' ),
				'category'            => 'site',
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'restored', 'errors' ),
					'properties'           => array(
						'restored' => array(
							'type'        => 'integer',
							'description' => __( 'Number of attachments successfully restored from backup.', 'thisismyurl-heic-support' ),
						),
						'errors'   => array(
							'type'        => 'integer',
							'description' => __( 'Number of attachments that could not be restored (for example, no backup on disk).', 'thisismyurl-heic-support' ),
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => static function () {
					if ( ! class_exists( 'TIMU_HEIC_Support' ) ) {
						return new WP_Error( 'heic_unavailable', __( 'The HEIC Support conversion engine is not loaded.', 'thisismyurl-heic-support' ) );
					}

					$lists    = TIMU_HEIC_Support::get_media_lists();
					$restored = 0;
					$errors   = 0;

					foreach ( $lists['media'] as $post ) {
						$orig = get_post_meta( $post->ID, TIMU_HEIC_Support::BACKUP_META_KEY, true );
						if ( ! $orig ) {
							continue; // No restorable backup for this attachment.
						}

						if ( TIMU_HEIC_Support::restore_image( (int) $post->ID ) ) {
							++$restored;
						} else {
							++$errors;
						}
					}

					return array(
						'restored' => $restored,
						'errors'   => $errors,
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false, // Overwrites converted files with the originals and clears backup meta.
						'destructive' => true,  // Overwrites the current converted state of each attachment.
						'idempotent'  => true,  // After a restore the backup meta is cleared, so a second run finds nothing to restore: same end state.
					),
					'show_in_rest' => true, // Fully guarded by a logged-in manage_options check, so REST exposure is acceptable.
				),
			)
		);
	}
);
