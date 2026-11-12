<?php

/**
 * Renders and saves this addon's section on the main plugin's "Addon Settings" tab.
 *
 * Note: the main plugin already runs a capability check and check_admin_referer() before firing
 * the 'swpm_addon_settings_save' action, so settings_save() does not repeat those here.
 */
class SWPM_REST_API_Settings {

	public function __construct() {
		//These hooks are fired from the main plugin's settings interface, so the main plugin's
		//classes and methods can be expected to be available by the time they run.
		add_action( 'swpm_addon_settings_section', array( $this, 'settings_ui' ) );
		add_action( 'swpm_addon_settings_save', array( $this, 'settings_save' ) );
	}

	/**
	 * Renders the settings fields of this addon.
	 */
	public function settings_ui() {
		$settings = SwpmSettings::get_instance();

		$enable_api   = ! empty( $settings->get_value( 'swpm-rest-api-enable' ) );
		$enable_write = ! empty( $settings->get_value( 'swpm-rest-api-enable-write' ) );
		$api_key      = $settings->get_value( 'swpm-rest-api-key' );

		if ( empty( $api_key ) ) {
			//No key yet. Generate one so the admin has something to copy straight away.
			$api_key = self::generate_api_key();
			$settings->set_value( 'swpm-rest-api-key', $api_key );
			$settings->save();
		}

		$api_base_url = esc_url_raw( rest_url( SWPM_REST_API_NAMESPACE ) );

		$rate_limit_read        = SWPM_REST_API_Rate_Limiter::get_limit( SWPM_REST_API_Rate_Limiter::BUCKET_READ );
		$rate_limit_write       = SWPM_REST_API_Rate_Limiter::get_limit( SWPM_REST_API_Rate_Limiter::BUCKET_WRITE );
		$rate_limit_failed_auth = SWPM_REST_API_Rate_Limiter::get_limit( SWPM_REST_API_Rate_Limiter::BUCKET_FAILED_AUTH );

		//Render the settings view.
		require SWPM_REST_API_ADDON_PATH . 'views/settings.php';
	}

	/**
	 * Saves the settings fields of this addon.
	 */
	public function settings_save() {
		$settings = SwpmSettings::get_instance();

		$enable_api   = filter_input( INPUT_POST, 'swpm-rest-api-enable' );
		$enable_write = filter_input( INPUT_POST, 'swpm-rest-api-enable-write' );
		$regenerate   = filter_input( INPUT_POST, 'swpm-rest-api-regenerate-key' );

		$settings->set_value( 'swpm-rest-api-enable', empty( $enable_api ) ? '' : '1' );
		$settings->set_value( 'swpm-rest-api-enable-write', empty( $enable_write ) ? '' : '1' );

		foreach ( SWPM_REST_API_Rate_Limiter::DEFAULT_LIMITS as $bucket => $default_limit ) {
			$limit = trim( (string) filter_input( INPUT_POST, 'swpm-rest-api-rate-limit-' . $bucket ) );

			//A blank box restores the default. Zero is a real value meaning no limit.
			$settings->set_value( 'swpm-rest-api-rate-limit-' . $bucket, '' === $limit ? $default_limit : absint( $limit ) );
		}

		if ( ! empty( $regenerate ) ) {
			//Any application using the old key stops working at this point, which is the intent.
			$settings->set_value( 'swpm-rest-api-key', self::generate_api_key() );
			SwpmLog::log_simple_debug( 'SWPM REST API: the API key was regenerated from the settings menu.', true );
		}

		$settings->save();

		$message = array(
			'succeeded' => true,
			'message'   => '<p>' . SwpmUtils::_( 'Settings updated!' ) . '</p>',
		);
		SwpmTransfer::get_instance()->set( 'status', $message );
	}

	/**
	 * Creates a new random API key.
	 *
	 * @return string
	 */
	public static function generate_api_key() {
		//wp_generate_password() uses a cryptographically secure source when one is available.
		return wp_generate_password( 64, false, false );
	}
}
