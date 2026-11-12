<?php

/**
 * Endpoints for membership levels. The routes are defined in routes/api.php.
 *
 * A level is stored in three places: the swpm_membership_tbl row, rows in
 * swpm_membership_meta_tbl, and the swpm_email_activation_lvl_{id} option.
 */
class SWPM_REST_API_Levels_Controller extends SWPM_REST_API_Controller_Base {

	protected $rest_base = 'levels';

	/**
	 * Values a client is allowed to sort the collection by, mapped onto their database columns.
	 *
	 * The level name is exposed as 'name' throughout the API, so that is what callers sort by
	 * even though the column behind it is called 'alias'.
	 *
	 * @var array
	 */
	protected $sortable_columns = array(
		'id'   => 'id',
		'name' => 'alias',
		'role' => 'role',
	);

	/**
	 * The settings stored as rows in swpm_membership_meta_tbl, with the same labels, types and
	 * contexts the admin level screens write.
	 *
	 * @var array
	 */
	protected $custom_fields = array(
		'annual_fixed_date_min_period'   => array(
			'label'   => 'Annual Fixed Date Minimum Period',
			'type'    => 'number',
			'context' => 'subscription-type',
		),
		'after_activation_redirect_page' => array(
			'label'   => 'After email activation redirect page URL',
			'type'    => 'url',
			'context' => 'email-activation',
		),
		'default_account_status'         => array(
			'label'   => 'Default Account Status Meta',
			'type'    => 'text',
			'context' => 'account-status',
		),
	);

	public function get_response_schema() {
		return array(
			'id'                              => 'integer',
			'name'                            => 'string',
			'role'                            => 'string',
			'subscription_period'             => 'string',
			'subscription_duration_type'      => 'integer',
			'subscription_duration_type_name' => 'string',
			'annual_fixed_date_min_period'    => '?integer',
			'default_account_status'          => '?string',
			'email_activation'                => 'boolean',
			'after_activation_redirect_page'  => '?string',
			'subscription_unit'               => '?string',
			'loginredirect_page'              => '?string',
			'protect_older_posts'             => 'boolean',
			'campaign_name'                   => 'string',
			'member_count'                    => 'integer',
		);
	}

	/**
	 * The query parameters accepted by the collection endpoint.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = $this->get_common_collection_params();

		//Levels read more naturally in creation order than newest first.
		$params['order']['default']      = 'asc';
		$params['search']['description'] = __( 'Limit the results to levels whose name matches this term.', 'swpm-rest-api' );

		$params['orderby'] = array(
			'description'       => __( 'Column to sort the results by.', 'swpm-rest-api' ),
			'type'              => 'string',
			'default'           => 'id',
			'enum'              => array_keys( $this->sortable_columns ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * GET /swpm/v1/levels
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		global $wpdb;

		$levels_table = $wpdb->prefix . 'swpm_membership_tbl';

		//Level id 1 is the internal "Content Protection" record the plugin creates at install time,
		//not a membership level anyone subscribes to. The admin dashboard hides it, so the API does too.
		$where  = array( 'id != 1' );
		$values = array();

		$search = $request['search'];
		if ( ! empty( $search ) ) {
			$where[]  = 'alias LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$levels_table} WHERE {$where_sql}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		//The orderby/order values come from an allowlist, so they are safe to interpolate.
		$orderby = $this->sanitize_orderby( $request['orderby'], array_keys( $this->sortable_columns ), 'id' );
		$orderby = $this->sortable_columns[ $orderby ];
		$order   = $this->sanitize_order( $request['order'] );

		$query_values   = $values;
		$query_values[] = (int) $request['per_page'];
		$query_values[] = $this->get_pagination_offset( $request );

		$sql  = "SELECT * FROM {$levels_table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_values ) );

		$member_counts = $this->get_member_counts();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->prepare_item_for_response( $row, $member_counts );
		}

		return $this->prepare_collection_response( $items, $total, $request );
	}

	/**
	 * GET /swpm/v1/levels/<id>
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$level_id = (int) $request['id'];

		//Level 1 is the internal "Content Protection" record, so it is treated as non-existent here
		//to stay consistent with the collection endpoint.
		if ( 1 === $level_id ) {
			return $this->not_found_error( __( 'No membership level was found for the given id.', 'swpm-rest-api' ) );
		}

		$level = SwpmUtils::get_membership_level_row_by_id( $level_id );

		if ( empty( $level ) ) {
			return $this->not_found_error( __( 'No membership level was found for the given id.', 'swpm-rest-api' ) );
		}

		$item = $this->prepare_item_for_response( $level, $this->get_member_counts() );

		return $this->prepare_item_response( $item, $this->get_level_links( $item ) );
	}

	/**
	 * Related resource links for one membership level.
	 *
	 * @param array $item A prepared level item.
	 * @return array
	 */
	protected function get_level_links( $item ) {
		return array(
			'self'    => $this->get_item_url( $item['id'] ),
			'members' => add_query_arg(
				array( 'membership_level' => $item['id'] ),
				rest_url( $this->namespace . '/members' )
			),
		);
	}

	/**
	 * The route argument schema for the membership level write endpoints.
	 *
	 * @param bool $for_create True for the create route, where the name becomes required.
	 * @return array
	 */
	public function get_write_params( $for_create = false ) {
		return array(
			'name'                       => array(
				'description'       => __( 'Display name of the membership level.', 'swpm-rest-api' ),
				'type'              => 'string',
				'required'          => $for_create,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'role'                       => array(
				'description'       => __( 'WordPress role given to members of this level.', 'swpm-rest-api' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'subscription_duration_type' => array(
				'description'       => __( 'How the access duration is expressed. 0 no expiry, 1 days, 2 weeks, 3 months, 4 years, 5 fixed date, 6 annual fixed date.', 'swpm-rest-api' ),
				'type'              => 'integer',
				'enum'              => array( 0, 1, 2, 3, 4, 5, 6 ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'subscription_period'        => array(
				'description'       => __( 'Length of access. A positive number for the days/weeks/months/years types, or a YYYY-MM-DD date for the fixed date types. Ignored when there is no expiry. For the annual fixed date type only the month and day are meaningful; the year is normalised on save.', 'swpm-rest-api' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'annual_fixed_date_min_period' => array(
				'description'       => __( 'Minimum number of days of access for the annual fixed date duration type. When a member joins fewer than this many days before the fixed date, their expiry moves to the following year.', 'swpm-rest-api' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'default_account_status'     => array(
				'description'       => __( 'Status given to new members of this level. An empty value uses the global setting.', 'swpm-rest-api' ),
				'type'              => 'string',
				'enum'              => array( '', 'active', 'inactive', 'activation_required', 'pending', 'expired' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'email_activation'           => array(
				'description' => __( 'Whether new members of this level must confirm their email address before they can log in.', 'swpm-rest-api' ),
				'type'        => 'boolean',
			),
			'after_activation_redirect_page' => array(
				'description'       => __( 'URL members are sent to after activating their account by email. An empty value clears it.', 'swpm-rest-api' ),
				'type'              => 'string',
				'format'            => 'uri',
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'loginredirect_page'         => array(
				'description'       => __( 'URL members of this level are sent to after logging in.', 'swpm-rest-api' ),
				'type'              => 'string',
				'format'            => 'uri',
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'campaign_name'              => array(
				'description'       => __( 'Campaign name recorded against this level.', 'swpm-rest-api' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'protect_older_posts'        => array(
				'description' => __( 'Whether posts published before a member joined are protected from them.', 'swpm-rest-api' ),
				'type'        => 'boolean',
			),
		);
	}

	/**
	 * The query parameters accepted by the delete endpoint.
	 *
	 * @return array
	 */
	public function get_delete_params() {
		return array(
			'force' => array(
				'description' => __( 'Delete the level even though members are still assigned to it. Those members are left pointing at a level that no longer exists.', 'swpm-rest-api' ),
				'type'        => 'boolean',
				'default'     => false,
			),
		);
	}

	/**
	 * POST /swpm/v1/levels
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		global $wpdb;

		$level = $this->collect_write_fields( $request );

		//Defaults matching a level created through the admin dashboard.
		if ( ! isset( $level['role'] ) ) {
			$level['role'] = 'subscriber';
		}
		if ( ! isset( $level['subscription_duration_type'] ) ) {
			$level['subscription_duration_type'] = SwpmMembershipLevel::NO_EXPIRY;
		}

		$duration_type = (int) $level['subscription_duration_type'];

		//Every duration type except "no expiry" needs an access duration to go with it. Letting
		//this through would fall back to the column default of -1, which is not a usable period.
		if ( SwpmMembershipLevel::NO_EXPIRY !== $duration_type && ! isset( $level['subscription_period'] ) ) {
			return $this->invalid_level_data_error(
				array(
					'subscription_period' => __( 'An access duration is required unless the level is set to never expire.', 'swpm-rest-api' ),
				)
			);
		}

		$error = $this->validate_level_data( $level, $duration_type );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$level = $this->normalize_subscription_period( $level, $duration_type );

		$inserted = $wpdb->insert( $wpdb->prefix . 'swpm_membership_tbl', $level );
		if ( ! $inserted ) {
			SwpmLog::log_simple_debug( 'SWPM REST API: failed to insert the new membership level. ' . $wpdb->last_error, false );
			return new WP_Error(
				'swpm_rest_create_failed',
				__( 'The membership level could not be saved.', 'swpm-rest-api' ),
				array( 'status' => 500 )
			);
		}

		$level_id = (int) $wpdb->insert_id;

		$this->save_level_settings( $level_id, $request, true );

		/**
		 * Fires after a membership level has been created through the REST API.
		 */
		do_action( 'swpm_rest_api_level_created', $level_id, $level );

		SwpmLog::log_simple_debug( 'SWPM REST API: membership level created (ID: ' . $level_id . ').', true );

		$created = SwpmUtils::get_membership_level_row_by_id( $level_id );
		$item    = $this->prepare_item_for_response( $created, $this->get_member_counts() );

		$response = $this->prepare_item_response( $item, $this->get_level_links( $item ) );
		$response->set_status( 201 );
		$response->header( 'Location', $this->get_item_url( $level_id ) );

		return $response;
	}

	/**
	 * PUT/PATCH /swpm/v1/levels/<id>
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		global $wpdb;

		$level_id = (int) $request['id'];

		if ( 1 === $level_id ) {
			return $this->not_found_error( __( 'No membership level was found for the given id.', 'swpm-rest-api' ) );
		}

		$existing = SwpmUtils::get_membership_level_row_by_id( $level_id );
		if ( empty( $existing ) ) {
			return $this->not_found_error( __( 'No membership level was found for the given id.', 'swpm-rest-api' ) );
		}

		$changes = $this->collect_write_fields( $request );

		if ( empty( $changes ) && ! $this->request_has_level_settings( $request ) ) {
			return new WP_Error(
				'swpm_rest_nothing_to_update',
				__( 'The request did not contain any updatable membership level fields.', 'swpm-rest-api' ),
				array( 'status' => 400 )
			);
		}

		//The duration type governs what a valid subscription_period looks like, so fall back to
		//the stored one when the request only changes the period.
		$duration_type = isset( $changes['subscription_duration_type'] )
			? (int) $changes['subscription_duration_type']
			: (int) $existing->subscription_duration_type;

		//The stored period belongs to the old type: a number left on a fixed date level, for
		//example, would break the expiry calculation.
		if ( $duration_type !== (int) $existing->subscription_duration_type
			&& SwpmMembershipLevel::NO_EXPIRY !== $duration_type
			&& ! isset( $changes['subscription_period'] ) ) {
			return $this->invalid_level_data_error(
				array(
					'subscription_period' => __( 'Changing the duration type also requires an access duration for the new type.', 'swpm-rest-api' ),
				)
			);
		}

		$error = $this->validate_level_data( $changes, $duration_type );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$changes = $this->normalize_subscription_period( $changes, $duration_type );

		if ( ! empty( $changes ) ) {
			$updated = $wpdb->update( $wpdb->prefix . 'swpm_membership_tbl', $changes, array( 'id' => $level_id ) );

			//update() returns 0 when the new values match the old ones, which is not a failure.
			if ( false === $updated ) {
				SwpmLog::log_simple_debug( 'SWPM REST API: failed to update membership level ' . $level_id . '. ' . $wpdb->last_error, false );
				return new WP_Error(
					'swpm_rest_update_failed',
					__( 'The membership level could not be updated.', 'swpm-rest-api' ),
					array( 'status' => 500 )
				);
			}
		}

		$this->save_level_settings( $level_id, $request );

		/**
		 * Fires after a membership level has been updated through the REST API.
		 */
		do_action( 'swpm_rest_api_level_updated', $level_id, $changes );

		SwpmLog::log_simple_debug( 'SWPM REST API: membership level updated (ID: ' . $level_id . ').', true );

		$level = SwpmUtils::get_membership_level_row_by_id( $level_id );
		$item  = $this->prepare_item_for_response( $level, $this->get_member_counts() );

		return $this->prepare_item_response( $item, $this->get_level_links( $item ) );
	}

	/**
	 * DELETE /swpm/v1/levels/<id>
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		global $wpdb;

		$level_id = (int) $request['id'];

		if ( 1 === $level_id ) {
			return $this->not_found_error( __( 'No membership level was found for the given id.', 'swpm-rest-api' ) );
		}

		$existing = SwpmUtils::get_membership_level_row_by_id( $level_id );
		if ( empty( $existing ) ) {
			return $this->not_found_error( __( 'No membership level was found for the given id.', 'swpm-rest-api' ) );
		}

		$member_counts = $this->get_member_counts();
		$member_count  = isset( $member_counts[ $level_id ] ) ? (int) $member_counts[ $level_id ] : 0;

		/*
		 * A member whose level no longer exists is a genuinely broken state: the plugin's
		 * expiry calculation treats it as a critical error and calls wp_die() on the front end.
		 * So members have to be moved off a level before it can go, unless the caller insists.
		 */
		if ( $member_count > 0 && empty( $request['force'] ) ) {
			return new WP_Error(
				'swpm_rest_level_in_use',
				sprintf(
					/* translators: %d: number of members assigned to the membership level. */
					__( 'This membership level still has %d member(s) assigned to it. Move those members to another level first, or repeat the request with force set to true.', 'swpm-rest-api' ),
					$member_count
				),
				array(
					'status'       => 409,
					'member_count' => $member_count,
				)
			);
		}

		$previous = $this->prepare_item_for_response( $existing, $member_counts );

		$deleted = $wpdb->delete( $wpdb->prefix . 'swpm_membership_tbl', array( 'id' => $level_id ) );

		if ( ! $deleted ) {
			SwpmLog::log_simple_debug( 'SWPM REST API: failed to delete membership level ' . $level_id . '. ' . $wpdb->last_error, false );
			return new WP_Error(
				'swpm_rest_delete_failed',
				__( 'The membership level could not be deleted.', 'swpm-rest-api' ),
				array( 'status' => 500 )
			);
		}

		$wpdb->delete( $wpdb->prefix . 'swpm_membership_meta_tbl', array( 'level_id' => $level_id ) );
		delete_option( 'swpm_email_activation_lvl_' . $level_id );

		/**
		 * Fires after a membership level has been deleted through the REST API.
		 */
		do_action( 'swpm_rest_api_level_deleted', $level_id );

		SwpmLog::log_simple_debug( 'SWPM REST API: membership level deleted (ID: ' . $level_id . ').', true );

		return $this->prepare_item_response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Maps the supplied request fields onto their membership level table columns.
	 *
	 * Only fields the caller actually sent are returned, so a partial update leaves everything
	 * else alone.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	protected function collect_write_fields( $request ) {
		//API field name => database column.
		$field_map = array(
			'name'                       => 'alias',
			'role'                       => 'role',
			'subscription_duration_type' => 'subscription_duration_type',
			'subscription_period'        => 'subscription_period',
			'loginredirect_page'         => 'loginredirect_page',
			'campaign_name'              => 'campaign_name',
			'protect_older_posts'        => 'protect_older_posts',
		);

		$supplied = $request->get_params();
		$level    = array();

		foreach ( $field_map as $field => $column ) {
			if ( ! array_key_exists( $field, $supplied ) ) {
				continue;
			}

			$value = $request[ $field ];

			if ( 'protect_older_posts' === $field ) {
				$value = empty( $value ) ? 0 : 1;
			}

			$level[ $column ] = $value;
		}

		return $level;
	}

	/**
	 * Checks the parts of a level payload a JSON schema cannot express.
	 *
	 * These rules mirror SwpmLevelForm, which cannot be reused here because it reads its input
	 * from $_POST rather than from the request object.
	 *
	 * @param array $level         The columns being written.
	 * @param int   $duration_type The duration type that applies after this write.
	 * @return true|WP_Error
	 */
	protected function validate_level_data( $level, $duration_type ) {
		$errors = array();

		if ( isset( $level['alias'] ) && '' === trim( $level['alias'] ) ) {
			$errors['name'] = __( 'The membership level name cannot be empty.', 'swpm-rest-api' );
		}

		if ( isset( $level['role'] ) && ! $this->is_valid_wp_role( $level['role'] ) ) {
			$errors['role'] = __( 'The given role is not a role that exists on this site.', 'swpm-rest-api' );
		}

		if ( isset( $level['subscription_period'] ) ) {
			$period = $level['subscription_period'];

			if ( in_array( $duration_type, array( SwpmMembershipLevel::FIXED_DATE, SwpmMembershipLevel::ANNUAL_FIXED_DATE ), true ) ) {
				$date = DateTime::createFromFormat( 'Y-m-d', $period );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $period ) {
					$errors['subscription_period'] = __( 'For the fixed date duration types the access duration must be a date in the YYYY-MM-DD format.', 'swpm-rest-api' );
				}
			} elseif ( SwpmMembershipLevel::NO_EXPIRY !== $duration_type ) {
				if ( ! is_numeric( $period ) || (float) $period <= 0 ) {
					$errors['subscription_period'] = __( 'The access duration must be a number greater than zero.', 'swpm-rest-api' );
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return $this->invalid_level_data_error( $errors );
		}

		return true;
	}

	/**
	 * @param array $errors Map of field name => message.
	 * @return WP_Error
	 */
	protected function invalid_level_data_error( $errors ) {
		return new WP_Error(
			'swpm_rest_invalid_level_data',
			__( 'One or more membership level fields were rejected. See the errors property for details.', 'swpm-rest-api' ),
			array(
				'status' => 400,
				'errors' => $errors,
			)
		);
	}

	/**
	 * Brings the stored access duration into the shape the rest of the plugin expects.
	 *
	 * Two cases, both copied from SwpmLevelForm:
	 *
	 * - A level that never expires stores an empty period, and the expiry calculation relies on
	 *   that.
	 * - An annual fixed date level stores the date with the year forced to the current year.
	 *   Only the month and day carry meaning: SwpmUtils::get_expiration_timestamp() re-anchors
	 *   the year to the one the member's subscription started in. Storing a caller supplied year
	 *   verbatim would leave a value the admin level screen renders differently from one created
	 *   through the dashboard.
	 *
	 * @param array $level
	 * @param int   $duration_type
	 * @return array
	 */
	protected function normalize_subscription_period( $level, $duration_type ) {
		$duration_type = (int) $duration_type;

		if ( SwpmMembershipLevel::NO_EXPIRY === $duration_type ) {
			$level['subscription_period'] = '';

			return $level;
		}

		if ( SwpmMembershipLevel::ANNUAL_FIXED_DATE === $duration_type && isset( $level['subscription_period'] ) ) {
			$date = DateTime::createFromFormat( 'Y-m-d', $level['subscription_period'] );

			if ( $date ) {
				$level['subscription_period'] = gmdate( 'Y' ) . '-' . $date->format( 'm-d' );
			}
		}

		return $level;
	}

	/**
	 * Does the request change a level setting stored outside the main table?
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	protected function request_has_level_settings( $request ) {
		$supplied = $request->get_params();

		foreach ( array_merge( array_keys( $this->custom_fields ), array( 'email_activation' ) ) as $field ) {
			if ( isset( $supplied[ $field ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Writes the level settings stored outside the main table.
	 *
	 * The admin screens write these from $_POST, so that code cannot be reused here, but
	 * SwpmMembershipLevelCustom::set() takes a plain array and can.
	 *
	 * @param int             $level_id
	 * @param WP_REST_Request $request
	 * @param bool            $is_new  Write every setting, as the admin screen does for a new level.
	 *                                 Otherwise only the settings the caller sent are touched.
	 */
	protected function save_level_settings( $level_id, $request, $is_new = false ) {
		global $wpdb;

		$supplied = $request->get_params();

		foreach ( $this->custom_fields as $meta_key => $field ) {
			if ( ! isset( $supplied[ $meta_key ] ) && ! $is_new ) {
				continue;
			}

			$value = isset( $supplied[ $meta_key ] ) ? $request[ $meta_key ] : '';

			if ( 'annual_fixed_date_min_period' === $meta_key ) {
				$value = empty( $value ) ? '' : absint( $value );
			}

			//The meta table has no unique key on level and meta_key, so set() adds another row
			//every time. Without this each API write would leave a duplicate behind.
			$wpdb->delete(
				$wpdb->prefix . 'swpm_membership_meta_tbl',
				array(
					'level_id' => $level_id,
					'meta_key' => $meta_key,
				)
			);

			SwpmMembershipLevelCustom::get_instance_by_id( $level_id )->set(
				array(
					'meta_key'     => $meta_key,
					'level_id'     => $level_id,
					'meta_label'   => $field['label'],
					'meta_value'   => $value,
					'meta_type'    => $field['type'],
					'meta_context' => $field['context'],
				)
			);
		}

		if ( isset( $supplied['email_activation'] ) || $is_new ) {
			update_option( 'swpm_email_activation_lvl_' . $level_id, empty( $request['email_activation'] ) ? '' : '1', false );
		}
	}

	/**
	 * Is the given string a role that exists on this site?
	 *
	 * @param string $role
	 * @return bool
	 */
	protected function is_valid_wp_role( $role ) {
		$roles = wp_roles()->get_names();

		return isset( $roles[ $role ] );
	}

	/**
	 * Shapes a membership level row into the API response.
	 *
	 * The content protection columns (category_list, page_list, post_list and friends) are large
	 * serialized blobs that are only meaningful to the plugin itself, so they are left out.
	 *
	 * @param object $level         Row from the swpm_membership_tbl table.
	 * @param array  $member_counts Map of level id => number of members.
	 * @return array
	 */
	protected function prepare_item_for_response( $level, $member_counts = array() ) {
		$level_id = (int) $level->id;

		$data = array(
			'id'                              => $level_id,
			'name'                            => $level->alias,
			'role'                            => $level->role,
			'subscription_period'             => $level->subscription_period,
			'subscription_duration_type'      => (int) $level->subscription_duration_type,
			'subscription_duration_type_name' => $this->get_duration_type_name( $level->subscription_duration_type ),
			'annual_fixed_date_min_period'    => $this->get_annual_fixed_date_min_period( $level_id ),
			'default_account_status'          => $this->get_custom_field_value( $level_id, 'default_account_status' ),
			'email_activation'                => ! empty( get_option( 'swpm_email_activation_lvl_' . $level_id ) ),
			'after_activation_redirect_page'  => $this->get_custom_field_value( $level_id, 'after_activation_redirect_page' ),
			'subscription_unit'               => $level->subscription_unit,
			'loginredirect_page'              => $level->loginredirect_page,
			'protect_older_posts'             => (bool) $level->protect_older_posts,
			'campaign_name'                   => $level->campaign_name,
			'member_count'                    => isset( $member_counts[ $level_id ] ) ? (int) $member_counts[ $level_id ] : 0,
		);

		/**
		 * Lets other addons add fields to (or remove fields from) a membership level API response.
		 */
		return apply_filters( 'swpm_rest_api_prepare_level', $data, $level );
	}

	/**
	 * Reads the annual fixed date minimum period from the level's meta table rows.
	 *
	 * @param int $level_id
	 * @return int|null Null when the level has no value stored.
	 */
	protected function get_annual_fixed_date_min_period( $level_id ) {
		$value = $this->get_custom_field_value( $level_id, 'annual_fixed_date_min_period' );

		return is_null( $value ) ? null : (int) $value;
	}

	/**
	 * Reads a setting from the level's meta table rows.
	 *
	 * @param int    $level_id
	 * @param string $meta_key
	 * @return string|null Null when the level has no value stored.
	 */
	protected function get_custom_field_value( $level_id, $meta_key ) {
		$value = SwpmMembershipLevelCustom::get_value_by_key( $level_id, $meta_key, '' );

		return ( '' === $value || is_null( $value ) ) ? null : $value;
	}

	/**
	 * Maps a subscription_duration_type value onto a name a human (or an AI agent) can read.
	 *
	 * @param int $duration_type
	 * @return string
	 */
	protected function get_duration_type_name( $duration_type ) {
		$names = array(
			SwpmMembershipLevel::NO_EXPIRY         => 'no_expiry',
			SwpmMembershipLevel::DAYS              => 'days',
			SwpmMembershipLevel::WEEKS             => 'weeks',
			SwpmMembershipLevel::MONTHS            => 'months',
			SwpmMembershipLevel::YEARS             => 'years',
			SwpmMembershipLevel::FIXED_DATE        => 'fixed_date',
			SwpmMembershipLevel::ANNUAL_FIXED_DATE => 'annual_fixed_date',
		);

		$duration_type = (int) $duration_type;

		return isset( $names[ $duration_type ] ) ? $names[ $duration_type ] : 'unknown';
	}

	/**
	 * Counts the members in each membership level using a single grouped query.
	 *
	 * @return array Map of level id => member count.
	 */
	protected function get_member_counts() {
		global $wpdb;

		$members_table = $wpdb->prefix . 'swpm_members_tbl';
		$results       = $wpdb->get_results( "SELECT membership_level, COUNT(*) AS total FROM {$members_table} GROUP BY membership_level" );

		$counts = array();
		foreach ( $results as $result ) {
			$counts[ (int) $result->membership_level ] = (int) $result->total;
		}

		return $counts;
	}
}
