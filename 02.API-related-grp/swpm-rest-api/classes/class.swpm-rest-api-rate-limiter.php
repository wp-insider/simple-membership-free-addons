<?php

/**
 * Per-minute request limits for the API.
 *
 * Reads and writes are counted per authenticated client (API key or WordPress user), because an IP
 * address is unreliable behind proxies and shared hosting. Failed API key attempts are the
 * exception: the caller is unknown at that point, so those are counted per IP address.
 *
 * The counters are transients. Without a persistent object cache those are not atomic, so limits
 * are approximate when requests arrive at the same moment.
 */
class SWPM_REST_API_Rate_Limiter {

	const WINDOW_SECONDS = 60;

	const BUCKET_READ        = 'read';
	const BUCKET_WRITE       = 'write';
	const BUCKET_FAILED_AUTH = 'failed-auth';

	const DEFAULT_LIMITS = array(
		self::BUCKET_READ        => 120,
		self::BUCKET_WRITE       => 30,
		self::BUCKET_FAILED_AUTH => 20,
	);

	/**
	 * Headers to add to the response of the current request.
	 *
	 * @var array
	 */
	protected static $headers = array();

	/**
	 * The requests allowed per minute for a bucket. Zero means no limit.
	 *
	 * @param string $bucket
	 * @return int
	 */
	public static function get_limit( $bucket ) {
		$limit = SwpmSettings::get_instance()->get_value( 'swpm-rest-api-rate-limit-' . $bucket, '' );

		if ( '' === $limit ) {
			$limit = self::DEFAULT_LIMITS[ $bucket ];
		}

		/**
		 * Filters the requests allowed per minute. Return 0 for no limit.
		 */
		return absint( apply_filters( 'swpm_rest_api_rate_limit', absint( $limit ), $bucket ) );
	}

	/**
	 * The 'throttle' middleware. Runs after authentication, so the client is known.
	 *
	 * @param WP_REST_Request $request
	 * @param string          $bucket 'read' or 'write'.
	 * @return true|WP_Error
	 */
	public static function handle( $request, $bucket ) {
		$limit = self::get_limit( $bucket );

		if ( 0 === $limit ) {
			return true;
		}

		$client = SWPM_REST_API_Auth::get_client_id();
		$window = self::get_window( $bucket, $client );

		if ( $window['count'] >= $limit ) {
			return self::limit_exceeded_error( $limit, $window );
		}

		$window['count']++;
		self::save_window( $bucket, $client, $window );

		self::$headers = array(
			'X-RateLimit-Limit'     => $limit,
			'X-RateLimit-Remaining' => max( 0, $limit - $window['count'] ),
			'X-RateLimit-Reset'     => $window['reset'],
		);

		return true;
	}

	/**
	 * Checks whether this IP address has used up its failed API key attempts.
	 *
	 * @return true|WP_Error True when the request may continue.
	 */
	public static function check_failed_auth_limit() {
		$limit = self::get_limit( self::BUCKET_FAILED_AUTH );

		if ( 0 === $limit ) {
			return true;
		}

		$window = self::get_window( self::BUCKET_FAILED_AUTH, self::get_client_ip() );

		if ( $window['count'] >= $limit ) {
			return self::limit_exceeded_error( $limit, $window );
		}

		return true;
	}

	/**
	 * Counts a failed API key attempt against this IP address.
	 */
	public static function record_failed_auth() {
		if ( 0 === self::get_limit( self::BUCKET_FAILED_AUTH ) ) {
			return;
		}

		$client = self::get_client_ip();
		$window = self::get_window( self::BUCKET_FAILED_AUTH, $client );

		$window['count']++;
		self::save_window( self::BUCKET_FAILED_AUTH, $client, $window );
	}

	/**
	 * Adds the rate limit headers to the outgoing response.
	 *
	 * A permission_callback has no access to the response, so this runs on 'rest_post_dispatch'.
	 *
	 * @param WP_HTTP_Response $response
	 * @return WP_HTTP_Response
	 */
	public static function add_response_headers( $response ) {
		foreach ( self::$headers as $name => $value ) {
			$response->header( $name, $value );
		}

		self::$headers = array();

		return $response;
	}

	/**
	 * The IP address of the connection. Forwarding headers are ignored because a caller can set
	 * them to anything; a site behind a trusted proxy can correct this with the filter.
	 *
	 * @return string
	 */
	protected static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return (string) apply_filters( 'swpm_rest_api_client_ip', $ip );
	}

	/**
	 * Returns the current counting window, starting a new one if the last has ended.
	 *
	 * @param string $bucket
	 * @param string $client
	 * @return array With 'count' and 'reset' (Unix timestamp) keys.
	 */
	protected static function get_window( $bucket, $client ) {
		$window = get_transient( self::get_transient_name( $bucket, $client ) );

		if ( ! is_array( $window ) || $window['reset'] <= time() ) {
			return array(
				'count' => 0,
				'reset' => time() + self::WINDOW_SECONDS,
			);
		}

		return $window;
	}

	protected static function save_window( $bucket, $client, $window ) {
		set_transient( self::get_transient_name( $bucket, $client ), $window, max( 1, $window['reset'] - time() ) );
	}

	protected static function get_transient_name( $bucket, $client ) {
		return 'swpm_rest_rl_' . md5( $bucket . '|' . $client );
	}

	/**
	 * @param int   $limit
	 * @param array $window
	 * @return WP_Error
	 */
	protected static function limit_exceeded_error( $limit, $window ) {
		$retry_after = max( 1, $window['reset'] - time() );

		self::$headers = array(
			'X-RateLimit-Limit'     => $limit,
			'X-RateLimit-Remaining' => 0,
			'X-RateLimit-Reset'     => $window['reset'],
			'Retry-After'           => $retry_after,
		);

		return new WP_Error(
			'swpm_rest_api_rate_limited',
			__( 'Too many requests. Wait for the time given in Retry-After and try again.', 'swpm-rest-api' ),
			array(
				'status'      => 429,
				'retry_after' => $retry_after,
			)
		);
	}
}
