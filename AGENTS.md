# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project

`zero-bounce/sdk` is the ZeroBounce PHP SDK. Callers use `ZeroBounce::create()`, call `initialize` with an API key, and read the returned response object. Failures throw `ZBException` (or `ZBMissingApiKeyException` / `ZBMissingParameterException` before a request). HTTP goes through `executeHttp` in `src/ZeroBounce.php`. `src/ZBHttpError.php` turns HTML, empty bodies, and bad JSON into a short message.

## Layout

- `src/ZeroBounce.php`: public client. `executeHttp` is the lowest HTTP call.
- `src/ZeroBouncePriority3.php`: bulk email finder, domain search, list evaluator, filters, and S3. It is a trait on `ZeroBounce`.
- `src/ZB*.php`: response models and options.
- `test/OfflineApiTest.php` and `test/OfflineHttp.php`: offline suite. `OfflineClient` overrides `executeHttp`. No network and no real API key.
- `test/ZeroBounceTest.php`: existing PHPUnit cases with a scripted response.
- `composer.json`: the package version field, `"version": "x.y.z"`. `composer.lock` lists dependencies, not this package's version. There is no runtime version constant.

## Commands

```bash
composer install
composer lint          # php -l on src and test; this is the syntax check
composer test          # phpunit
```

There is no separate typechecker. CI (`.github/workflows/sdk_ci.yml`) runs `php -l` and then `vendor/bin/phpunit`. PHPUnit collects every file in `test/`, including `OfflineApiTest.php`.

## Conventions

- **One client per API key.** `ZeroBounce::Instance()` is a process-wide singleton. Do not reuse it across users or requests. Call `ZeroBounce::create()` for each key. The offline concurrency test does this.
- **Column indexes are 1-based and are not converted.** Optional name, gender, and IP columns use `0` to mean "leave this field out". Required `email_address_column` and `domain_column` are sent as given, including `0`.
- **Optional parameters are omitted when unset.** `timeout`, `activityData`, `verifyPlus`, `allowPhase2`, and `downloadType` are not sent until the caller sets them. Explicit `false` is sent as the string `false` on query and multipart bodies, and as a JSON boolean on `validateBatch`.
- **Booleans on multipart must be the strings `true` and `false`.** A PHP bool in a multipart part becomes `1` or `0`, and the API will not treat that as a boolean.
- **`allow_phase_2` is validation `sendFile` and `validateS3File` only.** Scoring `sendFile` ignores it. `activity_data` on get-file is validation `getFile` only.
- **`validateBatch` uses `api.zerobounce.net`**, not `bulkapi.zerobounce.net`.
- **Failures throw `ZBException`.** The message must be short: no raw HTML, no megabyte body, no parser exception text, and no `Error: Error:` prefix. An account-level `validateBatch` error (`email_address` `all`) throws. A per-address error stays on the result.
- **`executeHttp` does not follow redirects** (`CURLOPT_FOLLOWLOCATION` is false, and `file_get_contents` uses `follow_location` 0 and `max_redirects` 0). A 3xx response is an error. Do not turn redirects back on.
- **Tests override `executeHttp`.** It is `protected`. Subclass `ZeroBounce` in the test. Do not call the live API from `composer test`.
- Match the surrounding style. Do not reformat unrelated code. Do not commit `vendor/`, `.env`, or `.phpunit.cache`.

## Changelog

`CHANGELOG.md` follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

- Add every user-visible change to `## [Unreleased]` in the same commit as the change.
- Group entries under these headings, in this order, omitting empty ones: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
- Write for SDK users: name the method, option, or behaviour, and say what changed. Leave out CI-only changes, internal refactors, and fixes to code that was never released.
- At 2.y.z, a breaking change bumps the major version, a new method or option bumps the minor version, and fixes alone bump the patch version.

## Releasing

Publishing and pushing tags need a maintainer's explicit approval. Do not bump the version, tag, or publish unless they asked.

1. Move `[Unreleased]` entries under `## [x.y.z] - YYYY-MM-DD`, leave an empty `[Unreleased]` section, and update the compare links at the bottom of `CHANGELOG.md`.
2. Set the same version in `composer.json` (`"version": "x.y.z"`). That is the only package version field. The publish workflow fails if it differs from the tag.
3. `composer lint` and `composer test`.
4. Tag `vx.y.z` on that commit and run the Publish workflow with that tag. It publishes `zero-bounce/sdk` to Packagist.
