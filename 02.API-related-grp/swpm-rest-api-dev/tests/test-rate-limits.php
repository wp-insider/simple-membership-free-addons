<?php
/**
 * Per-minute rate limits.
 */
class Test_SWPM_REST_API_Rate_Limits extends SWPM_REST_API_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->clear_rate_limit_counters();
	}

	public function tear_down() {
		$this->clear_rate_limit_counters();
		parent::tear_down();
	}

	public function test_read_limit() {
		$this->configure( array( 'swpm-rest-api-rate-limit-read' => 3 ) );

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assert_success( $this->request( 'GET', '/levels' ) );
		}

		$response = $this->request( 'GET', '/levels' );
		$this->assert_error( $response, 429, 'swpm_rest_api_rate_limited' );

		$retry_after = $response->get_data()['data']['retry_after'];
		$this->assertGreaterThan( 0, $retry_after );
		$this->assertLessThanOrEqual( 60, $retry_after );
	}

	public function test_writes_are_counted_separately() {
		$this->configure(
			array(
				'swpm-rest-api-rate-limit-read'  => 1,
				'swpm-rest-api-rate-limit-write' => 5,
			)
		);
		$this->request( 'GET', '/levels' );
		$this->assert_error( $this->request( 'GET', '/levels' ), 429, 'swpm_rest_api_rate_limited' );

		$this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Still allowed' ) ), 201 );
	}

	public function test_zero_means_no_limit() {
		for ( $i = 0; $i < 150; $i++ ) {
			$response = $this->request( 'GET', '/levels' );
		}

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_defaults() {
		$this->configure(
			array(
				'swpm-rest-api-rate-limit-read'        => '',
				'swpm-rest-api-rate-limit-write'       => '',
				'swpm-rest-api-rate-limit-failed-auth' => '',
			)
		);

		$this->assertSame( 120, SWPM_REST_API_Rate_Limiter::get_limit( 'read' ) );
		$this->assertSame( 30, SWPM_REST_API_Rate_Limiter::get_limit( 'write' ) );
		$this->assertSame( 20, SWPM_REST_API_Rate_Limiter::get_limit( 'failed-auth' ) );
	}

	public function test_headers_are_added_to_the_response() {
		$this->configure( array( 'swpm-rest-api-rate-limit-read' => 10 ) );

		$request  = new WP_REST_Request( 'GET', '/swpm/v1/levels' );
		$request->set_header( 'X-SWPM-API-Key', self::API_KEY );
		$response = rest_get_server()->dispatch( $request );
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );
		$headers  = $response->get_headers();

		$this->assertSame( 10, $headers['X-RateLimit-Limit'] );
		$this->assertSame( 9, $headers['X-RateLimit-Remaining'] );
		$this->assertGreaterThan( time(), $headers['X-RateLimit-Reset'] );
	}

	public function test_wrong_keys_lock_out_the_ip_address() {
		$this->configure( array( 'swpm-rest-api-rate-limit-failed-auth' => 2 ) );
		$wrong = array( 'X-SWPM-API-Key' => 'wrong' );

		$this->assert_error( $this->request( 'GET', '/levels', null, array(), $wrong ), 401, 'swpm_rest_api_unauthorized' );
		$this->assert_error( $this->request( 'GET', '/levels', null, array(), $wrong ), 401, 'swpm_rest_api_unauthorized' );
		$this->assert_error( $this->request( 'GET', '/levels', null, array(), $wrong ), 429, 'swpm_rest_api_rate_limited' );

		//Deliberate: until the minute is up, the correct key from that address is refused too.
		$this->assert_error( $this->request( 'GET', '/levels' ), 429, 'swpm_rest_api_rate_limited' );

		//A request with no key at all is not guessing a key, so it is not caught by this limit.
		$this->assert_error( $this->request( 'GET', '/levels', null, array(), array() ), 401, 'swpm_rest_api_unauthorized' );
	}
}
