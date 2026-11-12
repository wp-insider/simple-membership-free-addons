<?php
/**
 * Member endpoints: reads, create, update and delete.
 */
class Test_SWPM_REST_API_Members extends SWPM_REST_API_Test_Case {

	/*
	 * Reads
	 */

	public function test_list_uses_the_envelope_and_counts_correctly() {
		$level_id = $this->create_level();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->insert_member_row( array( 'membership_level' => $level_id ) );
		}

		$response = $this->request( 'GET', '/members', null, array( 'membership_level' => $level_id, 'per_page' => 1, 'page' => 2 ) );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $body['data'] );
		$this->assertSame( 3, $body['meta']['total'] );
		$this->assertSame( 3, $body['meta']['total_pages'] );
		$this->assertSame( 2, $body['meta']['current_page'] );
		$this->assertTrue( $body['meta']['has_more'] );
		$this->assertSame( 3, (int) $response->get_headers()['X-WP-Total'] );
	}

	public function test_pagination_links_keep_the_filters() {
		$level_id = $this->create_level();
		for ( $i = 0; $i < 2; $i++ ) {
			$this->insert_member_row( array( 'membership_level' => $level_id ) );
		}

		$body = $this->request( 'GET', '/members', null, array( 'membership_level' => $level_id, 'account_state' => 'active', 'per_page' => 1 ) )->get_data();

		$this->assertNull( $body['links']['prev'] );
		$this->assertStringContainsString( 'page=2', $body['links']['next'] );
		$this->assertStringContainsString( 'account_state=active', $body['links']['next'] );
		$this->assertStringContainsString( 'membership_level=' . $level_id, $body['links']['next'] );
	}

	public function test_responses_never_contain_credentials() {
		$member_id = $this->insert_member_row( array( 'reg_code' => 'secret-code', 'extra_info' => 'secret-info' ) );

		$item = $this->assert_success( $this->request( 'GET', '/members/' . $member_id ) );
		$list = $this->request( 'GET', '/members' )->get_data();

		foreach ( array( 'password', 'reg_code', 'extra_info' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $item );
			$this->assertArrayNotHasKey( $field, $list['data'][0] );
		}
	}

	public function test_single_member_has_level_name_wp_user_and_links() {
		$level_id = $this->create_level( array( 'alias' => 'Silver' ) );
		$member   = $this->create_member( $level_id );

		$body = $this->request( 'GET', '/members/' . $member['member_id'] )->get_data();

		$this->assertSame( 'Silver', $body['data']['membership_level_name'] );
		$this->assertSame( get_user_by( 'login', $member['user_name'] )->ID, $body['data']['wp_user_id'] );
		$this->assertArrayNotHasKey( 'meta', $body );
		foreach ( array( 'self', 'level', 'transactions' ) as $link ) {
			$this->assertArrayHasKey( $link, $body['links'] );
		}
	}

	public function test_no_expiry_level_reports_no_expiry_date() {
		$member_id = $this->insert_member_row();

		$item = $this->assert_success( $this->request( 'GET', '/members/' . $member_id ) );

		$this->assertNull( $item['expiry_date'] );
		$this->assertFalse( $item['is_expired'] );
	}

	public function test_member_with_a_deleted_level_does_not_break_the_response() {
		$member_id = $this->insert_member_row( array( 'membership_level' => 99999 ) );

		$item = $this->assert_success( $this->request( 'GET', '/members/' . $member_id ) );

		$this->assertNull( $item['expiry_date'] );
		$this->assertNull( $item['is_expired'] );
	}

	public function test_missing_member() {
		$this->assert_error( $this->request( 'GET', '/members/999999' ), 404, 'swpm_rest_not_found' );
	}

	public function test_invalid_list_parameters_are_rejected() {
		foreach ( array( array( 'orderby' => 'password' ), array( 'per_page' => 5000 ), array( 'email' => 'notanemail' ), array( 'account_state' => 'banana' ) ) as $query ) {
			$this->assertSame( 400, $this->request( 'GET', '/members', null, $query )->get_status(), wp_json_encode( $query ) );
		}
	}

	/*
	 * Create
	 */

	public function test_create_member_and_wp_user() {
		$level_id = $this->create_level( array( 'role' => 'contributor' ) );

		$response = $this->request( 'POST', '/members', $this->member_payload( $level_id, array( 'user_name' => 'apitestcreate', 'password' => 'TestPass123' ) ) );
		$item     = $this->assert_success( $response, 201 );

		$this->assertStringEndsWith( '/swpm/v1/members/' . $item['member_id'], $response->get_headers()['Location'] );
		$this->assertSame( SwpmUtils::get_current_date_in_wp_zone(), $item['member_since'] );
		$this->assertSame( SwpmUtils::get_current_date_in_wp_zone(), $item['subscription_starts'] );

		$wp_user = get_user_by( 'login', 'apitestcreate' );
		$this->assertInstanceOf( 'WP_User', $wp_user );
		$this->assertContains( 'contributor', $wp_user->roles );
		$this->assertTrue( wp_check_password( 'TestPass123', $wp_user->user_pass, $wp_user->ID ) );

		//The member record must accept the same password as the login form will check.
		$member = SwpmMemberUtils::get_user_by_id( $item['member_id'] );
		$this->assertTrue( wp_check_password( 'TestPass123', $member->password ) );
	}

	public function test_create_rejections_leave_nothing_behind() {
		$level_id = $this->create_level();
		$existing = $this->create_member( $level_id );
		self::factory()->user->create( array( 'user_login' => 'apitestadmin', 'user_email' => 'apitestadmin@example.com', 'role' => 'administrator' ) );
		self::factory()->user->create( array( 'user_login' => 'apitestplainwp', 'user_email' => 'apitestplainwp@example.com', 'role' => 'subscriber' ) );

		$cases = array(
			'duplicate username'             => array( 'user_name', array( 'user_name' => $existing['user_name'] ) ),
			'duplicate email'                => array( 'email', array( 'email' => $existing['email'] ) ),
			'missing level'                  => array( 'membership_level', array( 'membership_level' => 999999 ) ),
			'level 1'                        => array( 'membership_level', array( 'membership_level' => 1 ) ),
			'administrator email'            => array( 'email', array( 'email' => 'apitestadmin@example.com' ) ),
			'administrator username'         => array( 'user_name', array( 'user_name' => 'apitestadmin' ) ),
			'existing WordPress user email'  => array( 'email', array( 'email' => 'apitestplainwp@example.com' ) ),
			'username WordPress rejects'     => array( 'user_name', array( 'user_name' => 'bad*name' ) ),
			'empty password'                 => array( 'password', array( 'password' => '' ) ),
			'unknown country'                => array( 'country', array( 'country' => 'Narnia' ) ),
		);

		$members_before  = $this->count_members();
		$wp_users_before = count_users()['total_users'];

		foreach ( $cases as $label => $case ) {
			list( $field, $overrides ) = $case;

			$response = $this->request( 'POST', '/members', $this->member_payload( $level_id, $overrides ) );
			$data     = $response->get_data();

			$this->assertSame( 400, $response->get_status(), $label . ': ' . wp_json_encode( $data ) );
			$this->assertSame( 'swpm_rest_invalid_member_data', $data['code'], $label );
			$this->assertArrayHasKey( $field, $data['data']['errors'], $label );
		}

		$this->assertSame( $members_before, $this->count_members() );
		$this->assertSame( $wp_users_before, count_users()['total_users'] );
	}

	public function test_create_requires_the_mandatory_fields() {
		$response = $this->request( 'POST', '/members', array( 'user_name' => 'apitestincomplete' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );
	}

	/*
	 * Passwords
	 */

	public function test_any_non_empty_password_is_accepted_by_default() {
		$this->create_member( $this->create_level(), array( 'password' => 'a' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_strong_password_rule_applies_when_the_setting_is_on() {
		$this->configure( array( 'force-strong-passwords' => '1' ) );
		$level_id = $this->create_level();

		foreach ( array( 'Ab1', 'abcdefgh1', 'Abcdefgh' ) as $weak ) {
			$data = $this->request( 'POST', '/members', $this->member_payload( $level_id, array( 'password' => $weak ) ) )->get_data();
			$this->assertArrayHasKey( 'password', $data['data']['errors'], $weak );
		}

		$this->create_member( $level_id, array( 'password' => 'Abcdefg1' ) );
	}

	public function test_strong_password_minimum_length_filter() {
		$this->configure( array( 'force-strong-passwords' => '1' ) );
		add_filter(
			'swpm_reg_pass_min_length_validation',
			function () {
				return 3;
			}
		);

		$this->create_member( $this->create_level(), array( 'password' => 'Ab1' ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_strong_password_rule_on_update() {
		$member = $this->create_member( $this->create_level() );
		$this->configure( array( 'force-strong-passwords' => '1' ) );

		$data = $this->request( 'PATCH', '/members/' . $member['member_id'], array( 'password' => 'weak' ) )->get_data();

		$this->assertArrayHasKey( 'password', $data['data']['errors'] );
	}

	/*
	 * Country
	 */

	public function test_country_codes_and_names() {
		$level_id = $this->create_level();

		$expected = array(
			'US'        => 'United States of America',
			'us'        => 'United States of America',
			'AU'        => 'Australia',
			'australia' => 'Australia',
			'PR'        => 'Puerto Rico',
		);

		foreach ( $expected as $input => $stored ) {
			$item = $this->create_member( $level_id, array( 'country' => $input ) );
			$this->assertSame( $stored, $item['country'], $input );
		}
	}

	public function test_country_can_be_cleared() {
		$member = $this->create_member( $this->create_level(), array( 'country' => 'AU' ) );

		$item = $this->assert_success( $this->request( 'PATCH', '/members/' . $member['member_id'], array( 'country' => '' ) ) );

		$this->assertSame( '', $item['country'] );
	}

	public function test_an_unchanged_old_country_spelling_is_accepted() {
		$member_id = $this->insert_member_row( array( 'country' => 'United States' ) );

		$this->assert_success( $this->request( 'PATCH', '/members/' . $member_id, array( 'country' => 'United States', 'first_name' => 'Kept' ) ) );

		$data = $this->request( 'PATCH', '/members/' . $member_id, array( 'country' => 'Russian Federation' ) )->get_data();
		$this->assertArrayHasKey( 'country', $data['data']['errors'] );
	}

	public function test_country_validation_uses_the_filtered_list() {
		add_filter(
			'swpm_countries_list',
			function ( $countries ) {
				$countries['ZZ'] = 'Atlantis';
				$countries['US'] = 'USA';
				return $countries;
			}
		);
		$level_id = $this->create_level();

		$this->assertSame( 'Atlantis', $this->create_member( $level_id, array( 'country' => 'ZZ' ) )['country'] );
		$this->assertSame( 'Atlantis', $this->create_member( $level_id, array( 'country' => 'Atlantis' ) )['country'] );
		$this->assertSame( 'USA', $this->create_member( $level_id, array( 'country' => 'US' ) )['country'] );

		$data = $this->request( 'POST', '/members', $this->member_payload( $level_id, array( 'country' => 'United States of America' ) ) )->get_data();
		$this->assertArrayHasKey( 'country', $data['data']['errors'] );

		//Payment country codes are converted with the unfiltered list.
		$this->assertSame( 'United States of America', SwpmMiscUtils::get_country_name_by_country_code( 'US' ) );
	}

	/*
	 * New member status
	 */

	public function test_explicit_status_wins() {
		$level_id = $this->create_level();
		update_option( 'swpm_email_activation_lvl_' . $level_id, '1' );

		$this->assertSame( 'pending', $this->create_member( $level_id, array( 'account_state' => 'pending' ) )['account_state'] );
	}

	public function test_email_activation_level_gives_activation_required_and_sends_the_email() {
		$this->configure(
			array(
				'email-activation-mail-subject' => 'Activate your account',
				'email-activation-mail-body'    => 'Activate here: {activation_link}',
				'email-from'                    => 'Site <site@example.com>',
			)
		);
		$level_id = $this->create_level();
		update_option( 'swpm_email_activation_lvl_' . $level_id, '1' );

		//Leave send_notification out, so the default decides.
		$payload = $this->member_payload( $level_id );
		unset( $payload['send_notification'] );
		$member = $this->assert_success( $this->request( 'POST', '/members', $payload ), 201 );

		$this->assertSame( 'activation_required', $member['account_state'] );

		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $sent, 'No email was sent.' );
		$this->assertSame( $member['email'], $sent->to[0][0] );
		$this->assertStringContainsString( 'swpm_email_activation=1', $sent->body );
	}

	public function test_level_default_status_is_used() {
		$level_id = $this->create_level();
		SwpmMembershipLevelCustom::get_instance_by_id( $level_id )->set(
			array(
				'meta_key'     => 'default_account_status',
				'meta_value'   => 'pending',
				'meta_context' => 'account-status',
			)
		);

		$this->assertSame( 'pending', $this->create_member( $level_id )['account_state'] );
	}

	public function test_global_default_status_is_the_fallback() {
		$this->configure( array( 'default-account-status' => 'inactive' ) );

		$this->assertSame( 'inactive', $this->create_member( $this->create_level() )['account_state'] );
	}

	/*
	 * Update
	 */

	public function test_partial_update_leaves_other_fields_alone() {
		$member = $this->create_member( $this->create_level(), array( 'first_name' => 'Old', 'last_name' => 'Kept', 'country' => 'AU' ) );

		$item = $this->assert_success( $this->request( 'PATCH', '/members/' . $member['member_id'], array( 'first_name' => 'New' ) ) );

		$this->assertSame( 'New', $item['first_name'] );
		$this->assertSame( 'Kept', $item['last_name'] );
		$this->assertSame( 'Australia', $item['country'] );
		$this->assertSame( $member['email'], $item['email'] );
	}

	public function test_put_and_post_also_update() {
		$member = $this->create_member( $this->create_level() );

		$this->assertSame( 'ViaPut', $this->assert_success( $this->request( 'PUT', '/members/' . $member['member_id'], array( 'first_name' => 'ViaPut' ) ) )['first_name'] );
		$this->assertSame( 'ViaPost', $this->assert_success( $this->request( 'POST', '/members/' . $member['member_id'], array( 'first_name' => 'ViaPost' ) ) )['first_name'] );
	}

	public function test_email_change_syncs_to_the_wp_user() {
		$member = $this->create_member( $this->create_level() );

		$this->assert_success( $this->request( 'PATCH', '/members/' . $member['member_id'], array( 'email' => 'apitestchanged@example.com' ) ) );

		$this->assertSame( 'apitestchanged@example.com', get_user_by( 'login', $member['user_name'] )->user_email );
	}

	public function test_level_change_updates_the_role_and_fires_the_hook() {
		$member    = $this->create_member( $this->create_level( array( 'role' => 'subscriber' ) ) );
		$new_level = $this->create_level( array( 'role' => 'contributor' ) );
		$fired     = did_action( 'swpm_membership_level_changed' );

		$item = $this->assert_success( $this->request( 'PATCH', '/members/' . $member['member_id'], array( 'membership_level' => $new_level ) ) );

		$this->assertSame( $new_level, $item['membership_level'] );
		$this->assertContains( 'contributor', get_user_by( 'login', $member['user_name'] )->roles );
		$this->assertSame( $fired + 1, did_action( 'swpm_membership_level_changed' ) );
	}

	public function test_status_change_fires_the_hook() {
		$member = $this->create_member( $this->create_level() );
		$fired  = did_action( 'swpm_account_status_updated' );

		$this->assert_success( $this->request( 'PATCH', '/members/' . $member['member_id'], array( 'account_state' => 'inactive' ) ) );

		$this->assertSame( $fired + 1, did_action( 'swpm_account_status_updated' ) );
	}

	public function test_password_change() {
		$member = $this->create_member( $this->create_level() );

		$this->assert_success( $this->request( 'PATCH', '/members/' . $member['member_id'], array( 'password' => 'NewPass456' ) ) );

		$wp_user = get_user_by( 'login', $member['user_name'] );
		$this->assertTrue( wp_check_password( 'NewPass456', $wp_user->user_pass, $wp_user->ID ) );
		$this->assertTrue( wp_check_password( 'NewPass456', SwpmMemberUtils::get_user_by_id( $member['member_id'] )->password ) );
	}

	public function test_user_name_cannot_be_changed() {
		$member = $this->create_member( $this->create_level() );

		$item = $this->assert_success( $this->request( 'PATCH', '/members/' . $member['member_id'], array( 'user_name' => 'apitestrenamed', 'first_name' => 'X' ) ) );

		$this->assertSame( $member['user_name'], $item['user_name'] );
	}

	public function test_empty_update_is_rejected() {
		$member = $this->create_member( $this->create_level() );

		$this->assert_error( $this->request( 'PATCH', '/members/' . $member['member_id'], array() ), 400, 'swpm_rest_nothing_to_update' );
	}

	public function test_update_missing_member() {
		$this->assert_error( $this->request( 'PATCH', '/members/999999', array( 'first_name' => 'X' ) ), 404, 'swpm_rest_not_found' );
	}

	/*
	 * Delete
	 */

	public function test_delete_removes_the_member_and_wp_user() {
		$member = $this->create_member( $this->create_level() );

		$body = $this->request( 'DELETE', '/members/' . $member['member_id'] )->get_data();

		$this->assertTrue( $body['data']['deleted'] );
		$this->assertSame( $member['member_id'], $body['data']['previous']['member_id'] );
		$this->assertFalse( SwpmMemberUtils::member_record_exists( $member['member_id'] ) );
		$this->assertFalse( get_user_by( 'login', $member['user_name'] ) );

		$this->assert_error( $this->request( 'DELETE', '/members/' . $member['member_id'] ), 404, 'swpm_rest_not_found' );
	}

	public function test_delete_never_removes_an_administrator() {
		$admin_id  = self::factory()->user->create( array( 'user_login' => 'apitestlinkedadmin', 'role' => 'administrator' ) );
		$member_id = $this->insert_member_row( array( 'user_name' => 'apitestlinkedadmin' ) );

		$this->assert_success( $this->request( 'DELETE', '/members/' . $member_id ) );

		$this->assertFalse( SwpmMemberUtils::member_record_exists( $member_id ) );
		$this->assertInstanceOf( 'WP_User', get_user_by( 'id', $admin_id ) );
	}
}
