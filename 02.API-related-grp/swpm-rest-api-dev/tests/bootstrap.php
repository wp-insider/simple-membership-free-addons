<?php
/**
 * PHPUnit bootstrap for the REST API add-on.
 *
 * Loads the WordPress test library, then the main Simple Membership plugin and the add-on.
 *
 * Environment variables:
 *   WP_TESTS_DIR     Where install-wp-tests.sh put the WordPress test library.
 *                    Defaults to <system temp>/wordpress-tests-lib.
 *   SWPM_PLUGIN_DIR  The main plugin folder (the one holding simple-wp-membership.php).
 *                    Defaults to the simple-membership repo checked out beside the
 *                    simple-membership-free-addons repo.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php. Run install-wp-tests.sh first, or set WP_TESTS_DIR." . PHP_EOL;
	exit( 1 );
}

$_core_plugin_dir = getenv( 'SWPM_PLUGIN_DIR' );
if ( ! $_core_plugin_dir ) {
	$_core_plugin_dir = dirname( __DIR__, 4 ) . '/simple-membership/simple-membership';
}

if ( ! file_exists( $_core_plugin_dir . '/simple-wp-membership.php' ) ) {
	echo "Could not find the main plugin at $_core_plugin_dir. Set SWPM_PLUGIN_DIR." . PHP_EOL;
	exit( 1 );
}

define( 'SWPM_REST_API_TEST_CORE_DIR', $_core_plugin_dir );
define( 'SWPM_REST_API_TEST_ADDON_DIR', dirname( __DIR__, 2 ) . '/swpm-rest-api' );

require_once $_tests_dir . '/includes/functions.php';

function _swpm_rest_api_load_plugins() {
	require SWPM_REST_API_TEST_CORE_DIR . '/simple-wp-membership.php';

	if ( get_option( 'swpm_db_version' ) != SIMPLE_WP_MEMBERSHIP_DB_VER ) {
		//Marking the plugin as already installed skips the fresh install tasks, which create the
		//plugin's pages. The tests do not need them.
		SwpmSettings::get_instance()->set_value( 'swpm-active-version', SIMPLE_WP_MEMBERSHIP_VER );

		require_once SWPM_REST_API_TEST_CORE_DIR . '/classes/class.swpm-installation.php';
		SwpmInstallation::run_safe_installer();
	}

	require SWPM_REST_API_TEST_ADDON_DIR . '/swpm-rest-api.php';
}
tests_add_filter( 'muplugins_loaded', '_swpm_rest_api_load_plugins' );

require $_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-swpm-rest-api-test-case.php';
