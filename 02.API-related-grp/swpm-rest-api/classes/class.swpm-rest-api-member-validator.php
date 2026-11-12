<?php

/**
 * Validates and sanitizes member data coming in through the write endpoints.
 *
 * Why this is not built on SwpmForm:
 *
 * SwpmForm reads its input straight out of $_POST and filter_input(INPUT_POST, ...). A REST
 * request usually carries a JSON body, which leaves $_POST empty, and filter_input() reads the
 * raw request data PHP parsed at startup, so populating $_POST by hand would not reach the
 * fields SwpmForm pulls with filter_input() (email, password, phone, member_since and
 * membership_level among them). That would half-work, which is worse than not working.
 *
 * The rules below therefore mirror SwpmForm's, but read from the WP_REST_Request and report
 * problems as a WP_Error instead of leaving them on a form object.
 *
 * The plugin's own pre-flight guards (SwpmMemberUtils::check_and_die_if_email_belongs_to_admin_user()
 * and friends) call wp_die(), which would tear down the REST response mid-flight. The equivalent
 * checks are reimplemented here so those code paths are never reached.
 */
class SWPM_REST_API_Member_Validator {

	/**
	 * Account states accepted on write. This matches the account_state column's enum.
	 *
	 * Note that SwpmForm leaves 'unsubscribed' out of its own list, but the plugin does set that
	 * state elsewhere, so it is accepted here.
	 *
	 * @var array
	 */
	const ACCOUNT_STATES = array( 'active', 'inactive', 'activation_required', 'expired', 'pending', 'unsubscribed' );

	/**
	 * The member table columns that may be written through the API.
	 *
	 * Sanitization is not listed here because it is already declared per field in
	 * get_route_args(); WordPress applies those callbacks before the route callback runs.
	 *
	 * 'user_name' is deliberately absent: it is set once at creation and is not editable
	 * afterwards, which is how the admin dashboard and the older API add-on both behave.
	 *
	 * @return array
	 */
	public static function get_writable_fields() {
		return array(
			'email',
			'first_name',
			'last_name',
			'membership_level',
			'account_state',
			'phone',
			'address_street',
			'address_city',
			'address_state',
			'address_zipcode',
			'country',
			'home_page',
			'gender',
			'company_name',
			'member_since',
			'subscription_starts',
			'notes',
			'referrer',
			'subscr_id',
			'txn_id',
		);
	}

	/**
	 * The route argument schema for the member write endpoints.
	 *
	 * WordPress handles type, enum and format checking from this schema before the callback runs,
	 * so the validation below only has to deal with the things a schema cannot express
	 * (uniqueness, WordPress user conflicts, whether a membership level really exists).
	 *
	 * @param bool $for_create True for the create route, where some fields become required.
	 * @return array
	 */
	public static function get_route_args( $for_create = false ) {
		$args = array(
			'email'               => array(
				'description'       => __( 'Email address of the member.', 'swpm-rest-api' ),
				'type'              => 'string',
				'format'            => 'email',
				'required'          => $for_create,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'password'            => array(
				'description' => __( 'Plain text password for the member. It is hashed before it is stored. When the plugin\'s Force Strong Password setting is on, it must meet that rule.', 'swpm-rest-api' ),
				'type'        => 'string',
				'required'    => $for_create,
				//No sanitize_callback: a password must reach the hashing step unaltered.
				'validate_callback' => 'rest_validate_request_arg',
			),
			'membership_level'    => array(
				'description'       => __( 'Id of the membership level to assign to the member.', 'swpm-rest-api' ),
				'type'              => 'integer',
				'required'          => $for_create,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'first_name'          => array(
				'description'       => __( 'First name of the member.', 'swpm-rest-api' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'last_name'           => array(
				'description'       => __( 'Last name of the member.', 'swpm-rest-api' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'account_state'       => array(
				'description'       => __( 'Account status of the member.', 'swpm-rest-api' ),
				'type'              => 'string',
				'enum'              => self::ACCOUNT_STATES,
				'validate_callback' => 'rest_validate_request_arg',
			),
			'gender'              => array(
				'description'       => __( 'Gender recorded on the member profile.', 'swpm-rest-api' ),
				'type'              => 'string',
				'enum'              => array( 'male', 'female', 'not specified' ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'member_since'        => array(
				'description'       => __( 'Date the member joined, in YYYY-MM-DD format.', 'swpm-rest-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( __CLASS__, 'validate_date_arg' ),
			),
			'subscription_starts' => array(
				'description'       => __( 'Date the access period starts, in YYYY-MM-DD format. Membership expiry is calculated from this date.', 'swpm-rest-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( __CLASS__, 'validate_date_arg' ),
			),
			'home_page'           => array(
				'description'       => __( 'Home page URL recorded on the member profile.', 'swpm-rest-api' ),
				'type'              => 'string',
				'format'            => 'uri',
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'notes'               => array(
				'description'       => __( 'Admin notes about the member.', 'swpm-rest-api' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);

		//The remaining fields are plain text with no extra rules attached.
		$plain_text_fields = array(
			'phone'           => __( 'Phone number of the member.', 'swpm-rest-api' ),
			'address_street'  => __( 'Street address of the member.', 'swpm-rest-api' ),
			'address_city'    => __( 'City of the member.', 'swpm-rest-api' ),
			'address_state'   => __( 'State or region of the member.', 'swpm-rest-api' ),
			'address_zipcode' => __( 'Postcode of the member.', 'swpm-rest-api' ),
			'country'         => __( 'Country of the member, as a name from the plugin\'s country list or a two letter country code. Stored as the name.', 'swpm-rest-api' ),
			'company_name'    => __( 'Company name of the member.', 'swpm-rest-api' ),
			'referrer'        => __( 'Referrer recorded on the member profile.', 'swpm-rest-api' ),
			'subscr_id'       => __( 'Subscription id from the payment gateway.', 'swpm-rest-api' ),
			'txn_id'          => __( 'Transaction id from the payment gateway.', 'swpm-rest-api' ),
		);

		foreach ( $plain_text_fields as $field => $description ) {
			$args[ $field ] = array(
				'description'       => $description,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			);
		}

		if ( $for_create ) {
			$args['user_name'] = array(
				'description'       => __( 'Username for the member. It cannot be changed after the profile is created.', 'swpm-rest-api' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			);

			$args['send_notification'] = array(
				'description' => __( 'Whether to email the member the registration notification. By default a member who must activate their account is emailed; otherwise the plugin\'s "notification after manual user add" setting decides.', 'swpm-rest-api' ),
				'type'        => 'boolean',
			);
		}

		return $args;
	}

	/**
	 * Validates a YYYY-MM-DD date argument.
	 *
	 * @param string $value
	 * @return true|WP_Error
	 */
	public static function validate_date_arg( $value ) {
		$date = DateTime::createFromFormat( 'Y-m-d', $value );

		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return new WP_Error(
				'swpm_rest_invalid_date',
				__( 'The date must be a valid date in the YYYY-MM-DD format.', 'swpm-rest-api' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validates a create request and returns the row that should be inserted.
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error Array with 'member' (DB row) and 'plain_password' keys, or a WP_Error.
	 */
	public static function validate_for_create( $request ) {
		$errors = array();

		$user_name = $request['user_name'];
		$email     = $request['email'];
		$password  = $request['password'];
		$level_id  = (int) $request['membership_level'];

		$user_name_error = self::get_user_name_error( $user_name );
		if ( $user_name_error ) {
			$errors['user_name'] = $user_name_error;
		}

		$email_error = self::get_email_error( $email );
		if ( $email_error ) {
			$errors['email'] = $email_error;
		}

		$level_error = self::get_membership_level_error( $level_id );
		if ( $level_error ) {
			$errors['membership_level'] = $level_error;
		}

		$password_error = self::get_password_error( $password, 'swpm_reg_pass' );
		if ( $password_error ) {
			$errors['password'] = $password_error;
		}

		$country = self::resolve_country( $request['country'] );
		if ( '' !== $country['error'] ) {
			$errors['country'] = $country['error'];
		}

		//Only worth checking for a WordPress user clash once the username and email are themselves sound.
		if ( ! isset( $errors['user_name'] ) && ! isset( $errors['email'] ) ) {
			$wp_conflict = self::get_wp_user_conflict_error( $email, $user_name );
			if ( $wp_conflict ) {
				$errors[ $wp_conflict['field'] ] = $wp_conflict['message'];
			}
		}

		if ( ! empty( $errors ) ) {
			return self::invalid_data_error( $errors );
		}

		$member = self::collect_supplied_fields( $request );

		//Store the canonical country name rather than whatever spelling or code came in.
		if ( isset( $member['country'] ) ) {
			$member['country'] = $country['value'];
		}

		$member['user_name'] = $user_name;
		$member['password']  = self::hash_password( $password );

		if ( empty( $member['account_state'] ) ) {
			$member['account_state'] = self::get_default_account_state( $level_id );
		}

		$today = SwpmUtils::get_current_date_in_wp_zone();

		if ( empty( $member['member_since'] ) ) {
			$member['member_since'] = $today;
		}

		if ( empty( $member['subscription_starts'] ) ) {
			//Membership expiry is calculated from this date, so it must not be left empty.
			$member['subscription_starts'] = $today;
		}

		//Used by the plugin's "complete your registration" link.
		$member['reg_code'] = md5( uniqid() );

		$member['initial_membership_level'] = $member['membership_level'];

		return array(
			'member'         => $member,
			'plain_password' => $password,
		);
	}

	/**
	 * Validates an update request and returns only the columns that should change.
	 *
	 * @param WP_REST_Request $request
	 * @param object          $existing_member The current row from swpm_members_tbl.
	 * @return array|WP_Error Array with 'member' (changed columns) and 'plain_password' keys, or a WP_Error.
	 */
	public static function validate_for_update( $request, $existing_member ) {
		$errors    = array();
		$member_id = (int) $existing_member->member_id;

		if ( null !== $request['email'] ) {
			$email_error = self::get_email_error( $request['email'], $member_id );
			if ( $email_error ) {
				$errors['email'] = $email_error;
			}
		}

		if ( null !== $request['membership_level'] ) {
			$level_error = self::get_membership_level_error( (int) $request['membership_level'] );
			if ( $level_error ) {
				$errors['membership_level'] = $level_error;
			}
		}

		//An empty password means "leave it unchanged", the same as on the profile form.
		$plain_password = (string) $request['password'];
		if ( '' !== trim( $plain_password ) ) {
			$password_error = self::get_password_error( $plain_password, 'swpm_profile_pass' );
			if ( $password_error ) {
				$errors['password'] = $password_error;
			}
		} else {
			$plain_password = '';
		}

		$country = self::resolve_country( $request['country'], (string) $existing_member->country );
		if ( '' !== $country['error'] ) {
			$errors['country'] = $country['error'];
		}

		if ( ! empty( $errors ) ) {
			return self::invalid_data_error( $errors );
		}

		$member = self::collect_supplied_fields( $request );

		//Store the canonical country name rather than whatever spelling or code came in.
		if ( isset( $member['country'] ) ) {
			$member['country'] = $country['value'];
		}

		if ( '' !== $plain_password ) {
			$member['password'] = self::hash_password( $plain_password );
		}

		if ( empty( $member ) ) {
			return new WP_Error(
				'swpm_rest_nothing_to_update',
				__( 'The request did not contain any updatable member fields.', 'swpm-rest-api' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'member'         => $member,
			'plain_password' => $plain_password,
		);
	}

	/**
	 * Pulls the writable fields the caller actually supplied out of the request.
	 *
	 * Only fields present in the request body are returned, so a partial update never blanks out
	 * columns the caller did not mention.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	protected static function collect_supplied_fields( $request ) {
		$supplied = $request->get_params();
		$fields   = array();

		foreach ( self::get_writable_fields() as $field ) {
			if ( ! array_key_exists( $field, $supplied ) ) {
				continue;
			}

			$value = $request[ $field ];

			//An explicit null would write NULL over the column. Clearing a field is done by
			//sending an empty string, so a null is treated as "not supplied".
			if ( is_null( $value ) ) {
				continue;
			}

			//The route schema has already sanitized these, so the value is taken as-is.
			$fields[ $field ] = $value;
		}

		return $fields;
	}

	/**
	 * Hashes a password the same way the plugin's own registration form does.
	 *
	 * SwpmForm runs the password through sanitize_text_field() and trim() before hashing it,
	 * while handing the raw value to WordPress for the linked WP user. That asymmetry is the
	 * plugin's, not this add-on's, but it is reproduced here so a member created through the API
	 * can log in exactly like one created through the registration form.
	 *
	 * @param string $plain_password
	 * @return string
	 */
	protected static function hash_password( $plain_password ) {
		return SwpmUtils::encrypt_password( trim( sanitize_text_field( $plain_password ) ) );
	}

	/**
	 * Applies the same password rule as the plugin's forms.
	 *
	 * Any non-empty password is accepted unless the Force Strong Password setting is on. The forms
	 * only enforce that setting in JavaScript, which an API client never runs, so it is enforced
	 * here using the same defaults and filters.
	 *
	 * @param string $password
	 * @param string $filter_prefix 'swpm_reg_pass' for new members, 'swpm_profile_pass' for updates.
	 * @return string Empty string when the password is acceptable, otherwise the problem.
	 */
	protected static function get_password_error( $password, $filter_prefix ) {
		if ( '' === trim( sanitize_text_field( (string) $password ) ) ) {
			return __( 'Password is required.', 'swpm-rest-api' );
		}

		if ( empty( SwpmSettings::get_instance()->get_value( 'force-strong-passwords' ) ) ) {
			return '';
		}

		$min_length = apply_filters( $filter_prefix . '_min_length_validation', null );
		$min_length = is_numeric( $min_length ) && (int) $min_length > 0 ? (int) $min_length : 8;

		if ( mb_strlen( $password ) < $min_length ) {
			/* translators: %d: minimum number of characters in a password. */
			return sprintf( __( 'Password must be at least %d characters long.', 'swpm-rest-api' ), $min_length );
		}

		$pattern = self::get_password_pattern( apply_filters( $filter_prefix . '_pattern_validation', '' ) );

		//preg_match() returns false for a pattern PHP cannot compile, which is not the password's fault.
		if ( 0 === @preg_match( $pattern, $password ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return __( 'Password does not meet the strength requirements of this site.', 'swpm-rest-api' );
		}

		return '';
	}

	/**
	 * Converts the forms' JavaScript password pattern into a PHP regular expression.
	 *
	 * The filter value is printed into a script as a JavaScript expression, so it is normally a
	 * /regex/ literal or a quoted string.
	 *
	 * @param string $custom_pattern The filter value. Empty for the default rule.
	 * @return string
	 */
	protected static function get_password_pattern( $custom_pattern ) {
		$pattern = trim( (string) $custom_pattern );

		if ( '' === $pattern ) {
			return '~^(?=.*\d)(?=.*[A-Z])(?=.*[a-z]).+$~';
		}

		$flags = '';

		if ( preg_match( '~^/(.+)/([a-z]*)$~s', $pattern, $parts ) ) {
			$pattern = $parts[1];
			$flags   = array_intersect( str_split( $parts[2] ), array( 'i', 'm', 's', 'u' ) );
			$flags   = implode( '', $flags );
		} else {
			$pattern = trim( $pattern, '"\'' );
		}

		return '~' . str_replace( '~', '\~', $pattern ) . '~' . $flags;
	}

	/**
	 * Works out the status of a new member when the request does not set one.
	 *
	 * This is the order the plugin's front end registration uses: email activation on the level,
	 * then the level's own default status, then the global setting.
	 *
	 * @param int $level_id
	 * @return string
	 */
	protected static function get_default_account_state( $level_id ) {
		if ( get_option( 'swpm_email_activation_lvl_' . $level_id ) ) {
			return 'activation_required';
		}

		$level_default = sanitize_text_field( SwpmMembershipLevelCustom::get_value_by_key( $level_id, 'default_account_status', '' ) );

		if ( ! empty( $level_default ) ) {
			return $level_default;
		}

		return SwpmSettings::get_instance()->get_value( 'default-account-status', 'active' );
	}

	/**
	 * Checks a username for the create route.
	 *
	 * @param string $user_name
	 * @return string Empty string when the username is usable, otherwise the problem.
	 */
	protected static function get_user_name_error( $user_name ) {
		global $wpdb;

		if ( empty( $user_name ) ) {
			return __( 'Username is required.', 'swpm-rest-api' );
		}

		if ( ! SwpmMemberUtils::is_valid_user_name( $user_name ) ) {
			return __( 'Username contains an invalid character.', 'swpm-rest-api' );
		}

		//SwpmUtils::create_wp_user() calls wp_die() when wp_create_user() rejects a username, which
		//would tear down the REST response. WordPress' own rules are checked here first so that
		//never happens. They are stricter than is_valid_user_name() in places: '*' passes the
		//plugin's pattern but is stripped by sanitize_user(), and reserved logins are refused.
		if ( ! validate_username( $user_name ) ) {
			return __( 'Username is not allowed by WordPress.', 'swpm-rest-api' );
		}

		$members_table = $wpdb->prefix . 'swpm_members_tbl';
		$count         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(member_id) FROM {$members_table} WHERE user_name = %s", $user_name ) );

		if ( $count > 0 ) {
			return __( 'Username already exists.', 'swpm-rest-api' );
		}

		return '';
	}

	/**
	 * Checks an email address for uniqueness among member profiles.
	 *
	 * @param string $email
	 * @param int    $ignore_member_id Member id to exclude, so an update can keep its own address.
	 * @return string Empty string when the address is usable, otherwise the problem.
	 */
	protected static function get_email_error( $email, $ignore_member_id = 0 ) {
		global $wpdb;

		if ( empty( $email ) ) {
			return __( 'Email is required.', 'swpm-rest-api' );
		}

		if ( ! is_email( $email ) ) {
			return __( 'Email is invalid.', 'swpm-rest-api' );
		}

		$members_table = $wpdb->prefix . 'swpm_members_tbl';

		if ( $ignore_member_id > 0 ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(member_id) FROM {$members_table} WHERE email = %s AND member_id != %d", $email, $ignore_member_id ) );
		} else {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(member_id) FROM {$members_table} WHERE email = %s", $email ) );
		}

		if ( $count > 0 ) {
			return __( 'Email is already used by another member.', 'swpm-rest-api' );
		}

		return '';
	}

	/**
	 * Checks that a membership level can be assigned.
	 *
	 * @param int $level_id
	 * @return string Empty string when the level is usable, otherwise the problem.
	 */
	protected static function get_membership_level_error( $level_id ) {
		if ( empty( $level_id ) ) {
			return __( 'A membership level is required.', 'swpm-rest-api' );
		}

		if ( 1 === (int) $level_id ) {
			//Level 1 is the internal "Content Protection" record, not an assignable level.
			return __( 'Invalid membership level.', 'swpm-rest-api' );
		}

		if ( ! SwpmMembershipLevelUtils::check_if_membership_level_exists( $level_id ) ) {
			return __( 'The given membership level does not exist.', 'swpm-rest-api' );
		}

		return '';
	}

	/**
	 * Checks a country value against the plugin's country list and returns it in canonical form.
	 *
	 * A two letter country code is accepted as well and resolved to the matching name, since that
	 * is what most external systems send. The stored value is always the country name, which is
	 * what the registration form's dropdown writes.
	 *
	 * Sites holding imported or legacy country values that are not on the list can turn the check
	 * off with the 'swpm_rest_api_validate_country' filter.
	 *
	 * @param string $country
	 * @param string $current_value The member's stored country, on an update.
	 * @return array With 'value' (the value to store) and 'error' (empty when acceptable) keys.
	 */
	protected static function resolve_country( $country, $current_value = '' ) {
		$country = (string) $country;

		//An empty value clears the field, which is always allowed.
		if ( '' === trim( $country ) ) {
			return array(
				'value' => '',
				'error' => '',
			);
		}

		//Older profiles can hold a spelling that is not on the list. Sending a profile back
		//unchanged must not fail because of that.
		if ( $country === $current_value ) {
			return array(
				'value' => $country,
				'error' => '',
			);
		}

		if ( ! apply_filters( 'swpm_rest_api_validate_country', true, $country ) ) {
			return array(
				'value' => $country,
				'error' => '',
			);
		}

		//get_countries_list() was added to the main plugin alongside this addon. On an older
		//version the list is not reachable, so accept the value rather than reject every country.
		if ( ! method_exists( 'SwpmMiscUtils', 'get_countries_list' ) ) {
			return array(
				'value' => $country,
				'error' => '',
			);
		}

		//The list is keyed by country code.
		$countries = SwpmMiscUtils::get_countries_list();
		$code      = strtoupper( trim( $country ) );

		if ( isset( $countries[ $code ] ) ) {
			return array(
				'value' => $countries[ $code ],
				'error' => '',
			);
		}

		foreach ( $countries as $name ) {
			if ( strtolower( $name ) === strtolower( trim( $country ) ) ) {
				return array(
					'value' => $name,
					'error' => '',
				);
			}
		}

		return array(
			'value' => $country,
			'error' => __( 'The country was not recognised. Use a country name from the plugin\'s country list, or a two letter country code.', 'swpm-rest-api' ),
		);
	}

	/**
	 * Checks whether a new member profile would collide with an existing WordPress user.
	 *
	 * This is the REST equivalent of SwpmMemberUtils::check_and_die_if_email_belongs_to_admin_user(),
	 * check_and_die_if_username_belongs_to_admin_user() and check_and_die_if_existing_wp_user_exists().
	 * Those all call wp_die(), and SwpmUtils::create_wp_user() calls the first two internally, so
	 * these checks have to pass before the member is created.
	 *
	 * @param string $email
	 * @param string $user_name
	 * @return array|null Array with 'field' and 'message' keys when there is a conflict.
	 */
	protected static function get_wp_user_conflict_error( $email, $user_name ) {
		//An administrator's credentials can never be reused for a member profile.
		$wp_user_id = email_exists( $email );
		if ( $wp_user_id && SwpmMemberUtils::wp_user_has_admin_role( $wp_user_id ) ) {
			return array(
				'field'   => 'email',
				'message' => __( 'This email address belongs to an administrator account and cannot be used for a member profile.', 'swpm-rest-api' ),
			);
		}

		$wp_user_id = username_exists( $user_name );
		if ( $wp_user_id && SwpmMemberUtils::wp_user_has_admin_role( $wp_user_id ) ) {
			return array(
				'field'   => 'user_name',
				'message' => __( 'This username belongs to an administrator account and cannot be used for a member profile.', 'swpm-rest-api' ),
			);
		}

		//Linking a new member profile to a pre-existing WordPress user is refused unless the site
		//owner has explicitly allowed it through the plugin's own filter.
		$found_wp_user = SwpmMemberUtils::find_wp_user_by_email_or_username(
			array(
				'email'     => $email,
				'user_name' => $user_name,
			)
		);

		if ( empty( $found_wp_user ) ) {
			return null;
		}

		$allow_binding = apply_filters(
			'swpm_allow_existing_wp_user_registration',
			false,
			$found_wp_user,
			array(
				'email'     => $email,
				'user_name' => $user_name,
			)
		);

		if ( $allow_binding ) {
			return null;
		}

		if ( 'email' === $found_wp_user['identified_by'] ) {
			return array(
				'field'   => 'email',
				'message' => __( 'This email address is already associated with an existing WordPress user account on this site.', 'swpm-rest-api' ),
			);
		}

		return array(
			'field'   => 'user_name',
			'message' => __( 'This username is already associated with an existing WordPress user account on this site.', 'swpm-rest-api' ),
		);
	}

	/**
	 * Builds the WP_Error returned for field level validation problems.
	 *
	 * @param array $errors Map of field name => message.
	 * @return WP_Error
	 */
	protected static function invalid_data_error( $errors ) {
		return new WP_Error(
			'swpm_rest_invalid_member_data',
			__( 'One or more member fields were rejected. See the errors property for details.', 'swpm-rest-api' ),
			array(
				'status' => 400,
				'errors' => $errors,
			)
		);
	}
}
