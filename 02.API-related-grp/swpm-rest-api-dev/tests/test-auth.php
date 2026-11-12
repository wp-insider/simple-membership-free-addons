<?php
/**
 * Authentication, the write gate and the router's access rules.
 */
class Test_SWPM_REST_API_Auth extends SWPM_REST_API_Test_Case {

	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();

		foreach ( array( '/swpm/v1/members', '/swpm/v1/members/(?P<id>\d+)', '/swpm/v1/levels', '/swpm/v1/levels/(?P<id>\d+)', '/swpm/v1/transactions', '/swpm/v1/transactions/(?P<id>\d+)' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
		}
	}

	public function test_disabled_api_refuses_a_valid_key() {
		$this->configure( array( 'swpm-rest-api-enable' => '' ) );

		$this->assert_error( $this->request( 'GET', '/members' ), 403, 'swpm_rest_api_disabled' );
	}

	public function test_no_credentials() {
		$this->assert_error( $this->request( 'GET', '/members', null, array(), array() ), 401, 'swpm_rest_api_unauthorized' );
	}

	public function test_wrong_key() {
		$this->assert_error( $this->request( 'GET', '/members', null, array(), array( 'X-SWPM-API-Key' => 'wrong' ) ), 401, 'swpm_rest_api_unauthorized' );
	}

	public function test_key_in_the_query_string_is_not_accepted() {
		$this->assert_error( $this->request( 'GET', '/members', null, array( 'key' => self::API_KEY ), array() ), 401, 'swpm_rest_api_unauthorized' );
	}

	public function test_valid_key_header() {
		$this->assert_success( $this->request( 'GET', '/members' ) );
	}

	public function test_bearer_key() {
		$this->assert_success( $this->request( 'GET', '/members', null, array(), array( 'Authorization' => 'Bearer ' . self::API_KEY ) ) );
	}

	public function test_no_key_configured_means_no_key_is_valid() {
		$this->configure( array( 'swpm-rest-api-key' => '' ) );

		$this->assert_error( $this->request( 'GET', '/members' ), 401, 'swpm_rest_api_unauthorized' );
	}

	public function test_administrator_without_a_key() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assert_success( $this->request( 'GET', '/members', null, array(), array() ) );
	}

	public function test_subscriber_without_a_key() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assert_error( $this->request( 'GET', '/members', null, array(), array() ), 401, 'swpm_rest_api_unauthorized' );
	}

	public function test_write_gate_blocks_member_create_and_creates_nothing() {
		$this->configure( array( 'swpm-rest-api-enable-write' => '' ) );
		$before = $this->count_members();

		$response = $this->request( 'POST', '/members', $this->member_payload( $this->create_level() ) );

		$this->assert_error( $response, 403, 'swpm_rest_api_write_disabled' );
		$this->assertSame( $before, $this->count_members() );
	}

	public function test_write_gate_blocks_member_update_and_delete() {
		$member_id = $this->insert_member_row( array( 'first_name' => 'Before' ) );
		$this->configure( array( 'swpm-rest-api-enable-write' => '' ) );

		$this->assert_error( $this->request( 'PATCH', '/members/' . $member_id, array( 'first_name' => 'After' ) ), 403, 'swpm_rest_api_write_disabled' );
		$this->assert_error( $this->request( 'DELETE', '/members/' . $member_id ), 403, 'swpm_rest_api_write_disabled' );

		$member = SwpmMemberUtils::get_user_by_id( $member_id );
		$this->assertSame( 'Before', $member->first_name );
	}

	public function test_write_gate_blocks_level_writes() {
		$level_id = $this->create_level();
		$this->configure( array( 'swpm-rest-api-enable-write' => '' ) );

		$this->assert_error( $this->request( 'POST', '/levels', array( 'name' => 'Blocked' ) ), 403, 'swpm_rest_api_write_disabled' );
		$this->assert_error( $this->request( 'PATCH', '/levels/' . $level_id, array( 'name' => 'Blocked' ) ), 403, 'swpm_rest_api_write_disabled' );
		$this->assert_error( $this->request( 'DELETE', '/levels/' . $level_id ), 403, 'swpm_rest_api_write_disabled' );
		$this->assertNotEmpty( SwpmUtils::get_membership_level_row_by_id( $level_id ) );
	}

	public function test_write_gate_still_allows_reads() {
		$this->configure( array( 'swpm-rest-api-enable-write' => '' ) );

		$this->assert_success( $this->request( 'GET', '/levels' ) );
	}

	public function test_router_refuses_a_route_without_middleware() {
		$router = new SWPM_REST_API_Router( SWPM_REST_API_NAMESPACE );
		$route  = $router->get( '/x', '__return_true' );

		$result = $router->run_middleware( $route, new WP_REST_Request( 'GET', '/swpm/v1/x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'swpm_rest_route_misconfigured', $result->get_error_code() );
	}

	public function test_router_refuses_unknown_middleware() {
		$router = new SWPM_REST_API_Router( SWPM_REST_API_NAMESPACE );
		$route  = $router->get( '/x', '__return_true' )->middleware( 'nonsense:read' );

		$result = $router->run_middleware( $route, new WP_REST_Request( 'GET', '/swpm/v1/x' ) );

		$this->assertSame( 'swpm_rest_route_misconfigured', $result->get_error_code() );
	}

	public function test_an_unrecognised_scope_is_treated_as_a_write() {
		$this->configure( array( 'swpm-rest-api-enable-write' => '' ) );

		$router = new SWPM_REST_API_Router( SWPM_REST_API_NAMESPACE );
		$router->register_middleware( 'auth', array( 'SWPM_REST_API_Auth', 'check_permission' ) );
		$route = $router->post( '/x', '__return_true' )->middleware( 'auth:wirte' );

		$request = new WP_REST_Request( 'POST', '/swpm/v1/x' );
		$request->set_header( 'X-SWPM-API-Key', self::API_KEY );

		$this->assertSame( 'swpm_rest_api_write_disabled', $router->run_middleware( $route, $request )->get_error_code() );
	}
}
