<?php

/**
 * Endpoints for member profiles. The routes are defined in routes/api.php.
 */
class SWPM_REST_API_Members_Controller extends SWPM_REST_API_Controller_Base {

	protected $rest_base = 'members';

	/**
	 * Columns a client is allowed to sort the collection by.
	 *
	 * @var array
	 */
	protected $sortable_columns = array(
		'member_id',
		'user_name',
		'first_name',
		'last_name',
		'email',
		'member_since',
		'last_accessed',
		'membership_level',
		'account_state',
	);

	/**
	 * Cache of the membership level id => alias map, so a list response does not run one
	 * lookup query per row.
	 *
	 * @var array|null
	 */
	protected $level_name_map = null;

	public function get_response_schema() {
		return array(
			'member_id'                => 'integer',
			'user_name'                => 'string',
			'first_name'               => '?string',
			'last_name'                => '?string',
			'email'                    => '?string',
			'phone'                    => '?string',
			'membership_level'         => 'integer',
			'membership_level_name'    => 'string',
			'initial_membership_level' => '?integer',
			'account_state'            => '?string',
			'member_since'             => 'string',
			'subscription_starts'      => '?string',
			'expiry_date'              => '?string',
			'is_expired'               => '?boolean',
			'last_accessed'            => 'string',
			'last_accessed_from_ip'    => 'string',
			'company_name'             => '?string',
			'address_street'           => '?string',
			'address_city'             => '?string',
			'address_state'            => '?string',
			'address_zipcode'          => '?string',
			'country'                  => '?string',
			'home_page'                => '?string',
			'gender'                   => '?string',
			'referrer'                 => '?string',
			'notes'                    => '?string',
			'profile_image'            => '?string',
			'txn_id'                   => '?string',
			'subscr_id'                => '?string',
			'wp_user_id'               => '?integer',
		);
	}

	/**
	 * The query parameters accepted by the collection endpoint.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = $this->get_common_collection_params();

		$params['search']['description'] = __( 'Limit the results to members matching this term in their username, first name, last name or email address.', 'swpm-rest-api' );

		$params['orderby'] = array(
			'description'       => __( 'Column to sort the results by.', 'swpm-rest-api' ),
			'type'              => 'string',
			'default'           => 'member_id',
			'enum'              => $this->sortable_columns,
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['membership_level'] = array(
			'description'       => __( 'Limit the results to members of this membership level id.', 'swpm-rest-api' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		);

		$params['account_state'] = array(
			'description'       => __( 'Limit the results to members with this account status.', 'swpm-rest-api' ),
			'type'              => 'string',
			'enum'              => array( 'active', 'inactive', 'activation_required', 'expired', 'pending', 'unsubscribed' ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['email'] = array(
			'description'       => __( 'Limit the results to the member with this exact email address.', 'swpm-rest-api' ),
			'type'              => 'string',
			'format'            => 'email',
			'sanitize_callback' => 'sanitize_email',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * GET /swpm/v1/members
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		global $wpdb;

		$members_table = $wpdb->prefix . 'swpm_members_tbl';

		//Build the WHERE clause. Every value goes in as a bound parameter.
		$where  = array( '1=1' );
		$values = array();

		$search = $request['search'];
		if ( ! empty( $search ) ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( user_name LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s )';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $request['membership_level'] ) ) {
			$where[]  = 'membership_level = %d';
			$values[] = (int) $request['membership_level'];
		}

		if ( ! empty( $request['account_state'] ) ) {
			$where[]  = 'account_state = %s';
			$values[] = $request['account_state'];
		}

		if ( ! empty( $request['email'] ) ) {
			$where[]  = 'email = %s';
			$values[] = $request['email'];
		}

		$where_sql = implode( ' AND ', $where );

		//Total count first, so the pagination headers can be filled in.
		$count_sql = "SELECT COUNT(*) FROM {$members_table} WHERE {$where_sql}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		//The orderby/order values come from an allowlist, so they are safe to interpolate.
		$orderby = $this->sanitize_orderby( $request['orderby'], $this->sortable_columns, 'member_id' );
		$order   = $this->sanitize_order( $request['order'] );

		$query_values   = $values;
		$query_values[] = (int) $request['per_page'];
		$query_values[] = $this->get_pagination_offset( $request );

		$sql  = "SELECT * FROM {$members_table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_values ) );

		$wp_user_ids = $this->get_wp_user_id_map( $rows );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->prepare_item_for_response( $row, $wp_user_ids );
		}

		return $this->prepare_collection_response( $items, $total, $request );
	}

	/**
	 * GET /swpm/v1/members/<id>
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$member_id = (int) $request['id'];
		$member    = SwpmMemberUtils::get_user_by_id( $member_id );

		if ( empty( $member ) ) {
			return $this->not_found_error( __( 'No member profile was found for the given id.', 'swpm-rest-api' ) );
		}

		$wp_user_ids = $this->get_wp_user_id_map( array( $member ) );
		$item        = $this->prepare_item_for_response( $member, $wp_user_ids );

		return $this->prepare_item_response( $item, $this->get_member_links( $item ) );
	}

	/**
	 * Related resource links for one member.
	 *
	 * @param array $item A prepared member item.
	 * @return array
	 */
	protected function get_member_links( $item ) {
		$links = array(
			'self'         => $this->get_item_url( $item['member_id'] ),
			'transactions' => add_query_arg(
				array( 'member_id' => $item['member_id'] ),
				rest_url( $this->namespace . '/transactions' )
			),
		);

		if ( ! empty( $item['membership_level'] ) ) {
			$links['level'] = rest_url( $this->namespace . '/levels/' . $item['membership_level'] );
		}

		return $links;
	}

	/**
	 * POST /swpm/v1/members
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		global $wpdb;

		$validated = SWPM_REST_API_Member_Validator::validate_for_create( $request );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$member         = $validated['member'];
		$plain_password = $validated['plain_password'];

		$inserted = $wpdb->insert( $wpdb->prefix . 'swpm_members_tbl', $member );
		if ( ! $inserted ) {
			SwpmLog::log_simple_debug( 'SWPM REST API: failed to insert the new member record. ' . $wpdb->last_error, false );
			return new WP_Error(
				'swpm_rest_create_failed',
				__( 'The member record could not be saved.', 'swpm-rest-api' ),
				array( 'status' => 500 )
			);
		}

		$member_id = (int) $wpdb->insert_id;

		$wp_user_id = $this->create_linked_wp_user( $member, $plain_password );

		if ( is_wp_error( $wp_user_id ) ) {
			//The member row was written before the WordPress user could be created, which is the
			//order the plugin's own admin flow uses. Roll it back rather than leaving a member
			//profile behind that has no account to log in with.
			$wpdb->delete( $wpdb->prefix . 'swpm_members_tbl', array( 'member_id' => $member_id ) );
			SwpmLog::log_simple_debug( 'SWPM REST API: rolled back member record ' . $member_id . ' because the WordPress user could not be created.', false );
			return $wp_user_id;
		}

		$this->maybe_send_registration_email( $request, $member, $member_id, $plain_password );

		/**
		 * Fires after a member profile has been created through the REST API.
		 */
		do_action( 'swpm_rest_api_member_created', $member_id, $member );

		SwpmLog::log_simple_debug( 'SWPM REST API: member record created (ID: ' . $member_id . ').', true );

		$created = SwpmMemberUtils::get_user_by_id( $member_id );
		$item    = $this->prepare_item_for_response( $created, $this->get_wp_user_id_map( array( $created ) ) );

		$response = $this->prepare_item_response( $item, $this->get_member_links( $item ) );
		$response->set_status( 201 );
		$response->header( 'Location', $this->get_item_url( $member_id ) );

		return $response;
	}

	/**
	 * PUT/PATCH /swpm/v1/members/<id>
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		global $wpdb;

		$member_id = (int) $request['id'];
		$existing  = SwpmMemberUtils::get_user_by_id( $member_id );

		if ( empty( $existing ) ) {
			return $this->not_found_error( __( 'No member profile was found for the given id.', 'swpm-rest-api' ) );
		}

		$validated = SWPM_REST_API_Member_Validator::validate_for_update( $request, $existing );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$changes        = $validated['member'];
		$plain_password = $validated['plain_password'];
		$previous_level = (int) $existing->membership_level;
		$previous_state = $existing->account_state;

		$updated = $wpdb->update( $wpdb->prefix . 'swpm_members_tbl', $changes, array( 'member_id' => $member_id ) );

		//update() returns 0 when the new values match the old ones, which is not a failure.
		if ( false === $updated ) {
			SwpmLog::log_simple_debug( 'SWPM REST API: failed to update member record ' . $member_id . '. ' . $wpdb->last_error, false );
			return new WP_Error(
				'swpm_rest_update_failed',
				__( 'The member record could not be updated.', 'swpm-rest-api' ),
				array( 'status' => 500 )
			);
		}

		$this->sync_wp_user_after_update( $existing, $changes, $plain_password, $previous_level );

		//The plugin's own hooks so anything listening for a level or status change still fires.
		if ( isset( $changes['membership_level'] ) && (int) $changes['membership_level'] !== $previous_level ) {
			do_action(
				'swpm_membership_level_changed',
				array(
					'member_id'  => $member_id,
					'from_level' => $previous_level,
					'to_level'   => (int) $changes['membership_level'],
				)
			);
		}

		if ( isset( $changes['account_state'] ) && $changes['account_state'] !== $previous_state ) {
			do_action(
				'swpm_account_status_updated',
				array(
					'member_id'   => $member_id,
					'from_status' => $previous_state,
					'to_status'   => $changes['account_state'],
				)
			);
		}

		/**
		 * Fires after a member profile has been updated through the REST API.
		 */
		do_action( 'swpm_rest_api_member_updated', $member_id, $changes );

		SwpmLog::log_simple_debug( 'SWPM REST API: member record updated (ID: ' . $member_id . ').', true );

		$member = SwpmMemberUtils::get_user_by_id( $member_id );
		$item   = $this->prepare_item_for_response( $member, $this->get_wp_user_id_map( array( $member ) ) );

		return $this->prepare_item_response( $item, $this->get_member_links( $item ) );
	}

	/**
	 * DELETE /swpm/v1/members/<id>
	 *
	 * Removes the member profile and, unless it carries an administrator role, the linked
	 * WordPress user record too.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$member_id = (int) $request['id'];
		$existing  = SwpmMemberUtils::get_user_by_id( $member_id );

		if ( empty( $existing ) ) {
			return $this->not_found_error( __( 'No member profile was found for the given id.', 'swpm-rest-api' ) );
		}

		//Capture the profile before it goes, so the response can report what was removed.
		$previous = $this->prepare_item_for_response( $existing, $this->get_wp_user_id_map( array( $existing ) ) );

		SwpmMemberUtils::delete_member_and_wp_user( $member_id );

		if ( SwpmMemberUtils::member_record_exists( $member_id ) ) {
			SwpmLog::log_simple_debug( 'SWPM REST API: member record ' . $member_id . ' still exists after the delete call.', false );
			return new WP_Error(
				'swpm_rest_delete_failed',
				__( 'The member record could not be deleted.', 'swpm-rest-api' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires after a member profile has been deleted through the REST API.
		 */
		do_action( 'swpm_rest_api_member_deleted', $member_id );

		SwpmLog::log_simple_debug( 'SWPM REST API: member record deleted (ID: ' . $member_id . ').', true );

		return $this->prepare_item_response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Creates the WordPress user account that backs a new member profile.
	 *
	 * SwpmUtils::create_wp_user() calls wp_die() if the username or email belongs to an
	 * administrator, or if wp_create_user() fails. The validator rules out the first two cases
	 * and the username is checked against validate_username() before it gets here, so those
	 * paths should be unreachable. The return value is still checked in case a concurrent request
	 * claimed the username in between.
	 *
	 * @param array  $member         The member row that was just inserted.
	 * @param string $plain_password
	 * @return int|WP_Error The WordPress user id.
	 */
	protected function create_linked_wp_user( $member, $plain_password ) {
		$level_row = SwpmUtils::get_membership_level_row_by_id( $member['membership_level'] );

		$wp_user_info = array(
			'user_login'      => $member['user_name'],
			'user_nicename'   => implode( '-', explode( ' ', $member['user_name'] ) ),
			'display_name'    => $member['user_name'],
			'nickname'        => $member['user_name'],
			'user_email'      => $member['email'],
			'password'        => $plain_password,
			'role'            => empty( $level_row->role ) ? 'subscriber' : $level_row->role,
			'user_registered' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( isset( $member['first_name'] ) ) {
			$wp_user_info['first_name'] = $member['first_name'];
		}
		if ( isset( $member['last_name'] ) ) {
			$wp_user_info['last_name'] = $member['last_name'];
		}

		$wp_user_id = SwpmUtils::create_wp_user( $wp_user_info );

		if ( is_wp_error( $wp_user_id ) ) {
			return new WP_Error(
				'swpm_rest_wp_user_creation_failed',
				$wp_user_id->get_error_message(),
				array( 'status' => 400 )
			);
		}

		if ( empty( $wp_user_id ) ) {
			return new WP_Error(
				'swpm_rest_wp_user_creation_failed',
				__( 'The WordPress user account for this member could not be created.', 'swpm-rest-api' ),
				array( 'status' => 500 )
			);
		}

		return (int) $wp_user_id;
	}

	/**
	 * Pushes the relevant parts of a member update onto the linked WordPress user.
	 *
	 * @param object $existing       The member row as it was before the update.
	 * @param array  $changes        The columns that changed.
	 * @param string $plain_password Empty unless the password was changed.
	 * @param int    $previous_level The membership level before the update.
	 */
	protected function sync_wp_user_after_update( $existing, $changes, $plain_password, $previous_level ) {
		$wp_user = get_user_by( 'login', $existing->user_name );

		if ( ! $wp_user ) {
			//No linked WordPress account. Nothing to sync.
			return;
		}

		$wp_data = $changes;
		if ( '' !== $plain_password ) {
			//SwpmUtils::update_wp_user() looks for the password under this key.
			$wp_data['plain_password'] = $plain_password;
		}

		SwpmUtils::update_wp_user( $existing->user_name, $wp_data );

		//The WordPress role follows the membership level.
		if ( isset( $changes['membership_level'] ) && (int) $changes['membership_level'] !== $previous_level ) {
			SwpmMemberUtils::update_wp_user_role_with_level_id( $wp_user->ID, (int) $changes['membership_level'] );
		}
	}

	/**
	 * Sends the registration email for a new member, when that is wanted.
	 *
	 * The request can force this either way with 'send_notification'. When it says nothing, a
	 * member who has to activate their account is always emailed, since the activation link is
	 * only sent this way. Otherwise the plugin's "notification after manual user add" setting
	 * decides, which is what the admin dashboard does when a member is added by hand.
	 *
	 * @param WP_REST_Request $request
	 * @param array           $member
	 * @param int             $member_id
	 * @param string          $plain_password
	 */
	protected function maybe_send_registration_email( $request, $member, $member_id, $plain_password ) {
		$send_notification = $request['send_notification'];

		if ( null === $send_notification ) {
			$send_notification = 'activation_required' === $member['account_state']
				|| ! empty( SwpmSettings::get_instance()->get_value( 'enable-notification-after-manual-user-add' ) );
		}

		if ( ! $send_notification ) {
			return;
		}

		$member_info                   = $member;
		$member_info['member_id']      = $member_id;
		$member_info['plain_password'] = $plain_password;

		$notifier = new SWPM_REST_API_Registration_Notifier();
		$notifier->send_registration_email( $member_info );

		SwpmLog::log_simple_debug( 'SWPM REST API: registration email sent for member ID ' . $member_id . '.', true );
	}

	/**
	 * Shapes a member row into the API response.
	 *
	 * Only the fields listed here are ever returned. The 'password' and 'reg_code' columns are
	 * credentials and must never leave the site. The 'extra_info' column holds serialized data
	 * written by the payment gateways, so it is left out of the read-only response too.
	 *
	 * @param object $member      Row from the swpm_members_tbl table.
	 * @param array  $wp_user_ids Map of lowercased user_name => WP user id.
	 * @return array
	 */
	protected function prepare_item_for_response( $member, $wp_user_ids = array() ) {
		$expiry      = $this->get_expiry_info( $member );
		$user_name   = isset( $member->user_name ) ? $member->user_name : '';
		$lookup_key  = strtolower( $user_name );
		$wp_user_id  = isset( $wp_user_ids[ $lookup_key ] ) ? (int) $wp_user_ids[ $lookup_key ] : null;
		$level_id    = isset( $member->membership_level ) ? (int) $member->membership_level : 0;

		$data = array(
			'member_id'                => (int) $member->member_id,
			'user_name'                => $user_name,
			'first_name'               => $member->first_name,
			'last_name'                => $member->last_name,
			'email'                    => $member->email,
			'phone'                    => $member->phone,
			'membership_level'         => $level_id,
			'membership_level_name'    => $this->get_level_name( $level_id ),
			'initial_membership_level' => is_null( $member->initial_membership_level ) ? null : (int) $member->initial_membership_level,
			'account_state'            => $member->account_state,
			'member_since'             => $member->member_since,
			'subscription_starts'      => $member->subscription_starts,
			'expiry_date'              => $expiry['expiry_date'],
			'is_expired'               => $expiry['is_expired'],
			'last_accessed'            => $member->last_accessed,
			'last_accessed_from_ip'    => $member->last_accessed_from_ip,
			'company_name'             => $member->company_name,
			'address_street'           => $member->address_street,
			'address_city'             => $member->address_city,
			'address_state'            => $member->address_state,
			'address_zipcode'          => $member->address_zipcode,
			'country'                  => $member->country,
			'home_page'                => $member->home_page,
			'gender'                   => $member->gender,
			'referrer'                 => $member->referrer,
			'notes'                    => $member->notes,
			'profile_image'            => $member->profile_image,
			'txn_id'                   => $member->txn_id,
			'subscr_id'                => $member->subscr_id,
			'wp_user_id'               => $wp_user_id,
		);

		/**
		 * Lets other addons add fields to (or remove fields from) a member API response.
		 */
		return apply_filters( 'swpm_rest_api_prepare_member', $data, $member );
	}

	/**
	 * Works out the expiry date of a member profile.
	 *
	 * SwpmUtils::get_expiration_timestamp() calls wp_die() when the member has an invalid
	 * membership level assigned and the call is not coming from the admin side. A REST request is
	 * not an admin side request, so the level is validated first here. Otherwise one broken member
	 * row would kill the whole response.
	 *
	 * @param object $member
	 * @return array With the 'expiry_date' and 'is_expired' keys.
	 */
	protected function get_expiry_info( $member ) {
		$unknown = array(
			'expiry_date' => null,
			'is_expired'  => null,
		);

		if ( ! isset( $member->membership_level ) || ! is_numeric( $member->membership_level ) ) {
			return $unknown;
		}

		if ( ! SwpmMembershipLevelUtils::check_if_membership_level_exists( $member->membership_level ) ) {
			return $unknown;
		}

		$timestamp = SwpmUtils::get_expiration_timestamp( $member );
		if ( empty( $timestamp ) ) {
			return $unknown;
		}

		if ( PHP_INT_MAX === $timestamp ) {
			//The membership level is configured with no expiry.
			return array(
				'expiry_date' => null,
				'is_expired'  => false,
			);
		}

		return array(
			'expiry_date' => gmdate( 'Y-m-d', $timestamp ),
			'is_expired'  => $timestamp < time(),
		);
	}

	/**
	 * Returns the alias (display name) of a membership level.
	 *
	 * @param int $level_id
	 * @return string
	 */
	protected function get_level_name( $level_id ) {
		//The controller can serve several requests in one PHP request (a REST batch request, for
		//example), so a level created after the map was built must trigger a reload.
		if ( is_null( $this->level_name_map ) || ! isset( $this->level_name_map[ $level_id ] ) ) {
			global $wpdb;

			//SwpmMembershipLevelUtils::get_all_membership_levels_in_array() is not used here because
			//it leaves out level id 1. That is the right call for a level picker, but a member row
			//could still point at it, and reporting an empty name for that member would be misleading.
			$levels_table = $wpdb->prefix . 'swpm_membership_tbl';
			$levels       = $wpdb->get_results( "SELECT id, alias FROM {$levels_table}" );

			$this->level_name_map = array();
			foreach ( $levels as $level ) {
				$this->level_name_map[ (int) $level->id ] = $level->alias;
			}
		}

		return isset( $this->level_name_map[ $level_id ] ) ? $this->level_name_map[ $level_id ] : '';
	}

	/**
	 * Resolves the WP user id for a batch of member rows in a single query.
	 *
	 * A SWPM member profile is linked to a WP user record by username, so looking these up one at
	 * a time (as SwpmMemberUtils::get_wp_user_from_swpm_user_id does) would mean two extra queries
	 * per row in a list response.
	 *
	 * @param array $rows Member rows.
	 * @return array Map of lowercased user_name => WP user id.
	 */
	protected function get_wp_user_id_map( $rows ) {
		global $wpdb;

		$user_names = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row->user_name ) ) {
				$user_names[] = $row->user_name;
			}
		}

		if ( empty( $user_names ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $user_names ), '%s' ) );
		$sql          = "SELECT ID, user_login FROM {$wpdb->users} WHERE user_login IN ({$placeholders})";
		$results      = $wpdb->get_results( $wpdb->prepare( $sql, $user_names ) );

		$map = array();
		foreach ( $results as $result ) {
			$map[ strtolower( $result->user_login ) ] = (int) $result->ID;
		}

		return $map;
	}
}
