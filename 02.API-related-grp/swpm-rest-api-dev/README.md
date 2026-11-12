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

The PHPUnit tests will live here too, in `tests/`.
