<?php
/**
 * Lumination Core API Client
 *
 * Single gateway for all Lumination (AI Tutor) API requests. Extensions must
 * use this class — they must never read credentials or build API URLs themselves.
 *
 * The AI Tutor API is asynchronous: a POST to a tool endpoint (e.g. '/tutor',
 * '/summarize', '/quiz') returns a `request_id`; the finished result is fetched
 * by polling GET '/requests/{request_id}' until its `status` is `completed`
 * or `failed`.
 *
 * Two usage styles are provided:
 *
 *  - request()  — submit + block until the job finishes, then return the
 *                 completed job envelope. Best for fast tools (chat, homework)
 *                 that finish in a few seconds.
 *  - submit() + poll() — submit once, then poll from the browser via an AJAX
 *                 status action. Best for slow tools (summaries, quizzes) that
 *                 can take 30–60s and would otherwise block a PHP request.
 *
 * A completed job envelope looks like:
 *   array(
 *     'request_id'      => '…',
 *     'tool'            => 'tutor',
 *     'status'          => 'completed',
 *     'result'          => array( … ),   // tool-specific output
 *     'credits_charged' => 0.0056,
 *     'input_tokens'    => 4199,
 *     'output_tokens'   => 16,
 *     'conversation_id' => '…' | null,
 *     'error'           => null,
 *   )
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
	 * Seconds to wait between polls in the blocking request() helper.
	 *
	 * @since 1.2.0
	 */
	const POLL_INTERVAL = 2;

	/**
	 * Maximum seconds request() will block waiting for a job to finish.
	 *
	 * Fast tools (chat, homework) finish well within this window. Slow tools
	 * should use submit() + poll() with browser-side polling instead.
	 *
	 * @since 1.2.0
	 */
	const POLL_MAX_WAIT = 60;

	/**
	 * Resolve the API base URL (includes the /api/v1 prefix, no trailing slash).
	 *
	 * @since 1.2.0
	 * @return string
	 */
	private static function base_url() {
		/**
		 * Filter the AI Tutor API base URL.
		 *
		 * @since 1.2.0
		 * @param string $base_url Base URL including the /api/v1 prefix.
		 */
		return untrailingslashit( apply_filters( 'lumination_core_api_base_url', LUMINATION_API_BASE_URL ) );
	}

	/**
	 * Perform a single HTTP request against the API and decode the JSON body.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $method     'POST' or 'GET'.
	 * @param string     $endpoint   API path starting with '/', e.g. '/tutor'.
	 * @param array|null $body       Request body (POST only); JSON-encoded.
	 * @param string     $request_id Correlation-ID prefix for the X-REQUEST-ID header.
	 * @return array|WP_Error        Decoded JSON on success, WP_Error on failure.
	 */
	private static function http( $method, $endpoint, $body, $request_id ) {
		$api_key = get_option( 'lumination_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Lumination API key not configured.', 'lumination-core' ) );
		}

		$url = self::base_url() . '/' . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => $method,
			'headers' => array(
				'x-api-key'    => $api_key,
				'X-REQUEST-ID' => $request_id . '-' . time(),
				'Accept'       => 'application/json',
			),
			'timeout' => 30,
		);

		if ( 'POST' === $method && null !== $body ) {
			$json = wp_json_encode( $body );

			/**
			 * Filter the request body before sending.
			 *
			 * @since 1.0.0
			 * @param string $json     JSON-encoded body.
			 * @param string $endpoint API endpoint.
			 */
			$json = apply_filters( 'lumination_core_api_request_body', $json, $endpoint );

			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $json;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Lumination Core API Error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error( 'api_request_failed', __( 'Failed to connect to Lumination API.', 'lumination-core' ) );
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$body_content = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					'Lumination Core API Error - %s %s, Code: %d, Response: %s',
					$method,
					$url,
					$code,
					substr( (string) $body_content, 0, 500 )
				) );
			}
			return self::http_error( $code, $body_content, $url );
		}

		$data = json_decode( $body_content, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Lumination Core API JSON Error: ' . json_last_error_msg() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error( 'invalid_json', __( 'Invalid JSON response from API.', 'lumination-core' ) );
		}

		return $data;
	}

	/**
	 * Map a non-2xx HTTP status to a descriptive WP_Error.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $code Response status code.
	 * @param string $body Raw response body.
	 * @param string $url  Requested URL (for 404 messages).
	 * @return WP_Error
	 */
	private static function http_error( $code, $body, $url ) {
		switch ( $code ) {
			case 401:
				return new WP_Error( 'invalid_api_key', __( 'Invalid API key.', 'lumination-core' ) );
			case 403:
				return new WP_Error( 'forbidden', __( 'Access denied by the API. Check the API key and its permissions.', 'lumination-core' ) );
			case 429:
				return new WP_Error( 'rate_limit', __( 'API rate limit exceeded.', 'lumination-core' ) );
			case 402:
				return new WP_Error( 'insufficient_credits', __( 'The API key has insufficient credits.', 'lumination-core' ) );
			case 404:
				return new WP_Error(
					'endpoint_not_found',
					sprintf(
						/* translators: %s: API endpoint URL */
						__( 'API endpoint not found: %s', 'lumination-core' ),
						$url
					)
				);
			case 400:
			case 422:
				$detail = self::error_detail( $body );
				return new WP_Error(
					'bad_request',
					$detail
						? sprintf( /* translators: %s: API error detail */ __( 'The API rejected the request: %s', 'lumination-core' ), $detail )
						: __( 'The API rejected the request.', 'lumination-core' )
				);
			default:
				return new WP_Error(
					'api_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'API returned error code: %d', 'lumination-core' ),
						$code
					)
				);
		}
	}

	/**
	 * Pull a human-readable message out of an error response body.
	 *
	 * @since 1.2.0
	 * @param string $body Raw response body.
	 * @return string Detail string, or '' if none found.
	 */
	private static function error_detail( $body ) {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			foreach ( array( 'error', 'message', 'detail' ) as $key ) {
				if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
					return $decoded[ $key ];
				}
			}
		}
		return '';
	}

	/**
	 * Submit a job to a tool endpoint (POST) without waiting for the result.
	 *
	 * Returns the raw submit response, typically:
	 *   array( 'request_id' => '…', 'conversation_id' => '…' )
	 *
	 * Use this together with poll() when the browser will poll for completion.
	 *
	 * @since 1.2.0
	 *
	 * @param string $endpoint   Tool path, e.g. '/tutor', '/summarize', '/quiz'.
	 * @param array  $body       Request body (JSON-encoded).
	 * @param string $request_id Correlation-ID prefix.
	 * @return array|WP_Error
	 */
	public static function submit( $endpoint, array $body, $request_id = 'lumination' ) {
		return self::http( 'POST', $endpoint, $body, $request_id );
	}

	/**
	 * Fetch the current state of a submitted job (GET /requests/{id}).
	 *
	 * @since 1.2.0
	 *
	 * @param string $request_id The request_id returned by submit().
	 * @return array|WP_Error Job envelope (with 'status') or WP_Error.
	 */
	public static function poll( $request_id ) {
		if ( empty( $request_id ) ) {
			return new WP_Error( 'no_request_id', __( 'Missing request identifier.', 'lumination-core' ) );
		}
		return self::http( 'GET', '/requests/' . rawurlencode( $request_id ), null, 'lumination-poll' );
	}

	/**
	 * Submit a job and block until it finishes, returning the completed envelope.
	 *
	 * Suitable for fast tools (chat, homework). Slow tools should use
	 * submit() + browser-side poll() to avoid long-held PHP requests.
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint   Tool path, e.g. '/tutor'.
	 * @param array  $body       Request body (JSON-encoded).
	 * @param string $request_id Correlation-ID prefix.
	 * @return array|WP_Error    Completed job envelope, or WP_Error.
	 */
	public static function request( $endpoint, array $body, $request_id = 'lumination' ) {
		$submit = self::submit( $endpoint, $body, $request_id );
		if ( is_wp_error( $submit ) ) {
			return $submit;
		}

		// Some responses may already be terminal (future-proofing).
		if ( isset( $submit['status'] ) && in_array( $submit['status'], array( 'completed', 'failed' ), true ) ) {
			return self::finish( $submit, $endpoint );
		}

		$rid = isset( $submit['request_id'] ) ? $submit['request_id'] : '';
		if ( empty( $rid ) ) {
			// No async id and no terminal status: return the raw response as-is.
			return $submit;
		}

		// Give PHP room to complete the poll loop without hitting max_execution_time.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::POLL_MAX_WAIT + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$deadline = time() + self::POLL_MAX_WAIT;
		while ( time() < $deadline ) {
			sleep( self::POLL_INTERVAL );

			$job = self::poll( $rid );
			if ( is_wp_error( $job ) ) {
				return $job;
			}

			$status = isset( $job['status'] ) ? $job['status'] : '';
			if ( 'completed' === $status ) {
				return self::finish( $job, $endpoint );
			}
			if ( 'failed' === $status ) {
				$msg = ( ! empty( $job['error'] ) && is_string( $job['error'] ) )
					? $job['error']
					: __( 'The request could not be completed.', 'lumination-core' );
				return new WP_Error( 'job_failed', $msg );
			}
		}

		return new WP_Error( 'job_timeout', __( 'The request timed out. Please try again.', 'lumination-core' ) );
	}

	/**
	 * Apply the response filter to a finished job envelope.
	 *
	 * @since 1.2.0
	 * @param array  $job      Completed job envelope.
	 * @param string $endpoint Endpoint the job came from.
	 * @return array
	 */
	private static function finish( $job, $endpoint ) {
		/**
		 * Filter the decoded API response (completed job envelope).
		 *
		 * @since 1.0.0
		 * @param array  $job      Completed job envelope.
		 * @param string $endpoint API endpoint.
		 */
		return apply_filters( 'lumination_core_api_response', $job, $endpoint );
	}

	/**
	 * Check if Core has an API key configured.
	 *
	 * @since 1.0.0
	 * @return bool True if the API key option is set.
	 */
	public static function is_configured() {
		return ! empty( get_option( 'lumination_api_key', '' ) );
	}

	/**
	 * Test the API connection by sending a trivial chat request.
	 *
	 * @since 1.0.0
	 * @return true|WP_Error True on success.
	 */
	public static function test_connection() {
		$result = self::request(
			'/tutor',
			array( 'message' => '2 + 2' ),
			'lumination-test'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
