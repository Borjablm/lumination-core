<?php
/**
 * Lumination Core Security Utilities
 *
 * Shared security functions for all Lumination extensions.
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
 * Shared security utilities for Lumination extensions.
 *
 * @since 1.0.0
 */
class Lumination_Core_Security {

	/**
	 * Validate an uploaded file.
	 *
	 * @since 1.0.0
	 *
	 * @param array $file $_FILES array element.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function validate_file_upload( $file ) {
		if ( empty( $file ) || ! isset( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No file uploaded', 'lumination-core' ) );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'upload_error', __( 'File upload error', 'lumination-core' ) );
		}

		$allowed_mimes = array( 'image/png', 'image/jpeg', 'application/pdf' );
		if ( ! in_array( $file['type'], $allowed_mimes, true ) ) {
			return new WP_Error(
				'invalid_type',
				__( 'Invalid file type. Allowed: PNG, JPEG, PDF', 'lumination-core' )
			);
		}

		$allowed_ext = array( 'png', 'jpg', 'jpeg', 'pdf' );
		$ext         = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed_ext, true ) ) {
			return new WP_Error( 'invalid_extension', __( 'Invalid file extension', 'lumination-core' ) );
		}

		if ( $file['size'] > 10 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', __( 'File too large (max 10MB)', 'lumination-core' ) );
		}

		return true;
	}

	/**
	 * Check per-user rate limit using transients.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action Action identifier.
	 * @param int    $limit  Maximum calls allowed.
	 * @param int    $period Time period in seconds.
	 * @return true|WP_Error True if within limit, WP_Error if exceeded.
	 */
	public static function check_rate_limit( $action, $limit = 10, $period = 60 ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return true; // Allow all guests (rate-limit only logged-in users).
		}

		$key   = 'lumination_rl_' . $action . '_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'rate_limit',
				sprintf(
					/* translators: %d: number of seconds */
					__( 'Rate limit exceeded. Please wait %d seconds before trying again.', 'lumination-core' ),
					$period
				)
			);
		}

		set_transient( $key, $count + 1, $period );
		return true;
	}

	/**
	 * Sanitize a base64-encoded string.
	 *
	 * Strips data-URI prefix (e.g. "data:image/png;base64,") and validates characters.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base64 Raw base64 input.
	 * @return string Sanitized base64 string, or empty on invalid input.
	 */
	public static function sanitize_base64( $base64 ) {
		$base64 = preg_replace( '/\s+/', '', $base64 );
		$base64 = preg_replace( '/^data:[^;]+;base64,/', '', $base64 );

		if ( ! preg_match( '/^[a-zA-Z0-9\/+]*={0,2}$/', $base64 ) ) {
			return '';
		}

		return $base64;
	}

	/**
	 * Check if the current user is allowed to submit to a given capability gate.
	 *
	 * Extensions pass their own capability string. Default allows everyone.
	 * Filter: lumination_core_can_submit
	 *
	 * @since 1.0.0
	 *
	 * @param string $capability Optional capability gate (e.g. 'homework', 'chat').
	 * @return bool True if allowed.
	 */
	public static function can_submit( $capability = '' ) {
		/**
		 * Filters whether the current user can submit to a Lumination extension.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $allowed    Whether submission is allowed. Default true.
		 * @param string $capability The capability gate passed by the extension.
		 * @param int    $user_id    Current user ID (0 if not logged in).
		 */
		return apply_filters( 'lumination_core_can_submit', true, $capability, get_current_user_id() );
	}

	/**
	 * Log a security event to error_log (WP_DEBUG only).
	 *
	 * @since 1.0.0
	 *
	 * @param string $event   Event description.
	 * @param array  $context Additional context.
	 */
	public static function log_event( $event, $context = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$message = sprintf(
			'Lumination Security: %s | User: %d | IP: %s',
			$event,
			get_current_user_id(),
			$ip
		);

		if ( ! empty( $context ) ) {
			$message .= ' | Context: ' . wp_json_encode( $context );
		}

		error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
