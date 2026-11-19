=== Simple Membership WP user Import ===
Contributors: smp7, wp.insider
Donate link: https://simple-membership-plugin.com/
Tags: users, wp user, import, export, member, members, membership, access, level
Requires at least: 4.0
Tested up to: 7.1
Stable tag: 1.9.3
License: GPLv2 or later

An addon for importing existing WordPress users to the Simple Membership plugin as members

== Description ==

This addon allows you to import your existing WordPress users into the simple membership plugin as members. 

You will be able to select which membership access level a user goes into when importing the users.

This addon requires the [Simple Membership Plugin](https://wordpress.org/plugins/simple-membership/).

After you install this addon, go to the import users interface and select the option to import.

== Installation ==

1. Upload `swpm-wp-import` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

None

== Screenshots ==

See the following page for screenshots:
https://simple-membership-plugin.com/import-existing-wordpress-users-simple-membership-plugin/

== Changelog ==

= 1.9.3 =
* Added nonce verification and a capability check to the selective import action (CSRF).
* Added a capability check to the import all users action.
* The membership level, account state and subscription start date are now validated before importing.
* Fixed the administrator role check so it works with any database table prefix.
* The user search query now uses a prepared statement.

= 1.9.2 =
* Added Nonce verification to the import all wp users action.

= 1.9.1 =
* Added link to the settings interface from the plugin's list page.

= 1.9 =
* Added the option to sort by "ID" column in the list.

= 1.8 =
* Fixed an SQL injection issue in the orderby parameter.

= 1.7 =
* Fixed members import issue.
* Addon now uses WP built-in jQuery datepicker.

= 1.6 =
* Fixed an undefined function call error.

= 1.5 =
* Added a new action hook so it can be used with the Mailchimp integration addon.

= 1.4 =
* Removed any occurrences of the deprecated mysql_real_escape_string() function.

= 1.3 =
* Updated the function call to retrieve the IP address of the member.

= 1.2 =
* Updated the addon's admin menu rendering code to be more robust.

= 1.1 =
* Added a check for list table inclusion.

= 1.0 =
* First commit to wordpress.org

== Upgrade Notice ==
None
