<?php
/**
 * Shared Vortops cloud image-processing client.
 *
 * Included by any TIMU plugin that wants cloud-conversion fallback.
 * The class_exists guard means only the first load wins when multiple TIMU
 * plugins are active — whichever loads first wins, and they all share the
 * same timu_vortops_api_key option.
 *
 * @package TIMU_Vortops
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TIMU_Vortops_Client' ) ) {

	class TIMU_Vortops_Client {

		const OPTION_KEY = 'timu_vortops_api_key';
		const API_BASE   = 'https://api.vortops.com/v1';

		// -------------------------------------------------------------------------
		// Key management
		// -------------------------------------------------------------------------

		/**
		 * @return string The stored API key, or empty string if none.
		 */
		public static function get_api_key() {
			return (string) get_option( self::OPTION_KEY, '' );
		}

		/**
		 * @return bool True if an API key is stored.
		 */
		public static function is_connected() {
			return '' !== self::get_api_key();
		}

		// -------------------------------------------------------------------------
		// API calls
		// -------------------------------------------------------------------------

		/**
		 * Ping the Vortops API with the stored key.
		 *
		 * @return true|WP_Error True on success.
		 */
		public static function ping() {
			return self::ping_with_key( self::get_api_key() );
		}

		/**
		 * Ping the Vortops API with a specific key (for pre-save connection tests).
		 *
		 * @param string $api_key The API key to test.
		 * @return true|WP_Error True on success.
		 */
		public static function ping_with_key( $api_key ) {
			$api_key = (string) $api_key;

			if ( '' === $api_key ) {
				return new WP_Error( 'no_key', __( 'No Vortops API key provided.', 'timu-vortops' ) );
			}

			$response = wp_remote_get(
				self::API_BASE . '/ping',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Accept'        => 'application/json',
					),
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$body   = wp_remote_retrieve_body( $response );
				$parsed = json_decode( $body, true );
				return new WP_Error(
					'api_error',
					isset( $parsed['error'] ) ? $parsed['error'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vortops API returned status %d.', 'timu-vortops' ),
						$code
					)
				);
			}

			return true;
		}

		/**
		 * Get usage for the current billing period.
		 *
		 * @return array|WP_Error Usage data array on success.
		 */
		public static function get_usage() {
			$key = self::get_api_key();
			if ( '' === $key ) {
				return new WP_Error( 'no_key', __( 'No Vortops API key configured.', 'timu-vortops' ) );
			}

			$response = wp_remote_get(
				self::API_BASE . '/usage',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Accept'        => 'application/json',
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code   = (int) wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );
			$parsed = json_decode( $body, true );

			if ( 200 !== $code ) {
				return new WP_Error(
					'api_error',
					isset( $parsed['error'] ) ? $parsed['error'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vortops API returned status %d.', 'timu-vortops' ),
						$code
					)
				);
			}

			return is_array( $parsed ) ? $parsed : array();
		}

		/**
		 * Convert a file to WebP via Vortops cloud.
		 *
		 * Sends the file as multipart/form-data. Returns the raw WebP binary blob
		 * on success — write it to disk with file_put_contents().
		 *
		 * @param string $file_path   Absolute path to the source image.
		 * @param string $source_mime MIME type of the source (e.g. 'image/heic').
		 * @return string|WP_Error Raw WebP blob on success.
		 */
		public static function convert( $file_path, $source_mime = '' ) {
			$key = self::get_api_key();
			if ( '' === $key ) {
				return new WP_Error( 'no_key', __( 'No Vortops API key configured.', 'timu-vortops' ) );
			}

			if ( ! file_exists( $file_path ) ) {
				return new WP_Error( 'missing_file', __( 'Source file does not exist.', 'timu-vortops' ) );
			}

			$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $file_content ) {
				return new WP_Error( 'read_error', __( 'Could not read source file.', 'timu-vortops' ) );
			}

			$boundary = wp_generate_password( 24, false );
			$filename = basename( $file_path );
			$ct       = '' !== $source_mime ? $source_mime : 'application/octet-stream';

			$body  = '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
			$body .= 'Content-Type: ' . $ct . "\r\n\r\n";
			$body .= $file_content . "\r\n";
			$body .= '--' . $boundary . '--' . "\r\n";

			$response = wp_remote_post(
				self::API_BASE . '/convert',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					),
					'body'    => $body,
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
				return new WP_Error(
					'convert_error',
					isset( $parsed['error'] ) ? $parsed['error'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vortops conversion failed (HTTP %d).', 'timu-vortops' ),
						$code
					)
				);
			}

			return wp_remote_retrieve_body( $response );
		}

		/**
		 * Sanitize an SVG via Vortops cloud.
		 *
		 * @param string $file_path Absolute path to the SVG file.
		 * @return string|WP_Error Sanitized SVG string on success.
		 */
		public static function sanitize_svg( $file_path ) {
			$key = self::get_api_key();
			if ( '' === $key ) {
				return new WP_Error( 'no_key', __( 'No Vortops API key configured.', 'timu-vortops' ) );
			}

			if ( ! file_exists( $file_path ) ) {
				return new WP_Error( 'missing_file', __( 'Source file does not exist.', 'timu-vortops' ) );
			}

			$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $file_content ) {
				return new WP_Error( 'read_error', __( 'Could not read SVG file.', 'timu-vortops' ) );
			}

			$boundary = wp_generate_password( 24, false );

			$body  = '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="file"; filename="' . basename( $file_path ) . '"' . "\r\n";
			$body .= 'Content-Type: image/svg+xml' . "\r\n\r\n";
			$body .= $file_content . "\r\n";
			$body .= '--' . $boundary . '--' . "\r\n";

			$response = wp_remote_post(
				self::API_BASE . '/svg/sanitize',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					),
					'body'    => $body,
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
				return new WP_Error(
					'sanitize_error',
					isset( $parsed['error'] ) ? $parsed['error'] : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Vortops SVG sanitization failed (HTTP %d).', 'timu-vortops' ),
						$code
					)
				);
			}

			return wp_remote_retrieve_body( $response );
		}
	}
}
