<?php
/*
Plugin Name: Simple Membership WP User Import
Plugin URI: https://simple-membership-plugin.com/import-existing-wordpress-users-simple-membership-plugin/
Description: Addon for importing existing Wordpress users to Simple Membership
Author: wp.insider
Author URI: https://simple-membership-plugin.com/
Version: 1.9.3
*/

//Slug swmp_wpimport_

define( 'SWPM_WP_IMPORT_VERSION', '1.9.3' );
define('SWPM_WP_IMPORT_PATH', dirname(__FILE__) . '/');
define('SWPM_WP_IMPORT_URL', plugins_url('',__FILE__));
require_once ('classes/class.swpm-wp-import.php');
require_once ('classes/class.swpm_wp_user_list.php');
add_action('plugins_loaded', 'swpm_wp_import_addon');
function swpm_wp_import_addon(){
    new SwpmWpImport();
}

//Add settings link in plugins listing page
function swmp_wpimport_add_settings_link( $links, $file ) {
	if ( $file == plugin_basename( __FILE__ ) ) {
		$settings_link = '<a href="admin.php?page=swpm-wp-import">Settings</a>';
		array_unshift( $links, $settings_link );
	}
	return $links;
}
add_filter( 'plugin_action_links', 'swmp_wpimport_add_settings_link', 10, 2 );
