# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project

`zero-bounce-php-sdk-setup` is the ZeroBounce PHP SDK, package `zero-bounce/sdk` on Packagist. Callers use `ZeroBounce::create()`, then `initialize($apiKey)` or `initialize($apiKey, $baseUrl)`. `ZeroBounce::Instance()` is a process-wide singleton. Failures throw `ZBException`. HTTP goes through cURL and `file_get_contents` behind `executeHttp`.

## Layout

- `src/ZeroBounce.php`: validation, bulk file, credits, activity, scoring. `executeHttp` is the HTTP call.
- `src/ZeroBouncePriority3.php`: bulk email finder, domain search, list evaluator, filters, S3. It is a trait on `ZeroBounce`.
- `src/ZeroBounceInboxPlacement.php`: Inbox Placement Tester. Also a trait on `ZeroBounce`.
- `src/ZBHttpError.php`: turns HTTP failures, HTML pages, and empty or non-JSON bodies into a short `ZBException`.
- `test/ZeroBounceTest.php`: PHPUnit cases with `MockZeroBounce`, which replaces `request`, `json`, `formPost`, `curl`, and `getBulkFileHttp`.
- `test/OfflineApiTest.php`: offline suite. `test/OfflineHttp.php` records every request and answers without the network.
- `composer.json`: the only version field, `"version": "x.y.z"`. `composer.lock` lists dependencies, not this package's version. There is no runtime version constant and no User-Agent.

## Commands

```bash
composer install
composer lint    # php -l on src and test
composer test    # phpunit
```

`php -l` is the syntax check. There is no separate typechecker. CI (`.github/workflows/sdk_ci.yml`, PHP 8.2) installs dependencies, runs `php -l`, then `vendor/bin/phpunit`. On PHP 8.5, `php -l src/ZBLegacyHttpResponseHeaders.php` prints a deprecation for `$http_response_header`. That file is loaded only on PHP earlier than 8.5. Leave it in place.

## Conventions

- **Only endpoints documented at https://www.zerobounce.net/docs/.** Do not add a method for an endpoint that the public docs do not describe, even if it exists on the server. Check before concluding something is undocumented: the S3 bulk endpoints live under https://www.zerobounce.net/docs/api-integrations/amazon-s3-bulk-api, not the main API reference.
- **The Inbox Placement Tester takes the API key as the `api_key` query parameter and JSON bodies with camelCase fields.** Only fields the caller set are sent. Its error bodies use `detail`.

- **One client per API key.** `Instance()` is process-wide. Do not reuse it across users or requests. Call `create()` and `initialize` for each key. The API base URL is stored on the instance.
- **Do not treat `0` as missing.** `if ($column)` and `empty($column)` drop `0`. Unset is `null` or `''`. `0` and `false` are sent. `null` omits an optional parameter.
- **Column indexes are 1-based on the API and are not converted.** The SDK forwards the integer it is given, including `0`, for required and optional columns. `sendFile` does not have `remove_duplicate`. That flag is the `remove_duplicate` key on the S3 option array.
- **Booleans are the strings `true` and `false`** on query strings, form bodies, and multipart fields. A PHP bool would be sent as `1` or `0`. On `validateBatch` JSON, `activity_data` and `verify_plus` are JSON booleans.
- **`has_header_row`:** `null` is omitted, `false` is sent as `false`, `true` is sent as `true`. The same rule applies to `allow_phase_2`, `activity_data`, and `verify_plus`.
- **`allow_phase_2` is validation `sendFile` and `validateS3File` only.** Scoring `sendFile` ignores it. `activity_data` on get-file is validation `getFile` only.
- **`validate` timeout is 3–60 seconds. `validateBatch` timeout is 10–120.** An out-of-range timeout throws before a request. `null` omits `timeout`.
- **`validateBatch` uses `api.zerobounce.net`**, not `bulkapi.zerobounce.net`. Bulk file, email finder, domain search and S3 use `bulkapi.zerobounce.net`. The list evaluator and the Inbox Placement Tester have their own base URLs (`$listEvaluatorBaseUrl`, `$inboxPlacementBaseUrl`), set by `initialize`'s third and fourth arguments; `sendEvaluatedFile` stays on the bulk base. Filters and single-email calls use the host from `initialize`.
- **`ZBBaseUrl` accepts only the default, US, and EU hosts.** Any other value falls back to the default. There is no extra `https://` check.
- **Failures throw `ZBException`.** `ZBMissingApiKeyException` and `ZBMissingParameterException` extend it. The message must be short: no raw HTML, no megabyte body, and no `Error: Error:` prefix. An account-level `validateBatch` error (`email_address` `all`) throws. A per-address error stays on `$response->errors`.
- **`getCredits` can return `Credits` `-1` for a bad key.** That is a normal response. Throw for an `error` string, an HTML page, or a non-JSON body.
- **`executeHttp` does not follow redirects.** `CURLOPT_FOLLOWLOCATION` is false. `file_get_contents` uses `follow_location` 0 and `max_redirects` 0. A 3xx response is an error.
- **Tests override `executeHttp`.** It is `protected`. Subclass `ZeroBounce` in the test. Do not call the live API from `composer test`. Do not commit `vendor/`, `.env`, or `.phpunit.cache`. `composer.lock` is committed.
- Match the surrounding style. The library still runs on PHP 7.0: no scalar property types, `fn()`, or `str_contains` in `src/`.

## Changelog

`CHANGELOG.md` follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

- Add every user-visible change to `## [Unreleased]` in the same commit as the change.
- Group entries under these headings, in this order, omitting empty ones: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
- Write for SDK users: name the method, option, or behaviour, and say what changed. Leave out CI-only changes, internal refactors, and fixes to code that was never released.
- At 2.y.z, a breaking change bumps the major version, a new method or option bumps the minor version, and fixes alone bump the patch version.

## Releasing

Publishing and pushing tags need a maintainer's explicit approval. Do not bump the version, tag, or publish unless they asked.

1. Move `[Unreleased]` entries under `## [x.y.z] - YYYY-MM-DD`, leave an empty `[Unreleased]` section, and update the compare links at the bottom of `CHANGELOG.md`.
2. Set the same version in `composer.json` (`"version"`). The publish workflow fails if it differs from the tag. That is the only version field.
3. `composer lint` and `composer test`.
4. Tag `vx.y.z` on that commit and run the Publish workflow with that tag. It creates the GitHub release and tells Packagist to update `zero-bounce/sdk`.
