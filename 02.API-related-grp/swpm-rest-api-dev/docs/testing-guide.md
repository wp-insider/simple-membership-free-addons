# Simple Membership REST API — Testing Guide

A walk-through for verifying the add-on by hand before any automated tests exist.

Everything here uses `curl`. The same requests work in Postman, Insomnia, HTTPie or a browser
REST client.

---

## 0. Where to run this

You need a **browsable WordPress install** with both the main plugin and this add-on active.

> The `docker-compose.yml` in the `simple-membership` repo is **not** suitable for this. It builds
> a PHP CLI container that installs the WordPress *test library* for PHPUnit — it exposes no web
> server and no port, so there is no site to send HTTP requests to. It is for automated tests, not
> for the manual ones here.

Any of these will do for manual testing:

- [Local](https://localwp.com/) (easiest on Windows)
- `wp-env` (`npx @wordpress/env start`)
- XAMPP / Laragon
- A staging site

Symlink or copy both plugins into `wp-content/plugins/`:

```
wp-content/plugins/simple-membership/      <- from the simple-membership repo
wp-content/plugins/swpm-rest-api/          <- from the simple-membership-addons repo
```

Activate both under **Plugins**.

---

## 1. Lint before you deploy

Worth doing first, since a parse error shows up as a white screen rather than a useful message:

```bash
cd simple-membership-addons/swpm-rest-api
find . -name "*.php" -exec php -l {} \;
```

Every file should report `No syntax errors detected`.

If you have no local PHP, run it inside the test container from the `simple-membership` repo:

```bash
docker compose run --rm swpm
# then, inside the container:
find /app/../simple-membership-addons/swpm-rest-api -name "*.php" -exec php -l {} \;
```

(Adjust the path to wherever you mounted the add-ons repo.)

---

## 2. Set up

1. **WP Membership → Settings → Addon Settings**
2. Tick **Enable REST API**, save.
3. Copy the **API Key**.
4. Leave **Allow write access** unticked for now — step 6 turns it on deliberately.

Also turn on the plugin's debug logging (**WP Membership → Settings → Advanced Settings**) so you
can watch the audit trail build up.

Set up your shell:

```bash
export SITE="http://your-local-site.test"
export KEY="paste_your_api_key_here"
export API="$SITE/wp-json/swpm/v1"
```

On Windows PowerShell:

```powershell
$SITE = "http://your-local-site.test"
$KEY  = "paste_your_api_key_here"
$API  = "$SITE/wp-json/swpm/v1"
```

Before testing, create at least one membership level and two or three members through the admin
dashboard so there is something to read.

---

## 3. Smoke test — is the namespace registered?

```bash
curl -s "$SITE/wp-json/" | grep -o 'swpm/v1'
```

Expect `swpm/v1`. Nothing back means the add-on is not active, or the main plugin is missing.

List the routes:

```bash
curl -s "$SITE/wp-json/swpm/v1" | python -m json.tool
```

You should see `/swpm/v1/members`, `/swpm/v1/levels` and `/swpm/v1/transactions`.

---

## 4. Authentication tests (the negative ones matter most)

| # | Request | Expect |
| --- | --- | --- |
| 4.1 | No key at all | `401 swpm_rest_api_unauthorized` |
| 4.2 | Wrong key | `401 swpm_rest_api_unauthorized` |
| 4.3 | Correct key | `200` |
| 4.4 | Key in the query string | `401` — query string keys are deliberately not accepted |
| 4.5 | API disabled in settings, correct key | `403 swpm_rest_api_disabled` |

```bash
# 4.1 no credentials
curl -s -o /dev/null -w "%{http_code}\n" "$API/members"

# 4.2 wrong key
curl -s -o /dev/null -w "%{http_code}\n" -H "X-SWPM-API-Key: nope" "$API/members"

# 4.3 correct key
curl -s -o /dev/null -w "%{http_code}\n" -H "X-SWPM-API-Key: $KEY" "$API/members"

# 4.4 key in the query string — must NOT work
curl -s -o /dev/null -w "%{http_code}\n" "$API/members?key=$KEY"

# 4.5 untick "Enable REST API" in the settings, then:
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members"
# re-tick it before continuing
```

Bearer header should behave identically to 4.3:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -H "Authorization: Bearer $KEY" "$API/members"
```

Application Password path (create one under **Users → Profile → Application Passwords**):

```bash
curl -s -o /dev/null -w "%{http_code}\n" -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" "$API/members"
```

Expect `200`. A user *without* membership management permission should get `401`.

---

## 5. Read endpoints

### 5.1 Members

```bash
# basic list
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members" | python -m json.tool

# envelope: data / meta / links
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members?per_page=1&page=2" | python -m json.tool

# pagination headers (still sent alongside the meta block)
curl -s -D - -o /dev/null -H "X-SWPM-API-Key: $KEY" "$API/members?per_page=1" | grep -i "x-wp-"

# search
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members?search=jane"

# filters
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members?account_state=active&membership_level=2"

# sorting
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members?orderby=email&order=asc"

# single member
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members/1"

# missing member
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members/999999"
```

Check carefully:

- [ ] The response has `data`, `meta` and `links` at the top level
- [ ] `meta.total` matches the real member count; `current_page`, `total_pages`, `has_more` agree
- [ ] `links.prev` is `null` on page 1, `links.next` is `null` on the last page
- [ ] Following `links.next` returns the next page, **with any filters preserved**
- [ ] A single member response has `data` and `links` (`self`, `level`, `transactions`), no `meta`
- [ ] `X-WP-Total` and `X-WP-TotalPages` are present and correct
- [ ] **No `password` field anywhere in the response**
- [ ] **No `reg_code` or `extra_info` field**
- [ ] `membership_level_name` is filled in, not empty
- [ ] `expiry_date` looks right, and is `null` for a never-expiring level
- [ ] `wp_user_id` matches the real WordPress user
- [ ] `999999` returns `404 swpm_rest_not_found`

Rejected input should be rejected:

```bash
# invalid enum
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members?account_state=banana"      # 400
# invalid orderby
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members?orderby=password"          # 400
# over the per_page cap
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members?per_page=5000"             # 400
# malformed email
curl -s -H "X-SWPM-API-Key: $KEY" "$API/members?email=notanemail"          # 400
```

That `orderby=password` case is the important one — it is the SQL injection guard on the
`ORDER BY` clause. It must come back `400`, never `200`.

### 5.2 Levels

```bash
curl -s -H "X-SWPM-API-Key: $KEY" "$API/levels" | python -m json.tool
curl -s -H "X-SWPM-API-Key: $KEY" "$API/levels/2"
curl -s -H "X-SWPM-API-Key: $KEY" "$API/levels/1"
```

- [ ] Level id `1` ("Content Protection") does **not** appear in the list
- [ ] `GET /levels/1` returns `404`
- [ ] `member_count` matches what the admin Members page shows per level
- [ ] `subscription_duration_type_name` reads sensibly (`days`, `no_expiry`, …)
- [ ] No `category_list` / `page_list` / `post_list` blobs in the response

### 5.3 Transactions

```bash
curl -s -H "X-SWPM-API-Key: $KEY" "$API/transactions" | python -m json.tool
curl -s -H "X-SWPM-API-Key: $KEY" "$API/transactions?after=2026-01-01&before=2026-12-31"
curl -s -H "X-SWPM-API-Key: $KEY" "$API/transactions?gateway=stripe&status=Completed"
curl -s -H "X-SWPM-API-Key: $KEY" "$API/transactions?orderby=txn_date&order=asc"
curl -s -H "X-SWPM-API-Key: $KEY" "$API/transactions?after=not-a-date"     # 400
```

These endpoints now read the `swpm_transactions` post type rather than the legacy
`swpm_payments_tbl` table, so the identifiers changed. Check:

- [ ] `id` matches the **post ID** the admin Payments screen uses — open a transaction in the
      dashboard and compare. This is the bug that was reported as "invalid cpt_id"
- [ ] `db_row_id` is present and holds the legacy table's row id
- [ ] `GET /transactions/{id}` with that same id returns **the same record** as the list did
- [ ] `payment_button_id`, `is_live`, `custom`, `discount_amount`, `txn_type` and `subscr_status`
      appear — these exist only on the post and were missing before
- [ ] `meta.total` matches the number of rows the admin Payments screen reports
- [ ] `orderby=txn_date` returns every transaction, none dropped. Compare the count against
      `orderby=id` — **they must match**
- [ ] `orderby=status` is rejected with `400` (deliberate: see the api-reference for why)

If you have no payment records, an empty `data` array with `meta.total: 0` is correct.

---

## 6. The write gate

**This is the most important test in the guide.** With **Allow write access** still unticked:

```bash
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"user_name":"gatetest","email":"gate@example.com","password":"secret123","membership_level":2}' \
  "$API/members"
```

Expect `403 swpm_rest_api_write_disabled`, and **no member created**. Confirm in the admin
dashboard that nothing appeared.

Repeat for the other write routes — all should be `403`:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X DELETE -H "X-SWPM-API-Key: $KEY" "$API/members/1"
curl -s -o /dev/null -w "%{http_code}\n" -X POST   -H "X-SWPM-API-Key: $KEY" "$API/levels"
curl -s -o /dev/null -w "%{http_code}\n" -X DELETE -H "X-SWPM-API-Key: $KEY" "$API/levels/2"
```

Now tick **Allow write access** and save. Re-run the first request — it should now succeed.

---

## 7. Member write endpoints

Replace `2` with a real membership level id throughout.

### 7.1 Create

```bash
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" -d '{
  "user_name": "api_test_user",
  "email": "api_test_user@example.com",
  "password": "TestPass123",
  "membership_level": 2,
  "first_name": "Api",
  "last_name": "Tester",
  "send_notification": false
}' "$API/members" | python -m json.tool
```

- [ ] `201` with a `Location` header
- [ ] The member appears under **WP Membership → Members**
- [ ] A matching WordPress user exists under **Users**, with the role from that level
- [ ] `member_since` and `subscription_starts` default to today
- [ ] `expiry_date` is calculated from the level
- [ ] No email arrived (because `send_notification` was `false`)

Note the returned `member_id` — call it `$MID`.

### 7.2 Create — rejections

Each of these must fail cleanly with `400` and a useful `data.errors` map, **not** a white screen
or a raw PHP error page:

```bash
# duplicate username
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"user_name":"api_test_user","email":"other@example.com","password":"TestPass123","membership_level":2}' "$API/members"

# duplicate email
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"user_name":"another_user","email":"api_test_user@example.com","password":"TestPass123","membership_level":2}' "$API/members"

# nonexistent level
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"user_name":"lvl_user","email":"lvl@example.com","password":"TestPass123","membership_level":9999}' "$API/members"

# level 1 (the internal Content Protection record)
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"user_name":"lvl1_user","email":"lvl1@example.com","password":"TestPass123","membership_level":1}' "$API/members"

# the site administrator's email address  <-- the wp_die() guard
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"user_name":"admin_clash","email":"YOUR_ADMIN_EMAIL","password":"TestPass123","membership_level":2}' "$API/members"

# empty password
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"user_name":"empty_pw","email":"empty@example.com","password":"","membership_level":2}' "$API/members"

# missing required fields
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"user_name":"incomplete","email":"inc@example.com","password":"TestPass123"}' "$API/members"

# nonsense country  <-- the reported bug
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"user_name":"bad_country","email":"bc@example.com","password":"TestPass123","membership_level":2,"country":"Narnia"}' "$API/members"
```

Country handling, now validated against the plugin's list:

```bash
# exact name  -> accepted, stored as-is
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"country":"Australia"}' "$API/members/$MID"

# wrong case  -> accepted, normalised to "Australia"
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"country":"australia"}' "$API/members/$MID"

# country code -> accepted, stored as "Australia"
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"country":"AU"}' "$API/members/$MID"

# the code that failed in round 2 -> stored as "United States of America"
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"country":"US"}' "$API/members/$MID"

# nonsense    -> 400 with data.errors.country
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"country":"Narnia"}' "$API/members/$MID"

# empty       -> accepted, clears the field
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"country":""}' "$API/members/$MID"
```

- [ ] The stored value always matches a name in the registration form's country dropdown
- [ ] This needs the latest main plugin, where `SwpmMiscUtils::get_countries_list()` is keyed by
      country code. If `US` is rejected, check the main plugin is up to date before reporting it

The administrator-email case deserves a close look. The main plugin's own guard for it calls
`wp_die()`, which would wreck a REST response; the add-on re-implements the check so it never
gets that far. A clean JSON `400` here means that worked.

After all of these, confirm **no partial members** were left behind in the admin Members list.

Password rule:

- [ ] With **Force Strong Password** off, a one-character password is accepted and can log in
- [ ] With it on, `Ab1` and `abcdefgh1` are rejected with `data.errors.password`; `Abcdefg1` works

New member status, when `account_state` is not sent:

- [ ] Level with email activation on: status `activation_required`, and the activation email
      arrives and works
- [ ] Level with a default account status: that status is used
- [ ] Neither set on the level: the global default is used
- [ ] An explicit `account_state` always wins

### 7.3 Log in as the new member

Log out, then log in on the front end as `api_test_user` / `TestPass123`.

This is worth doing by hand: it proves the password hashing in the API matches what the
plugin's own login expects.

### 7.4 Update

```bash
export MID=12   # the id from 7.1

# partial update
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"first_name":"Updated","notes":"changed via API"}' "$API/members/$MID"

# email change — should sync to the WP user
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"email":"updated_email@example.com"}' "$API/members/$MID"

# level change — should change the WP role too
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"membership_level":3}' "$API/members/$MID"

# status change
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"account_state":"inactive"}' "$API/members/$MID"

# password change
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"password":"NewPass456"}' "$API/members/$MID"

# empty body
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{}' "$API/members/$MID"     # 400 swpm_rest_nothing_to_update

# missing member
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"first_name":"X"}' "$API/members/999999"    # 404
```

- [ ] Fields you did **not** send are untouched — this is the partial-update guarantee
- [ ] The WordPress user's email changed along with the member's
- [ ] The WordPress role changed with the membership level
- [ ] You can log in with the new password afterwards
- [ ] `user_name` cannot be changed (sending it is simply ignored)

### 7.5 Delete

```bash
curl -s -X DELETE -H "X-SWPM-API-Key: $KEY" "$API/members/$MID" | python -m json.tool
```

- [ ] `{"deleted": true, "previous": {...}}`
- [ ] Gone from **WP Membership → Members**
- [ ] The linked WordPress user is gone from **Users**
- [ ] A second `DELETE` on the same id returns `404`

Now the safety check. Link a member profile to an **administrator** WordPress account, then delete
the member through the API:

- [ ] The member profile is removed
- [ ] The administrator's WordPress user account **survives**

---

## 8. Level write endpoints

```bash
# create — no expiry
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"API Test Level","role":"subscriber","subscription_duration_type":0}' "$API/levels"

# create — 30 days
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"API Monthly","subscription_duration_type":1,"subscription_period":"30"}' "$API/levels"

# create — fixed date
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"API Fixed","subscription_duration_type":5,"subscription_period":"2027-06-30"}' "$API/levels"

# create — ANNUAL fixed date (the reported bug)
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"API Annual","subscription_duration_type":6,"subscription_period":"2027-06-30","annual_fixed_date_min_period":30}' \
  "$API/levels" | python -m json.tool

# switch an existing level TO annual fixed date
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"subscription_duration_type":6,"subscription_period":"2027-03-15","annual_fixed_date_min_period":45}' \
  "$API/levels/$LID"

# change only the minimum period
curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"annual_fixed_date_min_period":60}' "$API/levels/$LID"
```

For the annual fixed date cases specifically:

- [ ] **Open the level in the admin dashboard.** The "annual fixed date" radio is selected, the
      month/day match, and the minimum period box shows the number you sent — not empty. An empty
      box is the original bug
- [ ] The response's `subscription_period` has the **current** year, not the one you sent. Only
      month and day are meaningful; the dashboard stores it the same way
- [ ] `annual_fixed_date_min_period` comes back in the level response
- [ ] A level created this way, compared against one created through the dashboard with the same
      settings, produces **identical rows** in both `swpm_membership_tbl` and
      `swpm_membership_meta_tbl`
- [ ] Assign a member to the level and confirm the expiry date is calculated, not blank
- [ ] A partial update that changes only `name` leaves `annual_fixed_date_min_period` intact

Rejections, all `400`:

```bash
# duration type needs a period
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"No Period","subscription_duration_type":1}' "$API/levels"

# non-numeric period for a days-based level
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"Bad Period","subscription_duration_type":1,"subscription_period":"thirty"}' "$API/levels"

# bad date for a fixed-date level
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"Bad Date","subscription_duration_type":5,"subscription_period":"30/06/2027"}' "$API/levels"

# role that does not exist
curl -s -X POST -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"Bad Role","role":"wizard"}' "$API/levels"
```

Update and delete:

```bash
export LID=7   # a level id you just created

curl -s -X PATCH -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"API Test Level Renamed"}' "$API/levels/$LID"

# delete an empty level — should succeed
curl -s -X DELETE -H "X-SWPM-API-Key: $KEY" "$API/levels/$LID"

# level 1 is protected
curl -s -X DELETE -H "X-SWPM-API-Key: $KEY" "$API/levels/1"        # 404
curl -s -X PATCH  -H "X-SWPM-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"hack"}' "$API/levels/1"                             # 404
```

The in-use guard — assign a member to a level, then try to delete it:

```bash
curl -s -X DELETE -H "X-SWPM-API-Key: $KEY" "$API/levels/2"
# expect 409 swpm_rest_level_in_use, with data.member_count

curl -s -X DELETE -H "X-SWPM-API-Key: $KEY" "$API/levels/2?force=true"
# expect 200 — but this leaves those members pointing at a level that no longer exists
```

Only run the `force=true` case on a throwaway site. Afterwards, load the front end as one of the
orphaned members to see why the guard exists — the plugin halts the page with a critical error.

---

### 8.1 Level settings

Send `default_account_status`, `email_activation` and `after_activation_redirect_page` on create
and on update.

- [ ] All three come back in `GET /levels/{id}`
- [ ] All three show correctly on the admin level edit screen
- [ ] A setting saved in the admin screen shows correctly through the API
- [ ] A PATCH of one setting leaves the other two alone
- [ ] `default_account_status: ""` returns to "Use global settings"
- [ ] Repeating a PATCH does not add duplicate rows in `swpm_membership_meta_tbl`
- [ ] Changing `subscription_duration_type` without a `subscription_period` returns `400`
- [ ] Deleting a level removes its rows in `swpm_membership_meta_tbl` and its
      `swpm_email_activation_lvl_{id}` option

---

## 9. Rate limits

Temporarily set **Read Rate Limit** to `3` in the settings.

- [ ] Successful responses include `X-RateLimit-Limit`, `-Remaining` and `-Reset`
- [ ] The 4th read in a minute returns `429 swpm_rest_api_rate_limited` with `Retry-After`
- [ ] Reads work again after the minute is up
- [ ] Writes have their own count: a write still works while reads are limited
- [ ] `0` means no limit, and a blank box restores the default on save
- [ ] Set **Failed API Key Limit** to `2`: the 3rd wrong key returns `429`, and so does the correct
      key from that IP address until the minute is up

Put the limits back afterwards.

---

## 10. Audit log

Open **WP Membership → Settings → Advanced Settings** and view the debug log. Every authenticated
request should have left a line like:

```
SWPM REST API: GET /swpm/v1/members (scope: read, authenticated via: api_key)
SWPM REST API: member record created (ID: 14).
```

- [ ] Reads and writes are both logged
- [ ] Rejected keys are logged as failures
- [ ] **No API key or password appears anywhere in the log**

That last one is worth grepping for explicitly.

---

## 11. Key rotation

Tick **Regenerate API Key**, save, then retry a request with the old key.

- [ ] Old key returns `401`
- [ ] New key works

---

## Next: automated tests

The manual pass above is what the PHPUnit suite should eventually encode. The tests will live in
the `swpm-rest-api-dev` folder beside the add-on, so they stay out of the deployed zip, and run in
the main plugin's Docker setup with the add-ons repo mounted. They will call the routes in-process
with `rest_get_server()->dispatch( $request )`, so no web server is needed.

---

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `404 rest_no_route` on every route | Add-on not active, or permalinks need flushing (**Settings → Permalinks → Save**) |
| `401` with a key you just copied | Trailing whitespace in the header, or the key was regenerated |
| `403 swpm_rest_api_disabled` | "Enable REST API" is unticked |
| `403 swpm_rest_api_write_disabled` | "Allow write access" is unticked |
| `429 swpm_rest_api_rate_limited` | A rate limit was reached; see `Retry-After` |
| White screen / empty response on a write | A `wp_die()` path was reached. Capture the request and report it — the add-on is meant to prevent every one of these |
| `rest_forbidden` rather than an `swpm_` code | A WordPress-level block (security plugin, or REST API disabled site-wide) before the add-on ever runs |
