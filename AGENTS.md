# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project

`zero-bounce-php-sdk-setup` is the ZeroBounce PHP SDK, package `zero-bounce/sdk` on Packagist. Callers use `ZeroBounce::create()`, then `initialize($apiKey)` or `initialize($apiKey, $baseUrl)`. `ZeroBounce::Instance()` is a process-wide singleton. Failures throw `ZBException`. Every HTTP call goes through cURL behind `executeHttp`.

`src/ZeroBounceWarmup.php` is a separate client for the Warmup API, which is a different product with its own host and its own team-scoped key.

## Layout

- `src/ZeroBounce.php`: validation, bulk file, credits, activity, scoring. `executeHttp` is the HTTP call.
- `src/ZeroBouncePriority3.php`: bulk email finder, domain search, list evaluator, filters, S3. It is a trait on `ZeroBounce`.
- `src/ZeroBounceInboxPlacement.php`: Inbox Placement Tester. Also a trait on `ZeroBounce`.
- `src/ZeroBounceWarmup.php`: the Warmup API client. Not a trait: its own class, its own key, its own `executeHttp`.
- `src/ZBHttpError.php`: turns HTTP failures, HTML pages, and empty or non-JSON bodies into a short `ZBException`.
- `test/ZeroBounceTest.php`: PHPUnit cases with `MockZeroBounce`, which replaces `request`, `json`, `formPost`, `curl`, and `getBulkFileHttp`.
- `test/OfflineApiTest.php`: offline suite. `test/OfflineHttp.php` records every request and answers without the network.
- `test/OfflineWarmupTest.php`: the same for the Warmup client.
- `live/live_api.php`: the opt-in live suite. `phpunit.xml` only includes `test/`, so `composer test` and CI never run it.
- `composer.json`: the only version field, `"version": "x.y.z"`. `composer.lock` lists dependencies, not this package's version. There is no runtime version constant and no User-Agent.

## Commands

```bash
composer install
composer lint     # php -l on src, test and live
composer test     # phpunit
composer audit    # check dependencies against the security advisories
```

`php -l` is the syntax check. There is no separate typechecker. CI (`.github/workflows/sdk_ci.yml`, PHP 8.2) installs dependencies, runs `php -l`, then `vendor/bin/phpunit`.

The live suite is opt-in and never runs in CI:

```bash
export ZEROBOUNCE_API_KEY=...          # validation API
export ZEROBOUNCE_WARMUP_API_KEY=...   # Warmup API
composer run test:live                 # free calls only
ZEROBOUNCE_LIVE_SPEND=1 composer run test:live     # also spends credits
ZEROBOUNCE_LIVE_CHANGES=1 composer run test:live   # also changes the account, undone in cleanup
```

Keys come from the environment only. Never write one into a file, a commit, a log, a fixture or a message.

## Conventions

- **Only endpoints documented at https://www.zerobounce.net/docs/.** Do not add a method for an endpoint that the public docs do not describe, even if it exists on the server. Check before concluding something is undocumented: the S3 bulk endpoints live under https://www.zerobounce.net/docs/api-integrations/amazon-s3-bulk-api, not the main API reference.
- **Where the live API and the docs disagree, follow the API and say so in a comment at the call site.** The ones found so far: `validatebatch` takes `timeout` as a JSON *string*; the Inbox Placement Tester authenticates with `Authorization: Bearer`, not `api_key`; its listing is `GET /tests`, not `/test`; `send-evaluated-file` takes `file_id` as a form field, not a file upload; the S3 and SFTP jobs require `return_url` and `file_name`; the Warmup `check-domain-dmarc` takes an email address, not a domain; and a failed SMTP login in `test-smtp-configuration` comes back as an HTTP 500.
- **The Inbox Placement Tester takes the API key as an `Authorization: Bearer` header and JSON bodies with camelCase fields.** Only fields the caller set are sent. Its error bodies use `detail`, and its list errors are top-level JSON arrays of strings.
- **`{"success": false}` on an HTTP 200 is a failure.** The API answers an unknown bulk file id that way. `ZBHttpError::throwIfSuccessIsFalse` turns it into a `ZBApiException` carrying the status.

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
- **`executeHttp` does not follow redirects and only talks https.** `CURLOPT_FOLLOWLOCATION` is false and `CURLOPT_MAXREDIRS` is 0; a 3xx is an error. Do not reintroduce the `http://` stream wrapper: it reports its status only through PHP's predefined response-header variable, which the engine shows to the function that called `file_get_contents` and to no one else, so the status came back 0 and an error looked like a result. 8.5 deprecates reading it at all.
- **Errors never carry the key.** `redactApiKey` masks it in every message, and `ZBApiException::getStatusCode()` carries the HTTP status so callers can tell a Cloudflare 403 from an API 400/404.
- **Tests override `executeHttp`.** It is `protected`. Subclass `ZeroBounce` in the test. Do not call the live API from `composer test`. Do not commit `vendor/`, `.env`, or `.phpunit.cache`. `composer.lock` is committed.
- Match the surrounding style. The library still runs on PHP 7.0: no scalar property types, `fn()`, or `str_contains` in `src/`. Do not use an implicitly nullable parameter (`array $x = null`); 8.4 deprecates it and `?array` needs 7.1.

## Changelog

`CHANGELOG.md` follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

- Add every user-visible change to `## [Unreleased]` in the same commit as the change.
- Group entries under these headings, in this order, omitting empty ones: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
- Write for SDK users: name the method, option, or behaviour, and say what changed. Leave out CI-only changes, internal refactors, and fixes to code that was never released.
- At 2.y.z, a breaking change bumps the major version, a new method or option bumps the minor version, and fixes alone bump the patch version.

## Releasing

Publishing and pushing tags need a maintainer's explicit approval. Do not bump the version, tag, or publish unless they asked.

**Run the live suite by hand before every release.** The offline suite proves the SDK sends what we think it sends; only the live suite proves the API accepts it. Run it with real keys, including `ZEROBOUNCE_LIVE_SPEND=1` and `ZEROBOUNCE_LIVE_CHANGES=1`, and do not release on a failure.

1. Move `[Unreleased]` entries under `## [x.y.z] - YYYY-MM-DD`, leave an empty `[Unreleased]` section, and update the compare links at the bottom of `CHANGELOG.md`.
2. Set the same version in `composer.json` (`"version"`). The publish workflow fails if it differs from the tag. That is the only version field.
3. `composer lint` and `composer test`.
4. Tag `vx.y.z` on that commit and run the Publish workflow with that tag. It creates the GitHub release and tells Packagist to update `zero-bounce/sdk`.
