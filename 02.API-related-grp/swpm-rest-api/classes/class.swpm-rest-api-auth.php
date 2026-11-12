<?php

/**
 * Handles authentication and scope checking for every swpm/v1 REST route.
 *
 * Two credential types are accepted:
 *
 * 1) An API key sent in the 'X-SWPM-API-Key' request header (an 'Authorization: Bearer <key>'
 *    header is accepted as well, since a lot of HTTP clients make that one easier to configure).
 * 2) A logged in WordPress user who holds the SWPM management permission. This covers WordPress
 *    Application Passwords and the cookie + nonce authentication used from the admin dashboard.
 *
 * The API key is deliberately NOT accepted as a query string parameter. Query strings end up in
 * server access logs, browser history and referrer headers, so a key sent that way should be
 * treated as disclosed.
 */
class SWPM_REST_API_Auth {

	const SCOPE_READ  = 'read';
	const SCOPE_WRITE = 'write';

	/**
	 * Identifies the client authenticated by this request, for the rate limiter.
	 *
	 * @var string
	 */
	protected static $client_id = '';

	/**
	 * Is the REST API turned on in the addon settings?
	 */
	public static function is_api_enabled() {
		$settings = SwpmSettings::get_instance();
		return ! empty( $settings->get_value( 'swpm-rest-api-enable' ) );
	}

	/**
	 * Are write (create/update/delete) operations allowed?
	 *
	 * This is a separate switch from 'swpm-rest-api-enable' and is off by default. Read access is
	 * harmless enough to hand to an external tool; write access can delete member accounts, so the
	 * admin has to opt into it explicitly.
	 */
	public static function is_write_enabled() {
		if ( ! self::is_api_enabled() ) {
			return false;
		}
		$settings = SwpmSettings::get_instance();
		return ! empty( $settings->get_value( 'swpm-rest-api-enable-write' ) );
	}

	/**
	 * Returns the configured API key (empty string if one has not been generated yet).
	 */
	public static function get_api_key() {
		$settings = SwpmSettings::get_instance();
		$api_key  = $settings->get_value( 'swpm-rest-api-key' );
		return empty( $api_key ) ? '' : $api_key;
	}

	/**
	 * Returns the identifier of the client authenticated by this request.
	 *
	 * @return string
	 */
	public static function get_client_id() {
		return self::$client_id;
	}

	/**
	 * The 'auth' middleware.
	 *
	 * @param WP_REST_Request $request
	 * @param string          $scope Either self::SCOPE_READ or self::SCOPE_WRITE.
	 * @return true|WP_Error
	 */
	public static function check_permission( $request, $scope ) {
		if ( ! self::is_api_enabled() ) {
			return new WP_Error(
				'swpm_rest_api_disabled',
				__( 'The Simple Membership REST API is disabled on this site.', 'swpm-rest-api' ),
				array( 'status' => 403 )
			);
		}

		$provided_key = self::get_key_from_request( $request );

		if ( ! empty( $provided_key ) ) {
			$failed_auth_check = SWPM_REST_API_Rate_Limiter::check_failed_auth_limit();
			if ( is_wp_error( $failed_auth_check ) ) {
				return $failed_auth_check;
			}
		}

		$authenticated_via = self::authenticate( $request );

		if ( empty( $authenticated_via ) ) {
			if ( ! empty( $provided_key ) ) {
				SWPM_REST_API_Rate_Limiter::record_failed_auth();
			}

			return new WP_Error(
				'swpm_rest_api_unauthorized',
				__( 'Authentication is required. Send your API key in the X-SWPM-API-Key header, or authenticate as a WordPress user with membership management permission.', 'swpm-rest-api' ),
				array( 'status' => 401 )
			);
		}

		self::$client_id = 'api_key' === $authenticated_via ? 'api_key' : 'user:' . get_current_user_id();

		//Anything other than an explicit read scope is treated as a write.
		if ( self::SCOPE_READ !== $scope && ! self::is_write_enabled() ) {
			return new WP_Error(
				'swpm_rest_api_write_disabled',
				__( 'Write access is disabled for the Simple Membership REST API. Enable it in the addon settings if you want external applications to be able to modify membership data.', 'swpm-rest-api' ),
				array( 'status' => 403 )
			);
		}

		self::log_request( $request, $authenticated_via, $scope );

		/**
		 * Lets other code veto an authenticated request (IP allowlists, per key scopes etc.).
		 * Return a WP_Error to reject it.
		 */
		return apply_filters( 'swpm_rest_api_check_permission', true, $request, $scope, $authenticated_via );
	}

	/**
	 * Works out how (and whether) the caller is authenticated.
	 *
	 * @param WP_REST_Request $request
	 * @return string Empty string when unauthenticated, otherwise 'api_key' or 'wp_user'.
	 */
	protected static function authenticate( $request ) {
		if ( self::has_valid_api_key( $request ) ) {
			return 'api_key';
		}

		//No valid API key. Fall back to the current WordPress user (application password or admin cookie).
		if ( is_user_logged_in() && SwpmUtils::has_admin_management_permission() ) {
			return 'wp_user';
		}

		return '';
	}

	/**
	 * Pulls the API key out of the request headers and compares it to the configured one.
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	protected static function has_valid_api_key( $request ) {
		$provided_key = self::get_key_from_request( $request );
		if ( empty( $provided_key ) ) {
			return false;
		}

		$configured_key = self::get_api_key();
		if ( empty( $configured_key ) ) {
			//No key configured yet, so no key can match it.
			return false;
		}

		//hash_equals() keeps the comparison time constant so the key cannot be guessed a character at a time.
		if ( ! hash_equals( $configured_key, $provided_key ) ) {
			SwpmLog::log_simple_debug( 'SWPM REST API: request rejected. An invalid API key was provided.', false );
			return false;
		}

		if ( ! is_ssl() ) {
			//Not fatal (it would break local development setups), but worth flagging in the debug log.
			SwpmLog::log_simple_debug( 'SWPM REST API: warning. An API key was accepted over a non-HTTPS connection. The key is exposed in transit.', false );
		}

		return true;
	}

	/**
	 * Reads the API key from the 'X-SWPM-API-Key' header, or from an 'Authorization: Bearer' header.
	 *
	 * @param WP_REST_Request $request
	 * @return string
	 */
	protected static function get_key_from_request( $request ) {
		$key = $request->get_header( 'x_swpm_api_key' );
		if ( ! empty( $key ) ) {
			return trim( $key );
		}

		$authorization = $request->get_header( 'authorization' );
		if ( ! empty( $authorization ) && stripos( $authorization, 'bearer ' ) === 0 ) {
			return trim( substr( $authorization, 7 ) );
		}

		return '';
	}

	/**
	 * Records the request in the plugin's debug log so there is an audit trail of what an
	 * external application (or AI agent) did on the site.
	 *
	 * @param WP_REST_Request $request
	 * @param string          $authenticated_via
	 * @param string          $scope
	 */
	protected static function log_request( $request, $authenticated_via, $scope ) {
		$message = sprintf(
			'SWPM REST API: %s %s (scope: %s, authenticated via: %s)',
			$request->get_method(),
			$request->get_route(),
			$scope,
			$authenticated_via
		);
		SwpmLog::log_simple_debug( $message, true );
	}
}
