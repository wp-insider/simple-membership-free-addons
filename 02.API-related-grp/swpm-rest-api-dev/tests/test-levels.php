<?php
/**
 * Membership level endpoints, including the settings stored outside the main level table.
 */
class Test_SWPM_REST_API_Levels extends SWPM_REST_API_Test_Case {

	/*
	 * Reads and level 1
	 */

	public function test_level_1_is_hidden_everywhere() {
		$body = $this->request( 'GET', '/levels', null, array( 'per_page' => 100 ) )->get_data();

		$this->assertNotContains( 1, wp_list_pluck( $body['data'], 'id' ) );
		$this->assert_error( $this->request( 'GET', '/levels/1' ), 404, 'swpm_rest_not_found' );
		$this->assert_error( $this->request( 'PATCH', '/levels/1', array( 'name' => 'Hacked' ) ), 404, 'swpm_rest_not_found' );
		$this->assert_error( $this->request( 'DELETE', '/levels/1' ), 404, 'swpm_rest_not_found' );
	}

	public function test_member_count_and_links() {
		$level_id = $this->create_level();
		$this->insert_member_row( array( 'membership_level' => $level_id ) );
		$this->insert_member_row( array( 'membership_level' => $level_id ) );

		$body = $this->request( 'GET', '/levels/' . $level_id )->get_data();

		$this->assertSame( 2, $body['data']['member_count'] );
		$this->assertStringContainsString( 'membership_level=' . $level_id, $body['links']['members'] );
	}

	/*
	 * Create
	 */

	public function test_create_levels_of_each_kind() {
		$no_expiry = $this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Free', 'subscription_duration_type' => 0 ) ), 201 );
		$days      = $this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Monthly', 'subscription_duration_type' => 1, 'subscription_period' => '30' ) ), 201 );
		$fixed     = $this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Fixed', 'subscription_duration_type' => 5, 'subscription_period' => '2027-06-30' ) ), 201 );

		$this->assertSame( '', $no_expiry['subscription_period'] );
		$this->assertSame( 'subscriber', $no_expiry['role'] );
		$this->assertSame( 'days', $days['subscription_duration_type_name'] );
		$this->assertSame( '2027-06-30', $fixed['subscription_period'] );
	}

	public function test_create_rejections() {
		$cases = array(
			'period missing'     => array( 'subscription_period', array( 'name' => 'X', 'subscription_duration_type' => 1 ) ),
			'period not numeric' => array( 'subscription_period', array( 'name' => 'X', 'subscription_duration_type' => 1, 'subscription_period' => 'thirty' ) ),
			'bad fixed date'     => array( 'subscription_period', array( 'name' => 'X', 'subscription_duration_type' => 5, 'subscription_period' => '30/06/2027' ) ),
			'unknown role'       => array( 'role', array( 'name' => 'X', 'role' => 'wizard' ) ),
			'empty name'         => array( 'name', array( 'name' => '   ' ) ),
		);

		foreach ( $cases as $label => $case ) {
			list( $field, $body ) = $case;

			$data = $this->request( 'POST', '/levels', $body )->get_data();

			$this->assertSame( 'swpm_rest_invalid_level_data', $data['code'], $label . ': ' . wp_json_encode( $data ) );
			$this->assertArrayHasKey( $field, $data['data']['errors'], $label );
		}
	}

	public function test_annual_fixed_date_level() {
		$level = $this->assert_success(
			$this->request(
				'POST',
				'/levels',
				array(
					'name'                         => 'Annual',
					'subscription_duration_type'   => 6,
					'subscription_period'          => '2030-06-30',
					'annual_fixed_date_min_period' => 30,
				)
			),
			201
		);

		//Only month and day carry meaning; the year is stored as the current one, as the dashboard does.
		$this->assertSame( gmdate( 'Y' ) . '-06-30', $level['subscription_period'] );
		$this->assertSame( 30, $level['annual_fixed_date_min_period'] );
		$this->assertSame( 1, $this->count_meta_rows( $level['id'], 'annual_fixed_date_min_period' ) );
	}

	public function test_create_writes_every_level_setting() {
		$level = $this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Plain' ) ), 201 );

		foreach ( array( 'annual_fixed_date_min_period', 'after_activation_redirect_page', 'default_account_status' ) as $meta_key ) {
			$this->assertSame( 1, $this->count_meta_rows( $level['id'], $meta_key ), $meta_key );
		}
		$this->assertNotFalse( get_option( 'swpm_email_activation_lvl_' . $level['id'] ) );
	}

	/*
	 * Level settings
	 */

	public function test_level_settings_round_trip() {
		$level = $this->assert_success(
			$this->request(
				'POST',
				'/levels',
				array(
					'name'                           => 'With settings',
					'default_account_status'         => 'pending',
					'email_activation'               => true,
					'after_activation_redirect_page' => 'https://example.com/welcome',
				)
			),
			201
		);

		$item = $this->assert_success( $this->request( 'GET', '/levels/' . $level['id'] ) );

		$this->assertSame( 'pending', $item['default_account_status'] );
		$this->assertTrue( $item['email_activation'] );
		$this->assertSame( 'https://example.com/welcome', $item['after_activation_redirect_page'] );
	}

	public function test_patching_one_setting_leaves_the_others_alone() {
		$level = $this->assert_success(
			$this->request(
				'POST',
				'/levels',
				array(
					'name'                           => 'Keep',
					'default_account_status'         => 'pending',
					'email_activation'               => true,
					'after_activation_redirect_page' => 'https://example.com/welcome',
				)
			),
			201
		);

		$item = $this->assert_success( $this->request( 'PATCH', '/levels/' . $level['id'], array( 'default_account_status' => 'inactive' ) ) );

		$this->assertSame( 'inactive', $item['default_account_status'] );
		$this->assertTrue( $item['email_activation'] );
		$this->assertSame( 'https://example.com/welcome', $item['after_activation_redirect_page'] );
		$this->assertSame( 'Keep', $item['name'] );
	}

	public function test_empty_default_status_means_the_global_setting() {
		$level = $this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Reset', 'default_account_status' => 'pending' ) ), 201 );

		$item = $this->assert_success( $this->request( 'PATCH', '/levels/' . $level['id'], array( 'default_account_status' => '' ) ) );

		$this->assertNull( $item['default_account_status'] );
	}

	public function test_only_a_setting_can_be_patched() {
		$level = $this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Annual', 'subscription_duration_type' => 6, 'subscription_period' => '2030-03-15', 'annual_fixed_date_min_period' => 10 ) ), 201 );

		$item = $this->assert_success( $this->request( 'PATCH', '/levels/' . $level['id'], array( 'annual_fixed_date_min_period' => 60 ) ) );
		$this->assertSame( 60, $item['annual_fixed_date_min_period'] );

		$item = $this->assert_success( $this->request( 'PATCH', '/levels/' . $level['id'], array( 'name' => 'Renamed' ) ) );
		$this->assertSame( 60, $item['annual_fixed_date_min_period'] );
	}

	public function test_repeated_patches_leave_one_row_per_setting() {
		$level = $this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Repeat' ) ), 201 );

		foreach ( array( 'pending', 'inactive', 'active', 'pending' ) as $status ) {
			$this->assert_success( $this->request( 'PATCH', '/levels/' . $level['id'], array( 'default_account_status' => $status, 'after_activation_redirect_page' => 'https://example.com/' . $status ) ) );
		}

		$this->assertSame( 1, $this->count_meta_rows( $level['id'], 'default_account_status' ) );
		$this->assertSame( 1, $this->count_meta_rows( $level['id'], 'after_activation_redirect_page' ) );
	}

	public function test_settings_saved_outside_the_api_are_read_by_it() {
		$level_id = $this->create_level();

		//The same calls the admin level screen makes.
		SwpmMembershipLevelCustom::get_instance_by_id( $level_id )->set(
			array(
				'meta_key'     => 'default_account_status',
				'meta_value'   => 'inactive',
				'meta_context' => 'account-status',
			)
		);
		update_option( 'swpm_email_activation_lvl_' . $level_id, '1', false );

		$item = $this->assert_success( $this->request( 'GET', '/levels/' . $level_id ) );

		$this->assertSame( 'inactive', $item['default_account_status'] );
		$this->assertTrue( $item['email_activation'] );
	}

	/**
	 * Tests the main plugin's own save, which the admin level screen uses, not the API's.
	 */
	public function test_core_level_save_removes_existing_duplicate_rows() {
		global $wpdb;
		$level_id = $this->create_level();
		$other_id = $this->create_level();

		//Duplicates left behind by older versions of the plugin, plus rows that must be left alone.
		$rows = array(
			array( $level_id, 'default_account_status', 'old1' ),
			array( $level_id, 'default_account_status', 'old2' ),
			array( $level_id, 'default_account_status', 'old3' ),
			array( $level_id, 'after_activation_redirect_page', 'https://example.com/keep' ),
			array( $other_id, 'default_account_status', 'other-level' ),
		);
		foreach ( $rows as $row ) {
			$wpdb->insert(
				$wpdb->prefix . 'swpm_membership_meta_tbl',
				array(
					'level_id'     => $row[0],
					'meta_key'     => $row[1],
					'meta_value'   => $row[2],
					'meta_context' => 'default',
				)
			);
		}

		SwpmMembershipLevelCustom::get_instance_by_id( $level_id )->set(
			array(
				'meta_key'     => 'default_account_status',
				'meta_value'   => 'pending',
				'meta_context' => 'account-status',
			)
		);

		$this->assertSame( 1, $this->count_meta_rows( $level_id, 'default_account_status' ) );
		$this->assertSame( 1, $this->count_meta_rows( $level_id, 'after_activation_redirect_page' ) );
		$this->assertSame( 1, $this->count_meta_rows( $other_id, 'default_account_status' ) );
		$this->assertSame( 'pending', $this->assert_success( $this->request( 'GET', '/levels/' . $level_id ) )['default_account_status'] );
	}

	/*
	 * Update
	 */

	public function test_changing_duration_type_needs_a_period() {
		$level = $this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Days', 'subscription_duration_type' => 1, 'subscription_period' => '30' ) ), 201 );

		$data = $this->request( 'PATCH', '/levels/' . $level['id'], array( 'subscription_duration_type' => 5 ) )->get_data();
		$this->assertArrayHasKey( 'subscription_period', $data['data']['errors'] );

		$item = $this->assert_success( $this->request( 'PATCH', '/levels/' . $level['id'], array( 'subscription_duration_type' => 5, 'subscription_period' => '2027-01-31' ) ) );
		$this->assertSame( 'fixed_date', $item['subscription_duration_type_name'] );

		$item = $this->assert_success( $this->request( 'PATCH', '/levels/' . $level['id'], array( 'subscription_duration_type' => 0 ) ) );
		$this->assertSame( '', $item['subscription_period'] );
	}

	public function test_empty_level_update_is_rejected() {
		$this->assert_error( $this->request( 'PATCH', '/levels/' . $this->create_level(), array() ), 400, 'swpm_rest_nothing_to_update' );
	}

	/*
	 * Delete
	 */

	public function test_level_in_use_cannot_be_deleted_without_force() {
		$level_id = $this->create_level();
		$this->insert_member_row( array( 'membership_level' => $level_id ) );

		$response = $this->request( 'DELETE', '/levels/' . $level_id );

		$this->assert_error( $response, 409, 'swpm_rest_level_in_use' );
		$this->assertSame( 1, $response->get_data()['data']['member_count'] );
		$this->assertNotEmpty( SwpmUtils::get_membership_level_row_by_id( $level_id ) );

		$this->assert_success( $this->request( 'DELETE', '/levels/' . $level_id, null, array( 'force' => 'true' ) ) );
		$this->assertEmpty( SwpmUtils::get_membership_level_row_by_id( $level_id ) );
	}

	public function test_delete_removes_the_level_settings() {
		$level = $this->assert_success( $this->request( 'POST', '/levels', array( 'name' => 'Gone', 'email_activation' => true, 'default_account_status' => 'pending' ) ), 201 );

		$body = $this->request( 'DELETE', '/levels/' . $level['id'] )->get_data();

		$this->assertTrue( $body['data']['deleted'] );
		$this->assertSame( 0, $this->count_meta_rows( $level['id'], 'default_account_status' ) );
		$this->assertFalse( get_option( 'swpm_email_activation_lvl_' . $level['id'] ) );
		$this->assert_error( $this->request( 'GET', '/levels/' . $level['id'] ), 404, 'swpm_rest_not_found' );
	}
}
