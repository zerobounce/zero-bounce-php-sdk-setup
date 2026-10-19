# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `validate` takes `ZBValidateOptions`: `timeout`, `activityData`, `verifyPlus`, and `usePost` (form-encoded `POST /validate`).
- `validateBatch` takes the same options. `timeout` is 10–120 seconds.
- `scoreEmail` (`GET /v2/scoring`) and a form-encoded `POST /v2/scoring` when `usePost` is set.
- Bulk email finder (`sendEmailFinderFile`, `emailFinderFileStatus`, `emailFinderGetFile`, `emailFinderDeleteFile`).
- Bulk domain search (`sendDomainSearchFile`, `domainSearchFileStatus`, `domainSearchGetFile`, `domainSearchDeleteFile`).
- List evaluator (`sendListEvaluatorFile`, `getListEvaluatorResult`, `sendEvaluatedFile`).
- Filters (`addFilter`, `listFilters`, `deleteFilter`).
- S3 bulk (`validateS3File`, `scoreS3File`, `exportS3File`, `listS3files`).
- `ZeroBounce::create()` returns a new client. `Instance()` stays a process-wide singleton.

### Changed

- API requests time out after 120 seconds and do not follow redirects.
- A custom base URL must be `https://`, except a test may point `getFile` at `http://127.0.0.1` or `http://localhost`.

### Fixed

- A 403 HTML "Access denied" page (about 1.4 MB from the live API on `getFile`) is thrown as a short `ZBException`. An HTML page with status 200 is an error as well, including `getFile`, `emailFinderGetFile`, and `domainSearchGetFile`, instead of a saved CSV or a JSON parse.
- Empty and non-JSON bodies are a short `ZBException`. The message is no longer raw parser text, and a repeated `Error:` / `[Error]:` prefix is removed.
- `validateBatch` throws when the body is an account-level failure (HTTP 200, error for address `all`, such as an invalid API key or no credits). An error for one address stays on the returned result.
- `sendFile` and `scoringSendFile` send `has_header_row` and `remove_duplicate` as `true` or `false`. Bulk email finder and domain search send `has_header_row` when it is set to false.
- Required column indexes are sent as given, including `0`. Optional name, gender, and IP columns of `0` are left out.
- `initialize('')` throws `ZBMissingApiKeyException` before a request is sent.

### Security

- Query values are percent-encoded, and redirects are disabled, so a value cannot rewrite the request URL or send the client to another host.
- Error messages no longer include the raw HTML access-denied page.

## [2.1.10] - 2026-06-18

### Fixed

- `validateBatch` posts to `api.zerobounce.net/v2/validatebatch` instead of `bulkapi.zerobounce.net`.

## [2.1.9] - 2026-06-10

### Added

- Validate responses include `catchall_domain`.

## [2.1.6] - 2026-04-16

### Fixed

- HTTP helpers no longer read the deprecated `$http_response_header` on PHP 8.5.

## [2.1.1] - 2026-04-15

### Fixed

- `getFile` reads response headers on PHP 8.5 when `http_get_last_response_headers()` is not an array.

## [2.1.0] - 2026-04-05

### Added

- `sendFile` takes `allowPhase2`, sent as `allow_phase_2` on validation bulk only.
- `getFile` and `scoringGetFile` take `ZBGetFileOptions` with `downloadType` (`phase_1`, `phase_2`, `combined`). Validation `getFile` also takes `activityData`.
- `ZBFileStatusResponse` includes the phase 2 status.

## [2.0.11] - 2026-03-03

### Changed

- License the project under MIT.

## [2.0.0] - 2026-02-26

### Changed

- Response models for credits, API usage, batch validation, file status, and email finder match the 2.0 payloads.

## [1.2.0] - 2025-10-27

### Added

- `findEmailByDomain`, `findEmailByCompanyName`, and the format helpers.
- `initialize($apiKey, $baseUrl)` selects the default, US, or EU API host.
- `ZBValidateSubStatus::AcceptAll`.

## [1.1.3] - 2023-09-05

### Added

- `guessFormat`.

## [1.1.0] - 2023-05-17

### Added

- `validateBatch`.

## [1.0.0] - 2023-04-06

### Added

- First stable release: `getCredits`, `getApiUsage`, `validate`, activity, and bulk file methods.

[Unreleased]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.11...HEAD
[2.1.10]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.9...v2.1.10
[2.1.9]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.6...v2.1.9
[2.1.6]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.1...v2.1.6
[2.1.1]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.0.11...v2.1.0
[2.0.11]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.0.0...v2.0.11
[2.0.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.1.3...v1.2.0
[1.1.3]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.1.0...v1.1.3
[1.1.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/releases/tag/v1.0.0
