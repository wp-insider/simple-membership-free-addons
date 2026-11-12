# swpm-rest-api-dev

Development tooling for the `swpm-rest-api` add-on. It sits beside the add-on folder so it stays
out of the zip the deploy workflow builds from that folder.

## Regenerate the OpenAPI file

`swpm-rest-api/docs/openapi.yaml` is generated from `swpm-rest-api/routes/api.php` and each
controller's `get_response_schema()`. Do not edit it by hand. After changing a route, its arguments
or a response field, run this from this folder (no WordPress needed):

```bash
php bin/generate-openapi.php
```

To only check that the file is current:

```bash
php bin/generate-openapi.php --check
```

The script stops with an error if a controller's response fields and its `get_response_schema()`
no longer match.

## Tests

The manual test walk-through is in `docs/testing-guide.md`.

The automated PHPUnit tests are in `tests/`. They call the API in-process through
`rest_get_server()->dispatch()`, against a real WordPress test database, with the main Simple
Membership plugin loaded.

### Run them on GitHub

In the simple-membership-free-addons repo, open **Actions**, choose **Test swpm-rest-api**, then
**Run workflow**. Pick the main plugin branch to test against (default `master`).

The workflow lints every file on PHP 7.4 and 8.5, checks that `openapi.yaml` is current, and runs
the tests on PHP 7.4 and 8.4. The main plugin is checked out from `wp-insider/simple-membership`.

### Run them locally

You need PHP 7.4 or newer, Composer, Subversion and a MySQL or MariaDB server.

```bash
# Once: install WordPress and its test library (the script is in the main plugin repo).
bash ../../../simple-membership/tests/bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest

# Once: install PHPUnit.
composer install

composer test
```

The tests expect the main plugin repo checked out beside this one
(`<workspace>/simple-membership`). Set `SWPM_PLUGIN_DIR` to the plugin folder (the one with
`simple-wp-membership.php`) if it lives elsewhere, and `WP_TESTS_DIR` if the test library is not in
the default temporary folder.

Every test runs in a database transaction that WordPress rolls back afterwards, so the tests never
leave data behind.
