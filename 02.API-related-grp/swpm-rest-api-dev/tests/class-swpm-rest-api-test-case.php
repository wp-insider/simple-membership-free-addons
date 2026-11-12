<?php
/**
 * Shared setup and helpers for the REST API add-on tests.
 *
 * Requests are dispatched in-process through rest_get_server(), so no web server is involved.
 * WordPress rolls the database back after every test.
 */
abstract class SWPM_REST_API_Test_Case extends WP_UnitTestCase {

	const API_KEY = 'test-api-key-0123456789abcdef';

	/**
	 * Gives every test the same known settings. SwpmSettings is a singleton that outlives a single
	 * test, so anything a test changes is put back here.
	 */
	public function set_up() {
		parent::set_up();

		$this->configure(
			array(
				'swpm-rest-api-enable'                      => '1',
				'swpm-rest-api-enable-write'                => '1',
				'swpm-rest-api-key'                         => self::API_KEY,
				'swpm-rest-api-rate-limit-read'             => 0,
				'swpm-rest-api-rate-limit-write'            => 0,
				'swpm-rest-api-rate-limit-failed-auth'      => 0,
				'force-strong-passwords'                    => '',
				'default-account-status'                    => 'active',
				'enable-notification-after-manual-user-add' => '',
				'enable-admin-notification-after-reg'       => '',
			)
		);

		wp_set_current_user( 0 );
		reset_phpmailer_instance();
	}

	/**
	 * Sets plugin settings in memory for the current test.
	 *
	 * @param array $values
	 */
	protected function configure( $values ) {
		$settings = SwpmSettings::get_instance();
		foreach ( $values as $key => $value ) {
			$settings->set_value( $key, $value );
		}
	}

	/**
	 * Dispatches a request to the API.
	 *
	 * @param string     $method
	 * @param string     $path    Path after the namespace, e.g. '/members'.
	 * @param array|null $body    Sent as a JSON body.
	 * @param array      $query   Query string parameters.
	 * @param array|null $headers Defaults to a valid API key header. Pass array() for none.
	 * @return WP_REST_Response
	 */
	protected function request( $method, $path, $body = null, $query = array(), $headers = null ) {
		$request = new WP_REST_Request( $method, '/swpm/v1' . $path );

		if ( null === $headers ) {
			$headers = array( 'X-SWPM-API-Key' => self::API_KEY );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		if ( $query ) {
			$request->set_query_params( $query );
		}

		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Asserts the response is an error with the given status and code.
	 */
	protected function assert_error( $response, $status, $code ) {
		$data = $response->get_data();
		$this->assertSame( $status, $response->get_status(), 'Response: ' . wp_json_encode( $data ) );
		$this->assertSame( $code, isset( $data['code'] ) ? $data['code'] : null, 'Response: ' . wp_json_encode( $data ) );
	}

	/**
	 * Asserts the response succeeded with the given status, and returns its 'data' envelope part.
	 */
	protected function assert_success( $response, $status = 200 ) {
		$body = $response->get_data();
		$this->assertSame( $status, $response->get_status(), 'Response: ' . wp_json_encode( $body ) );
		$this->assertArrayHasKey( 'data', $body );

		return $body['data'];
	}

	/**
	 * Inserts a membership level row directly and returns its id.
	 */
	protected function create_level( $overrides = array() ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'swpm_membership_tbl',
			array_merge(
				array(
					'alias'                      => 'Test Level ' . wp_generate_password( 6, false ),
					'role'                       => 'subscriber',
					'subscription_period'        => '',
					'subscription_duration_type' => 0,
				),
				$overrides
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Inserts a member row directly, bypassing the API, and returns its id.
	 */
	protected function insert_member_row( $overrides = array() ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'swpm_members_tbl',
			array_merge(
				array(
					'user_name'             => 'testrow' . wp_generate_password( 8, false ),
					'email'                 => 'testrow' . wp_generate_password( 8, false ) . '@example.com',
					'password'              => wp_hash_password( 'unused' ),
					'membership_level'      => $this->create_level(),
					'account_state'         => 'active',
					'member_since'          => '2026-01-01',
					'subscription_starts'   => '2026-01-01',
					'last_accessed_from_ip' => '',
				),
				$overrides
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * A valid member create body with a unique username and email.
	 */
	protected function member_payload( $level_id, $overrides = array() ) {
		$unique = strtolower( wp_generate_password( 10, false ) );

		return array_merge(
			array(
				'user_name'         => 'apitest' . $unique,
				'email'             => 'apitest' . $unique . '@example.com',
				'password'          => 'TestPass123',
				'membership_level'  => $level_id,
				'first_name'        => 'Api',
				'last_name'         => 'Tester',
				'send_notification' => false,
			),
			$overrides
		);
	}

	/**
	 * Creates a member through the API and returns the member item.
	 */
	protected function create_member( $level_id, $overrides = array() ) {
		return $this->assert_success( $this->request( 'POST', '/members', $this->member_payload( $level_id, $overrides ) ), 201 );
	}

	protected function count_members() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}swpm_members_tbl" );
	}

	protected function count_meta_rows( $level_id, $meta_key ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}swpm_membership_meta_tbl WHERE level_id = %d AND meta_key = %s",
				$level_id,
				$meta_key
			)
		);
	}

	/**
	 * Removes the rate limit counters so a test starts with a fresh minute.
	 */
	protected function clear_rate_limit_counters() {
		$clients = array(
			'read|api_key',
			'write|api_key',
			'failed-auth|' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ),
		);

		foreach ( $clients as $client ) {
			delete_transient( 'swpm_rest_rl_' . md5( $client ) );
		}
	}
}
