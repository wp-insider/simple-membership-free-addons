# Simple Membership REST API — Reference

The machine-readable OpenAPI specification is available in
[openapi.yaml](./openapi.yaml). It is generated from `routes/api.php`, so do not edit it by hand.

All routes live under the `swpm/v1` namespace:

```
https://your-site.com/wp-json/swpm/v1/...
```

If your site does not use pretty permalinks, the same routes are reachable as
`https://your-site.com/?rest_route=/swpm/v1/...`.

---

## Setup

1. Install and activate the **Simple Membership REST API** add-on.
2. Go to **WP Membership → Settings → Addon Settings**.
3. Tick **Enable REST API** and save.
4. Copy the **API Key**.
5. Tick **Allow write access** only if you need the create/update/delete routes. It is off by
   default and every write route returns `403` while it stays off.

---

## Authentication

Every route requires authentication. Two credential types are accepted.

### API key (recommended for machines)

```
X-SWPM-API-Key: YOUR_API_KEY
```

An `Authorization: Bearer YOUR_API_KEY` header works identically, for clients that make bearer
tokens easier to configure.

The key is **not** accepted as a query string parameter. Query strings end up in server access
logs, browser history and referrer headers, so a key sent that way should be considered
disclosed.

### WordPress user

Any request authenticated as a WordPress user holding the membership management capability is
accepted. In practice this means [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/):

```
Authorization: Basic base64(username:application_password)
```

This path needs no API key at all, and is the easiest way to test from a browser session or from
tools that already speak WordPress authentication.

---

## Conventions

### Response envelope

Every successful response is wrapped. Collections carry `data`, `meta` and `links`:

```json
{
  "data": [ { "member_id": 12, "...": "..." } ],
  "meta": {
    "total": 248,
    "per_page": 20,
    "current_page": 3,
    "total_pages": 13,
    "has_more": true
  },
  "links": {
    "self":  "https://example.com/wp-json/swpm/v1/members?per_page=20&page=3",
    "first": "https://example.com/wp-json/swpm/v1/members?per_page=20&page=1",
    "prev":  "https://example.com/wp-json/swpm/v1/members?per_page=20&page=2",
    "next":  "https://example.com/wp-json/swpm/v1/members?per_page=20&page=4",
    "last":  "https://example.com/wp-json/swpm/v1/members?per_page=20&page=13"
  }
}
```

`prev` and `next` are `null` at the ends of the range. Every filter and sort parameter you sent is
carried into the generated links, so following `next` keeps the same query.

Single items carry `data` and `links`, with no `meta` block:

```json
{
  "data": { "member_id": 12, "...": "..." },
  "links": {
    "self": "https://example.com/wp-json/swpm/v1/members/12",
    "level": "https://example.com/wp-json/swpm/v1/levels/3",
    "transactions": "https://example.com/wp-json/swpm/v1/transactions?member_id=12"
  }
}
```

The counts travel in the body because many HTTP clients, no-code tools and AI agent frameworks
never expose response headers to the caller, which would otherwise leave them unable to page
through results at all.

### Pagination

Collection endpoints accept `page` and `per_page` (default `20`, max `100`).

Alongside the `meta` block, the WordPress convention headers are still sent, so tooling that
expects them keeps working:

| Header | Meaning |
| --- | --- |
| `X-WP-Total` | Total matching records, ignoring pagination |
| `X-WP-TotalPages` | Total number of pages at the current `per_page` |

### Sorting

Collection endpoints accept `orderby` (values differ per resource, listed below) and `order`
(`asc` or `desc`).

### Rate limits

Requests are limited per minute. Set the limits under **Addon Settings**; `0` means no limit.

| Limit | Default | Counted per |
| --- | --- | --- |
| Read requests | `120` | API client (the API key, or the WordPress user) |
| Create, update and delete requests | `30` | API client |
| Wrong API keys | `20` | IP address |

Successful responses carry `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` (a
Unix timestamp). Over the limit, the API returns `429 swpm_rest_api_rate_limited` with a
`Retry-After` header and the same number of seconds in `data.retry_after`.

Without a persistent object cache, the counters are not atomic, so limits are approximate when
requests arrive at the same moment.

On a site behind a reverse proxy, every wrong key can appear to come from the proxy's IP address.
Use the `swpm_rest_api_client_ip` filter to supply the visitor's real address.

### Errors

Errors are **not** wrapped in the `data` envelope. They keep the standard WordPress REST error
shape, which clients detect from the HTTP status code:

```json
{
  "code": "swpm_rest_invalid_member_data",
  "message": "One or more member fields were rejected. See the errors property for details.",
  "data": {
    "status": 400,
    "errors": {
      "email": "Email is already used by another member."
    }
  }
}
```

| Code | Status | Meaning |
| --- | --- | --- |
| `swpm_rest_api_disabled` | 403 | The API is switched off in the add-on settings |
| `swpm_rest_api_unauthorized` | 401 | Missing or invalid credentials |
| `swpm_rest_api_write_disabled` | 403 | Write access is switched off in the add-on settings |
| `swpm_rest_api_rate_limited` | 429 | Too many requests; see `data.retry_after` |
| `swpm_rest_route_misconfigured` | 500 | A route has no valid middleware, so it was refused |
| `swpm_rest_not_found` | 404 | No such member, level or transaction |
| `swpm_rest_invalid_member_data` | 400 | Field level validation failed; see `data.errors` |
| `swpm_rest_invalid_level_data` | 400 | Field level validation failed; see `data.errors` |
| `swpm_rest_nothing_to_update` | 400 | The request body contained no updatable fields |
| `swpm_rest_invalid_date` | 400 | A date argument was not valid `YYYY-MM-DD` |
| `swpm_rest_level_in_use` | 409 | Level still has members; see `data.member_count` |
| `swpm_rest_wp_user_creation_failed` | 400/500 | The linked WordPress user could not be created |
| `swpm_rest_create_failed` | 500 | Database write failed |
| `swpm_rest_update_failed` | 500 | Database write failed |
| `swpm_rest_delete_failed` | 500 | Database delete failed |

---

## Members

### `GET /members`

List and search member profiles.

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `page` | integer | `1` | |
| `per_page` | integer | `20` | Max `100` |
| `search` | string | | Matches username, first name, last name or email |
| `orderby` | string | `member_id` | `member_id`, `user_name`, `first_name`, `last_name`, `email`, `member_since`, `last_accessed`, `membership_level`, `account_state` |
| `order` | string | `desc` | `asc` or `desc` |
| `membership_level` | integer | | Filter by level id |
| `account_state` | string | | `active`, `inactive`, `activation_required`, `expired`, `pending`, `unsubscribed` |
| `email` | string | | Exact match |

### `GET /members/{id}`

Retrieve one member profile.

### Member object

```json
{
  "member_id": 12,
  "user_name": "jane_doe",
  "first_name": "Jane",
  "last_name": "Doe",
  "email": "jane@example.com",
  "phone": "",
  "membership_level": 3,
  "membership_level_name": "Silver Level",
  "initial_membership_level": 3,
  "account_state": "active",
  "member_since": "2026-01-15",
  "subscription_starts": "2026-01-15",
  "expiry_date": "2027-01-15",
  "is_expired": false,
  "last_accessed": "2026-09-01 10:22:04",
  "last_accessed_from_ip": "203.0.113.4",
  "company_name": "",
  "address_street": "",
  "address_city": "",
  "address_state": "",
  "address_zipcode": "",
  "country": "",
  "home_page": "",
  "gender": "not specified",
  "referrer": "",
  "notes": "",
  "profile_image": "",
  "txn_id": "",
  "subscr_id": "",
  "wp_user_id": 45
}
```

Notes:

- `expiry_date` is `null` when the level never expires, and `is_expired` is then `false`.
- Both are `null` when the member's level no longer exists, which is a broken state worth fixing.
- The `password`, `reg_code` and `extra_info` columns are never returned.

### `POST /members`

Create a member profile and the WordPress user account behind it.

Requires write access.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `user_name` | string | yes | Cannot be changed later |
| `email` | string | yes | Must be unique among members |
| `password` | string | yes | Must not be empty. When **Force Strong Password** is on: 8+ characters with an uppercase letter, a lowercase letter and a digit (see below) |
| `membership_level` | integer | yes | Must be an existing level, and not level 1 |
| `first_name`, `last_name` | string | no | |
| `account_state` | string | no | Defaults as described below |
| `member_since` | string | no | `YYYY-MM-DD`, defaults to today |
| `subscription_starts` | string | no | `YYYY-MM-DD`, defaults to today. Expiry is calculated from this |
| `gender` | string | no | `male`, `female`, `not specified` |
| `country` | string | no | A country name from the plugin's list, or a two letter country code. Stored as the name |
| `phone`, `address_street`, `address_city`, `address_state`, `address_zipcode`, `company_name`, `referrer`, `subscr_id`, `txn_id` | string | no | |
| `home_page` | string | no | URL |
| `notes` | string | no | |
| `send_notification` | boolean | no | By default a member with status `activation_required` is emailed the activation link; otherwise the plugin's "notification after manual user add" setting decides |

Returns `201` with the created member object and a `Location` header.

When `account_state` is not sent, the status is decided the same way as front-end registration:

1. The level has email activation on: `activation_required`
2. Otherwise, the level's `default_account_status`, if set
3. Otherwise, the plugin's global default account status

**Strong passwords.** The strength rule applies only when the plugin's **Force Strong Password**
setting is on. The minimum length and pattern can be changed with the
`swpm_reg_pass_min_length_validation` and `swpm_reg_pass_pattern_validation` filters on create, and
the `swpm_profile_pass_` equivalents on update.

Rejected if the username or email belongs to an administrator, or is already attached to an
existing WordPress user (unless the `swpm_allow_existing_wp_user_registration` filter permits it).

If the WordPress user cannot be created, the member row is rolled back so no orphaned profile is
left behind.

### `PATCH /members/{id}` (or `PUT`)

Update a member profile. Send only the fields you want to change; anything you leave out is kept.

Requires write access.

Accepts every field from `POST` except `user_name` and `send_notification`. All fields are
optional, but the body must contain at least one of them. An empty `password` leaves the password
unchanged. Sending a member's existing `country` back unchanged is always accepted, even if that
older value is not on the current list.

Side effects:

- Changing `email`, `first_name`, `last_name` or `password` syncs those to the WordPress user.
- Changing `membership_level` updates the WordPress role to the one configured on the new level
  and fires `swpm_membership_level_changed`.
- Changing `account_state` fires `swpm_account_status_updated`.

### `DELETE /members/{id}`

Delete a member profile and its linked WordPress user.

Requires write access.

The linked WordPress user is preserved if it carries an administrator role — this is the main
plugin's own safeguard, not something this add-on can override.

```json
{
  "data": {
    "deleted": true,
    "previous": { "member_id": 12, "...": "..." }
  },
  "links": {}
}
```

---

## Membership levels

### `GET /levels`

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `page` | integer | `1` | |
| `per_page` | integer | `20` | Max `100` |
| `search` | string | | Matches the level name |
| `orderby` | string | `id` | `id`, `name`, `role` |
| `order` | string | `asc` | |

### `GET /levels/{id}`

### Level object

```json
{
  "id": 3,
  "name": "Silver Level",
  "role": "subscriber",
  "subscription_period": "365",
  "subscription_duration_type": 1,
  "subscription_duration_type_name": "days",
  "annual_fixed_date_min_period": null,
  "default_account_status": null,
  "email_activation": false,
  "after_activation_redirect_page": null,
  "subscription_unit": null,
  "loginredirect_page": "",
  "protect_older_posts": false,
  "campaign_name": "",
  "member_count": 42
}
```

Duration types:

| Value | Name | `subscription_period` holds |
| --- | --- | --- |
| `0` | `no_expiry` | (empty) |
| `1` | `days` | A positive number |
| `2` | `weeks` | A positive number |
| `3` | `months` | A positive number |
| `4` | `years` | A positive number |
| `5` | `fixed_date` | `YYYY-MM-DD` |
| `6` | `annual_fixed_date` | `YYYY-MM-DD` — only month and day are meaningful |

**Annual fixed date levels need two things.** Send `subscription_period` as a full `YYYY-MM-DD`
date, but only the month and day are kept: the year is rewritten to the current year on save,
because `SwpmUtils::get_expiration_timestamp()` re-anchors it to the year each member's
subscription started. This matches exactly what the admin dashboard stores.

They also need `annual_fixed_date_min_period` — the minimum number of days of access. If a member
joins fewer than that many days before the fixed date, their expiry moves to the following year.
It lives in the `swpm_membership_meta_tbl` table rather than the main level row, and a level
created without it is structurally incomplete: the admin level screen shows an empty minimum
period box and the expiry calculation reads it back as zero. The API writes that row for you.

Level id `1` is the plugin's internal "Content Protection" record, not a real membership level.
It is excluded from `GET /levels` and returns `404` on every single-level route, matching how the
admin dashboard hides it.

The bulky content protection columns (`category_list`, `page_list`, `post_list` and friends) are
not exposed. They are serialized blobs only meaningful to the plugin itself.

### `POST /levels`

Requires write access.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `name` | string | yes | |
| `role` | string | no | Must be a role that exists on the site. Defaults to `subscriber` |
| `subscription_duration_type` | integer | no | `0`–`6`, defaults to `0` (no expiry) |
| `subscription_period` | string | conditional | Required unless the duration type is `0` |
| `annual_fixed_date_min_period` | integer | no | Minimum days of access, for duration type `6` |
| `default_account_status` | string | no | `active`, `inactive`, `activation_required`, `pending`, `expired`, or empty to use the global setting |
| `email_activation` | boolean | no | New members must confirm their email address before logging in |
| `after_activation_redirect_page` | string | no | URL. Empty clears it |
| `loginredirect_page` | string | no | URL |
| `campaign_name` | string | no | |
| `protect_older_posts` | boolean | no | |

Returns `201` with the created level object.

### `PATCH /levels/{id}` (or `PUT`)

Requires write access. Send only the fields you want to change.

When you change `subscription_period` without also sending `subscription_duration_type`, the
stored duration type is used to decide what a valid period looks like. Changing the duration type
also requires a `subscription_period` for the new type, unless the new type is `0`.

### `DELETE /levels/{id}`

Requires write access.

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `force` | boolean | `false` | Delete even though members are still assigned |

Refuses with `409` when members still hold the level. A member pointing at a level that no longer
exists is a genuinely broken state — the plugin treats it as a critical error and halts front-end
page loads for that member — so move the members first. Pass `force=true` only if you understand
that consequence.

Deleting a level also removes its settings from `swpm_membership_meta_tbl` and its
`swpm_email_activation_lvl_{id}` option.

---

## Transactions

Read-only. These are the same records listed under the plugin's Payments menu.

### Which record `id` refers to

The plugin stores every payment **twice**. `SwpmTransactions::save_txn_record()` inserts a row
into the `swpm_payments_tbl` table — its own comment calls that *"for backwards compatibility"* —
and then creates a `swpm_transactions` custom post, linking the two through a `db_row_id` post
meta.

The post is the richer record. These fields exist **only** on it and have no column in the legacy
table: `payment_button_id`, `is_live`, `custom`, `discount_amount`, `txn_type`, `subscr_status`.

So this API treats the post as canonical:

- `id` is the **post ID**, the same identifier the admin Payments screen works with.
- `db_row_id` reports the legacy table's row id, for anything still keyed to it.

### `GET /transactions`

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `page` | integer | `1` | |
| `per_page` | integer | `20` | Max `100` |
| `search` | string | | Matches email, first name, last name, transaction id or subscription id |
| `orderby` | string | `id` | `id` or `txn_date` only — see below |
| `order` | string | `desc` | |
| `member_id` | integer | | |
| `email` | string | | Exact match |
| `gateway` | string | | e.g. `stripe`, `paypal` |
| `status` | string | | e.g. `Completed` |
| `subscr_id` | string | | Exact match |
| `after` | string | | `YYYY-MM-DD`, inclusive |
| `before` | string | | `YYYY-MM-DD`, inclusive |

**Why only two sort fields.** Sorting by a post meta value requires that meta row to exist, and
`save_txn_record()` runs `array_filter()` over the transaction data before writing it — so any
field that happened to be empty at save time has no meta row at all. Sorting by `status` or
`gateway` would therefore silently drop every transaction missing that value from the results.
`txn_date` is always written, so it is safe; `id` sorts on the post itself. Filtering by the other
fields is unaffected, because excluding records without a value is the correct behaviour there.

**Performance note.** Filters and search run as `meta_query` lookups against the post meta table.
On sites with a large payment history these are slower than indexed column lookups. This is the
same approach the plugin's own Payments screen uses, so the API is no slower than the dashboard,
but it is worth knowing before pointing a tight polling loop at it.

### `GET /transactions/{id}`

`{id}` is the post ID.

### Transaction object

```json
{
  "id": 1842,
  "db_row_id": 88,
  "member_id": 12,
  "email": "jane@example.com",
  "first_name": "Jane",
  "last_name": "Doe",
  "membership_level": "3",
  "txn_date": "2026-01-15",
  "txn_id": "ch_3Abc123",
  "subscr_id": "sub_1Xyz",
  "subscr_status": "",
  "reference": "",
  "payment_amount": "49.00",
  "discount_amount": "",
  "gateway": "stripe",
  "status": "Completed",
  "ip_address": "203.0.113.4",
  "payment_button_id": "412",
  "is_live": "1",
  "custom": "subsc_ref=3&user_ip=203.0.113.4",
  "txn_type": ""
}
```

Fields that were empty when the payment was recorded come back as empty strings, because no meta
row was written for them.

---

## Hooks

For site owners extending the add-on.

### Filters

| Filter | Purpose |
| --- | --- |
| `swpm_rest_api_check_permission` | Veto an authenticated request (IP allowlists, per-key scopes). Return a `WP_Error` to reject |
| `swpm_rest_api_rate_limit` | Change the per-minute limit. Receives the limit and the bucket (`read`, `write`, `failed-auth`). Return `0` for no limit |
| `swpm_rest_api_client_ip` | Supply the visitor's real IP address on a site behind a reverse proxy |
| `swpm_rest_api_prepare_member` | Add or remove fields on a member response |
| `swpm_rest_api_prepare_level` | Add or remove fields on a level response |
| `swpm_rest_api_prepare_transaction` | Add or remove fields on a transaction response |
| `swpm_rest_api_validate_country` | Return `false` to accept any country string, for sites holding imported or legacy values |

The main plugin's `swpm_countries_list` filter changes the shared `code => name` country list this
add-on validates against.

### Actions

| Action | Arguments |
| --- | --- |
| `swpm_rest_api_register_routes` | `$router`. Add your own routes and middleware to the namespace |
| `swpm_rest_api_member_created` | `$member_id`, `$member` |
| `swpm_rest_api_member_updated` | `$member_id`, `$changes` |
| `swpm_rest_api_member_deleted` | `$member_id` |
| `swpm_rest_api_level_created` | `$level_id`, `$level` |
| `swpm_rest_api_level_updated` | `$level_id`, `$changes` |
| `swpm_rest_api_level_deleted` | `$level_id` |

The main plugin's own `swpm_membership_level_changed` and `swpm_account_status_updated` hooks
also fire when the API changes those values, so existing integrations keep working.

---

## Security notes

- **Always use HTTPS.** An API key sent over plain HTTP should be treated as disclosed. The
  plugin writes a warning to its debug log whenever a key is accepted over a non-HTTPS
  connection.
- **Keep write access off** unless something actually needs it.
- **Every authenticated request is logged** to the plugin's debug log with method, route, scope
  and how the caller authenticated, so there is an audit trail.
- **Rotate a leaked key** with the "Regenerate API Key" checkbox in the settings. Applications
  using the old key stop working immediately.
- The API key is stored in plain text in `wp_options`, same as the older API add-on's key. Anyone
  with database or admin access can read it.
