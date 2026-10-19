# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ZeroBounce::create()` returns a new client. `Instance()` stays a process-wide singleton.
- `validate` and `validateBatch` take `ZBValidateOptions`: `timeout`, `activityData`, `verifyPlus`, and `usePost` (form-encoded `POST /validate`). Single `timeout` is 3–60 seconds. Batch `timeout` is 10–120.
- `ZBValidateResponse` reads `active_in_days` and `active_first_seen`.
- `scoreEmail` (`GET /v2/scoring`) and `scoreEmailPost` (form-encoded `POST /v2/scoring`).
- Bulk email finder (`sendEmailFinderFile`, `emailFinderFileStatus`, `emailFinderGetFile`, `emailFinderDeleteFile`, plus stream upload).
- Bulk domain search (`sendDomainSearchFile`, `domainSearchFileStatus`, `domainSearchGetFile`, `domainSearchDeleteFile`, plus stream upload).
- List evaluator (`sendListEvaluatorFile`, `getListEvaluatorResult`, `sendEvaluatedFile`, plus stream uploads).
- Filters (`addFilter`, `listFilters`, `deleteFilter`).
- S3 bulk (`validateS3File`, `scoreS3File`, `exportS3File`, `listS3Files`).

### Changed

- API requests time out after 120 seconds.
- Each `ZeroBounce` instance keeps its own API base URL.

### Fixed

- A 403 HTML "Access denied" page is a short `ZBException`. An HTML page with status 200 is an error as well, including `getFile`, `emailFinderGetFile`, and `domainSearchGetFile`, instead of a saved CSV.
- Empty and non-JSON bodies are a short error. A repeated `Error:` / `[Error]:` prefix is removed.
- `validateBatch` throws when the API returns an account-level error for address `all` (invalid API key or no credits). An error for one address stays on the successful result.
- `has_header_row` is sent as `false` when the caller sets it to false, on `sendFile`, `scoringSendFile`, bulk email finder, and domain search.
- Column `0` is sent. That includes the required email and domain columns and optional name, gender, and IP columns. `null` still omits an optional column. `validate` sends IP address `0`. A filter `value` of `0` is sent.
- `validate` rejects a timeout outside 3–60 seconds, and `validateBatch` rejects a timeout outside 10–120 seconds, before a request is sent.
- `validateS3File`, `scoreS3File`, and `exportS3File` reject an empty API key before a request is sent.

### Security

- API requests do not follow redirects, so a 3xx response cannot send the API key to another host.
- Exception messages no longer include the raw HTML access-denied page.

## [2.1.10] - 2026-06-18

### Fixed

- `validateBatch` posts to `api.zerobounce.net/v2/validatebatch` instead of `bulkapi.zerobounce.net`.

## [2.1.9] - 2026-06-10

### Added

- `ZBValidateResponse.catchallDomain`, read from `catchall_domain`.

## [2.1.8] - 2026-04-22

### Fixed

- An empty HTTP header list is treated as "no status" instead of raising a warning while reading the status code.

## [2.1.6] - 2026-04-16

### Fixed

- On PHP 8.5, reading response headers no longer touches the deprecated `$http_response_header` variable.

## [2.1.1] - 2026-04-15

### Fixed

- `getFile` still reads response headers on PHP 8.5 when `http_get_last_response_headers()` does not return an array.

## [2.1.0] - 2026-04-05

### Added

- `getFile` and `scoringGetFile` accept `ZBGetFileOptions`: `downloadType` (`phase_1`, `phase_2`, `combined`) and `activityData` (validation `getFile` only).
- `sendFile` and `sendFileStream` accept `allowPhase2`, sent as `allow_phase_2` on validation bulk sendfile only.
- `ZBFileStatusResponse.filePhase2Status`.

## [2.0.11] - 2026-03-03

### Changed

- License the project under MIT.

### Removed

- The Spanish documentation file.

## [2.0.0] - 2026-02-26

### Added

- `ZBResponse::toArray()`.
- API usage totals for `accept_all`, `mx_forward`, `alternate`, `allowed`, `blocked`, `gold`, and `role_based_accept_all`.

### Changed

- `guessFormat` emits `E_USER_DEPRECATED`. Use `findEmail` or `findEmailFormat`.
- A response that is not a JSON object or array throws `ZBException`.

## [1.2.0] - 2025-10-27

### Added

- `initialize($apiKey, $baseUrl)` with `ZBBaseUrl::API_DEFAULT_URL`, `API_USA_URL`, and `API_EU_URL`.
- `findEmail` and `findEmailFormat`.
- `ZBValidateSubStatus::AcceptAll`.

### Deprecated

- `guessFormat`. Use `findEmail` or `findEmailFormat`.

## [1.1.4] - 2023-11-26

### Fixed

- A body that is not a JSON object or array throws `ZBException` instead of being ignored.
- Response fields start as `null` until the API sets them.

### Changed

- Missing parameters, a missing API key, and other SDK failures use separate exception classes.

## [1.1.3] - 2023-09-05

### Added

- `guessFormat`.

## [1.1.2] - 2023-08-22

### Fixed

- `getFile` calls the bulk get-file path.

## [1.1.1] - 2023-08-17

### Fixed

- `sendFile` calls the bulk send-file path.

## [1.1.0] - 2023-05-17

### Added

- `validateBatch`.

## [1.0.2] - 2023-05-11

### Fixed

- A missing API response no longer raises a warning.

## [1.0.1] - 2023-04-27

### Fixed

- Removed an import that broke installation under Laravel.

## [1.0.0] - 2023-04-06

### Added

- `getActivity`.

## [0.0.4] - 2020-02-24

### Fixed

- The client runs on PHP 7.0.

## [0.0.3] - 2020-02-11

### Added

- Scoring bulk file methods: `scoringSendFile`, `scoringFileStatus`, `scoringGetFile`, and `scoringDeleteFile`.

### Changed

- The package requires PHP 7.0 or newer.

## [0.0.2] - 2019-08-05

### Changed

- Composer no longer requires the `ext-spltype` and `ext-http` extensions.

## [0.0.1] - 2019-08-05

### Added

- Initial release: `getCredits`, `getApiUsage`, `validate`, and the bulk file methods.

[Unreleased]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.11...HEAD
[2.1.10]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.9...v2.1.10
[2.1.9]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.8...v2.1.9
[2.1.8]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.6...v2.1.8
[2.1.6]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.1...v2.1.6
[2.1.1]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.0.11...v2.1.0
[2.0.11]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.0.0...v2.0.11
[2.0.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.1.4...v1.2.0
[1.1.4]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v0.0.4...v1.0.0
[0.0.4]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v0.0.3...v0.0.4
[0.0.3]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/releases/tag/v0.0.1
