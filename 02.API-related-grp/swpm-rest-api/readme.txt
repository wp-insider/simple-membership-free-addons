=== Simple Membership REST API ===
Contributors: smp7, wp.insider
Donate link: https://simple-membership-plugin.com/
Tags: member, members, membership, api, rest, rest-api
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0
License: GPLv2 or later

REST API add-on for the Simple Membership plugin.

== Description ==

This add-on for the [Simple Membership](http://wordpress.org/plugins/simple-membership/) plugin
registers a proper WordPress REST API under the `swpm/v1` namespace. It lets external
applications, automation tools (n8n, Make, Zapier) and AI assistants read, and optionally manage,
your membership data over standard authenticated HTTP requests.

This is a different add-on from the older "Simple Membership API" add-on. That one handles a small
set of actions over ad-hoc HTTP POST requests. This one uses WordPress' own REST infrastructure,
so it gets proper HTTP verbs, per-route access rules, request validation, pagination, rate limits
and standard error responses.

= Available endpoints =

Read:

* `GET /wp-json/swpm/v1/members` - list and search member profiles
* `GET /wp-json/swpm/v1/members/<id>` - retrieve a single member profile
* `GET /wp-json/swpm/v1/levels` - list the membership levels
* `GET /wp-json/swpm/v1/levels/<id>` - retrieve a single membership level
* `GET /wp-json/swpm/v1/transactions` - list and search transactions
* `GET /wp-json/swpm/v1/transactions/<id>` - retrieve a single transaction

Write (these require the separate "Allow write access" setting, which is off by default):

* `POST /wp-json/swpm/v1/members` - create a member profile and its WordPress user
* `PATCH /wp-json/swpm/v1/members/<id>` - update a member profile
* `DELETE /wp-json/swpm/v1/members/<id>` - delete a member profile and its WordPress user
* `POST /wp-json/swpm/v1/levels` - create a membership level
* `PATCH /wp-json/swpm/v1/levels/<id>` - update a membership level
* `DELETE /wp-json/swpm/v1/levels/<id>` - delete a membership level

Transactions stay read-only.

Collection endpoints accept `page`, `per_page` (max 100), `search`, `orderby` and `order`, and
return the total number of matching records in the `X-WP-Total` and `X-WP-TotalPages` response
headers.

`/members` also accepts `membership_level`, `account_state` and `email`.
`/transactions` also accepts `member_id`, `email`, `gateway`, `status`, `after` and `before`.

Full details are in `docs/api-reference.md`, and the machine-readable contract is in
`docs/openapi.yaml`.

= Authentication =

Two credential types are accepted.

1. **API key.** Send the key from the settings menu in an `X-SWPM-API-Key` request header. An
   `Authorization: Bearer <key>` header works too. The key is deliberately not accepted as a query
   string parameter, because query strings are recorded in server access logs, browser history and
   referrer headers.
2. **WordPress user.** Any request authenticated as a WordPress user who holds the membership
   management permission is accepted. This covers WordPress Application Passwords and the ordinary
   cookie authentication used from the admin dashboard.

Example:

`curl -H "X-SWPM-API-Key: YOUR_KEY" "https://example.com/wp-json/swpm/v1/members?per_page=5&account_state=active"`

= Security notes =

* The API is off by default. Turn it on from **WP Membership > Settings > Addon Settings**.
* Write access is a separate switch and is also off by default. While it is off the API is
  read-only, so a misconfigured or misbehaving application cannot modify or remove your members.
* Deleting a membership level that still has members assigned to it is refused unless the request
  explicitly passes `force=true`, because a member whose level no longer exists cannot load the
  site's protected pages.
* The main plugin's rule that an associated WordPress account holding an administrator role is
  never deleted still applies to the delete endpoint.
* Always use HTTPS. An API key sent over plain HTTP should be treated as disclosed. The plugin
  records a warning in its debug log when a key is accepted over a non-HTTPS connection.
* Member passwords and registration codes are never included in a response.
* Every authenticated API request is written to the plugin's debug log, so there is an audit trail
  of what an external application did.
* Requests are rate limited per minute. The read, write and failed API key limits can be changed in
  the settings.
* If a key is exposed, tick "Regenerate API Key" in the settings and save.

== Installation ==

1. Upload the `swpm-rest-api` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to **WP Membership > Settings > Addon Settings**, enable the REST API and copy your API key

Note: country validation needs the version of the main plugin that made
`SwpmMiscUtils::get_countries_list()` return country codes. On an older main plugin, country codes
are not recognised.

== Frequently Asked Questions ==

= Can I use this to let an AI assistant work with my membership site? =

Yes. The API is plain authenticated HTTP and JSON, so it can be called from an MCP server, a
ChatGPT action, a Gemini function call, or any agent framework that can make an HTTP request.

= Does this replace the older API add-on? =

Not yet. The two can run side by side. The older add-on is still the only one that handles the
member login action. This add-on covers everything else it does, plus membership levels,
transactions, listing, searching and deletion, over the standard REST infrastructure.

== Changelog ==

= WIP =
* Added create, update and delete endpoints for member profiles.
* Added create, update and delete endpoints for membership levels.
* Member writes keep the linked WordPress user in sync, including its role when the membership
  level changes, and fire the plugin's existing level and status change hooks.
* Deleting a membership level that still has members assigned is refused unless `force=true`.
* Responses are now wrapped in a data/meta/links envelope. Collections report total, per_page,
  current_page, total_pages and has_more, plus self/first/prev/next/last links. The X-WP-Total
  and X-WP-TotalPages headers are still sent as well.
* Fixed the transactions endpoints reading the legacy swpm_payments_tbl table instead of the
  swpm_transactions post type the plugin treats as canonical. The 'id' is now the transaction
  post id (what the Payments menu uses), the legacy row id is reported as 'db_row_id', and the
  payment_button_id, is_live, custom, discount_amount, txn_type and subscr_status fields that
  only exist on the post are now included.
* Fixed membership level create and update not working for the annual fixed date expiry type.
  The level's 'annual_fixed_date_min_period' meta row is now written, and the stored date has its
  year normalised the same way the admin dashboard does it.
* Added the 'annual_fixed_date_min_period' parameter and response field for membership levels.
* The member 'country' field is now validated against the plugin's country list. Two letter ISO
  country codes are accepted and stored as the matching country name. Added the
  'swpm_rest_api_validate_country' filter to turn the check off.
* Added the API reference under `docs/`.
* Passwords now follow the plugin's own rule: any non-empty password, or the Force Strong Password
  rule when that setting is on.
* Country codes are now matched using the main plugin's code keyed country list, which fixes codes
  such as US being rejected.
* Added the default_account_status, email_activation and after_activation_redirect_page fields for
  membership levels. Deleting a level also removes its settings.
* A new member's default status now follows the level's email activation and default status
  settings, and the activation email is sent when activation is required.
* Changing a level's duration type now requires a period for the new type.
* Routes are now all defined in `routes/api.php` through a small router with middleware. Replaced
  the swpm_rest_api_controllers filter with the swpm_rest_api_register_routes action.
* Added configurable rate limits for read requests, write requests and failed API keys.
* `docs/openapi.yaml` is now generated from the route definitions.
* Fixed membership_level_name coming back empty for a level created earlier in the same PHP request
  (for example, in a REST batch request).

= 1.0 =
* First release.
* Added the `swpm/v1` REST API namespace.
* Added read-only endpoints for members, membership levels and transactions.
* Added API key and WordPress user authentication.
* Added a separate write access setting for use by future write endpoints.

== Upgrade Notice ==

None
