<?php
/**
 * Lumination Core API Client
 *
 * Single gateway for all Lumination API requests. Extensions must use this
 * class — they must never read credentials or build API URLs themselves.
 *
 * @package    LuminationCore
 * @since      1.0.0
 * @license    GPL-3.0-or-later
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public API gateway for Lumination extensions.
 *
 * @since 1.0.0
 */
class Lumination_Core_API {

	/**
	 * Make a request to the Lumination API.
	 *
	 * Extensions call this method. Core reads credentials from its own options
	 * so extensions never need to know the API key or base URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint   API path, e.g. '/lumination-ai/api/v1/agent/chat'.
	 * @param array  $body       Request body as associative array (will be JSON-encoded).
	 * @param string $request_id Optional correlation ID prefix (default: 'lumination').
	 * @return array|WP_Error    Decoded JSON response array on success, WP_Error on failure.
	 */
	public static function request( $endpoint, array $body, $request_id = 'lumination' ) {
		$api_key  = get_option( 'lumination_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Lumination API key not configured.', 'lumination-core' ) );
		}

		$url  = LUMINATION_API_BASE_URL . $endpoint;
		$json = wp_json_encode( $body );

		/**
		 * Filter the request body before sending.
		 *
		 * @since 1.0.0
		 * @param string $json     JSON-encoded body.
		 * @param string $endpoint API endpoint.
		 */
		$json = apply_filters( 'lumination_core_api_request_body', $json, $endpoint );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'X-API-KEY'      => $api_key,
					'X-REQUEST-ID'   => $request_id . '-' . time(),
					'Content-Type'   => 'application/json',
				),
				'body'    => $json,
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Lumination Core API Error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error( 'api_request_failed', __( 'Failed to connect to Lumination API.', 'lumination-core' ) );
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$body_content = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					'Lumination Core API Error - URL: %s, Code: %d, Response: %s',
					$url,
					$code,
					substr( $body_content, 0, 500 )
				) );
			}

			if ( 401 === $code ) {
				return new WP_Error( 'invalid_api_key', __( 'Invalid API key.', 'lumination-core' ) );
			}
			if ( 429 === $code ) {
				return new WP_Error( 'rate_limit', __( 'API rate limit exceeded.', 'lumination-core' ) );
			}
			if ( 404 === $code ) {
				return new WP_Error(
					'endpoint_not_found',
					sprintf(
						/* translators: %s: API endpoint URL */
						__( 'API endpoint not found: %s', 'lumination-core' ),
						$url
					)
				);
			}
			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'API returned error code: %d', 'lumination-core' ),
					$code
				)
			);
		}

		$data = json_decode( $body_content, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Lumination Core API JSON Error: ' . json_last_error_msg() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error( 'invalid_json', __( 'Invalid JSON response from API.', 'lumination-core' ) );
		}

		/**
		 * Filter the decoded API response.
		 *
		 * @since 1.0.0
		 * @param array  $data     Decoded response.
		 * @param string $endpoint API endpoint.
		 */
		return apply_filters( 'lumination_core_api_response', $data, $endpoint );
	}

	/**
	 * Check if Core has both API key and base URL configured.
	 *
	 * @since 1.0.0
	 * @return bool True if both options are set.
	 */
	public static function is_configured() {
		return ! empty( get_option( 'lumination_api_key', '' ) );
	}

	/**
	 * Test the API connection by sending a trivial request.
	 *
	 * @since 1.0.0
	 * @return true|WP_Error True on success.
	 */
	public static function test_connection() {
		$result = self::request(
			'/lumination-ai/api/v1/agent/chat',
			array(
				'persist'  => false,
				'stream'   => false,
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => '2 + 2',
					),
				),
			),
			'lumination-test'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
