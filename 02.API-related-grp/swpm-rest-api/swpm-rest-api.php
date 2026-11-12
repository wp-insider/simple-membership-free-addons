<?php
/*
  Plugin Name: Simple Membership REST API
  Version: 1.0
  Plugin URI: https://simple-membership-plugin.com/
  Author: wp.insider
  Author URI: https://simple-membership-plugin.com/
  Description: Adds a WordPress REST API (swpm/v1 namespace) to the Simple Membership plugin so external applications, automation tools and AI agents can work with your membership data.
  Requires PHP: 7.4
 */

//Slug - swpm_rest_

//Direct access to this file is not permitted
if ( ! defined( 'ABSPATH' ) ) {
	exit; //Exit if accessed directly
}

define( 'SWPM_REST_API_ADDON_VER', '1.0' );
define( 'SWPM_REST_API_ADDON_PATH', dirname( __FILE__ ) . '/' );
define( 'SWPM_REST_API_ADDON_URL', plugins_url( '', __FILE__ ) );

//The REST API namespace shared by every route of this addon.
define( 'SWPM_REST_API_NAMESPACE', 'swpm/v1' );

//Load the settings handler. It only hooks into the main plugin's addon settings tab, so it is safe to load this early.
require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-rate-limiter.php';
require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-settings.php';
new SWPM_REST_API_Settings();

/**
 * Registers all the routes of this addon.
 *
 * This runs on 'rest_api_init' (rather than 'init' like the older API addon) so the routes go
 * through WordPress' own REST infrastructure. The main plugin and its utility classes have
 * been loaded by this point.
 */
function swpm_rest_api_register_routes() {
	//The main plugin must be active. Without it there is nothing to expose. SwpmRegistration is
	//checked too because the registration notifier extends it.
	if ( ! class_exists( 'SimpleWpMembership' ) || ! class_exists( 'SwpmRegistration' ) ) {
		return;
	}

	require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-auth.php';
	require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-route.php';
	require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-router.php';
	require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-controller-base.php';
	require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-member-validator.php';
	require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-registration-notifier.php';
	require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-members-controller.php';
	require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-levels-controller.php';
	require_once SWPM_REST_API_ADDON_PATH . 'classes/class.swpm-rest-api-transactions-controller.php';

	$router = new SWPM_REST_API_Router( SWPM_REST_API_NAMESPACE );
	$router->register_middleware( 'auth', array( 'SWPM_REST_API_Auth', 'check_permission' ) );
	$router->register_middleware( 'throttle', array( 'SWPM_REST_API_Rate_Limiter', 'handle' ) );

	require SWPM_REST_API_ADDON_PATH . 'routes/api.php';

	/**
	 * Lets other addons add routes and middleware to the swpm/v1 namespace.
	 */
	do_action( 'swpm_rest_api_register_routes', $router );

	$router->register();
}
add_action( 'rest_api_init', 'swpm_rest_api_register_routes' );

//Adds the rate limit headers to API responses. A permission callback cannot reach the response.
add_filter( 'rest_post_dispatch', array( 'SWPM_REST_API_Rate_Limiter', 'add_response_headers' ) );

//Add settings link in the plugins listing page
function swpm_rest_api_add_settings_link( $links, $file ) {
	if ( $file == plugin_basename( __FILE__ ) ) {
		$settings_link = '<a href="admin.php?page=simple_wp_membership_settings&tab=7">Settings</a>';
		array_unshift( $links, $settings_link );
	}
	return $links;
}
add_filter( 'plugin_action_links', 'swpm_rest_api_add_settings_link', 10, 2 );
