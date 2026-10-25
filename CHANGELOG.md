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
- S3 bulk (`validateS3File`, `scoreS3File`, `exportS3File`, `listS3Files`, `emailFinderS3File`, `domainSearchS3File`).
- SFTP/FTP bulk (`validateSFTPFile`, `scoreSFTPFile`, `emailFinderSFTPFile`, `domainSearchSFTPFile`, `exportSFTPFile`). The server, path and protocol come from your ZeroBounce settings. `validateSFTPFile`'s `file_name` is optional; `exportSFTPFile` takes `export_path` rather than `export_bucket`.
- `validate` accepts `domainInfo`, `greylistProcessing`, and `callbackUrl` on `ZBValidateOptions`. `callbackUrl` needs `greylistProcessing = true` and is rejected without it, and when blank, before a request is sent.
- `ZBValidateResponse` carries `domainWebsiteExists`, `domainRegistrantCompanyName`, and `message`. `message` is read from the API's `Message` key, returned while greylist processing is still running.
- Inbox Placement Tester on `https://bulkapi.zerobounce.net/api/ipt`: `getInboxPlacementProviders`, `getInboxPlacementTestTypes`, `createInboxPlacementTest`, `getInboxPlacementTest`, `listInboxPlacementTests`, `deleteInboxPlacementTest`, `getInboxPlacementWebhook`, `setInboxPlacementWebhook`.
- `initialize` takes optional third and fourth arguments to override the list evaluator and Inbox Placement Tester base URLs.
- `initialize` takes an optional fifth argument, the bulk base URL. It moves every `bulkapi.zerobounce.net` host at once -- bulk files, scoring, bulk email finder, domain search, S3/SFTP, the list evaluator and the Inbox Placement Tester -- so the client can be pointed at a test server. The third and fourth arguments still override their own host.
- `ZeroBounceWarmup`, a client for the Warmup API. It is a separate product with its own host (`https://warmup-api.zerobounce.net`), its own team-scoped key sent in an `API-Key` header, and all 26 documented endpoints, including the seed-list and statistics CSV downloads.
- `getCredits`, `getApiUsage`, `getActivity`, `scoreEmail`, `findEmail` and `findEmailFormat` take a trailing `$usePost`. The API accepts the same parameters form-encoded, which keeps the key out of request logs.
- `getApiUsage` accepts a `YYYY-MM-DD` string as well as a `DateTime`.
- `ZBGetFileOptions::$stream` writes a result file to disk in chunks instead of holding it in memory. The bytes land in a temporary file beside the download path and move into place only once the transfer finishes.
- `ZBApiException::getStatusCode()` returns the HTTP status of a failed response, so callers can tell a Cloudflare 403 from an API 400/404.
- A regional `baseUrl` paired with the global bulk host raises an `E_USER_WARNING` once per client: ZeroBounce documents no regional host for bulk files, the list evaluator, S3/SFTP or the Inbox Placement Tester.
- An opt-in live API suite (`composer run test:live`). It is excluded from `composer test` and from CI, needs keys in the environment, and only spends credits or changes the account behind `ZEROBOUNCE_LIVE_SPEND=1` and `ZEROBOUNCE_LIVE_CHANGES=1`.

### Changed

- List evaluator requests use their own base URL (`https://bulkapi.zerobounce.net/v2/listevaluator`) instead of building the path from the bulk base, and the file id is URL-encoded as one path segment. `sendEvaluatedFile` stays on the bulk base.

- API requests time out after 120 seconds.
- Each `ZeroBounce` instance keeps its own API base URL.
- `validateBatch` sends `timeout` as a JSON string. Sent as a number, the live API rejects the whole batch with an account-level error.
- The Inbox Placement Tester authenticates with an `Authorization: Bearer` header instead of an `api_key` parameter, and `listInboxPlacementTests` calls `GET /tests`. `GET /test` answers "Method \"GET\" not allowed".
- `sendEvaluatedFile($fileId)` posts the list evaluator's `file_id` as a form field. The endpoint rejects a file upload with "file_id: This field is required". `sendEvaluatedFileStream` is gone.
- The S3 and SFTP/FTP jobs require `return_url`, and `file_name` is required on all of them. The API answers "This field is required" otherwise. That now includes `validateS3File` and `scoreS3File`, which share the same checks as the other six jobs.
- `has_header_row` is optional on every S3 and SFTP/FTP job. `emailFinderS3File`, `domainSearchS3File`, `validateSFTPFile`, `scoreSFTPFile`, `emailFinderSFTPFile` and `domainSearchSFTPFile` used to refuse a job without it, which the API accepts.
- `exportS3File`'s `$exportBucket` is optional. Left out, the API exports to the bucket in your ZeroBounce settings, the way `exportSFTPFile` falls back to the configured path.
- `findEmail`'s `$firstName` is optional. Only `domain` or `companyName` is required; without a first name the API answers with the domain's format. `findEmailFormat` and the deprecated `guessFormat` are unchanged.
- `sendEmailFinderFile` and `sendEmailFinderFileStream` require `firstNameColumn` or `fullNameColumn`. The endpoint needs a name to search for, and only `domainColumn` was checked.
- `_deleteFile` is private. It is the internal helper behind `deleteFile` and `scoringDeleteFile`, which are unchanged.
- `getListEvaluatorResult` returns a `ZBListEvaluatorResponse` instead of a plain array. Everything the API sent is still available on `->data`.
- Every HTTP call goes through cURL. The `http://` stream wrapper is no longer used.

### Fixed

- Four `ZBApiUsageResponse` counters were never populated and stayed `null` for every caller. The base class converts `snake_case` to `camelCase`, which turns `sub_status_antispam_system`, `sub_status_greylisted`, `sub_status_unroutable_ip_address` and `sub_status_mail_server_did_not_respond` into names that do not match the declared properties (`$subStatusAntiSpamSystem`, `$subStatusGreyListed`, `$subStatusUnRoutableIpAddress`, `$subStatusMailServerDidNotResponse`). The four are now mapped explicitly; the property names are unchanged, so no caller breaks.
- `ZBApiUsageResponse` gained `$subStatusLeadingPeriodRemoved` and `$subStatusAliasAddress`, two counters the API returns that were silently discarded.

- The HTTP status of a `GET` is read correctly. It was always recorded as 0, because the stream wrapper reports it only through PHP's predefined response-header variable, which the engine makes visible to the function that called `file_get_contents` and to no one else; an error status therefore never reached the response check and a 404 from the list evaluator came back as a successful result.
- `{"success": false}` on an HTTP 200 raises instead of being returned as a result. The API answers an unknown bulk file id that way.
- `getApiUsage` formats its dates. A `DateTime` was interpolated whole, so the request carried a locale-formatted timestamp that the API rejects and Cloudflare can block.
- `validateS3File`, `scoreS3File`, and `exportS3File` reject an empty API key before a request is sent.
- `scoreS3File` sends `return_url`. It was never added to the form, so a caller's `return_url` was dropped and the API refused the job with "Provided return URL is not permitted: None".
- A custom `baseUrl` is used as given. Any URL that was not one of the three production constants was silently replaced with `https://api.zerobounce.net/v2`, so a client aimed at a mock or regional host talked to production instead. A base URL that is not `https://` now throws.
- An error body whose JSON carries a `detail` string (the Inbox Placement Tester, Django REST framework) raises with that text.
- The Inbox Placement Tester's documentation says the key goes in an `Authorization: Bearer` header, which is what the code sends. It claimed the key went on the query string.
- `validateSFTPFile`'s documentation no longer says `file_name` can be left out. The API requires it.

- A 403 HTML "Access denied" page is a short `ZBException`. An HTML page with status 200 is an error as well, including `getFile`, `emailFinderGetFile`, and `domainSearchGetFile`, instead of a saved CSV.
- Empty and non-JSON bodies are a short error. A repeated `Error:` / `[Error]:` prefix is removed.
- `validateBatch` throws when the API returns an account-level error for address `all` (invalid API key or no credits). An error for one address stays on the successful result.
- `has_header_row` is sent as `false` when the caller sets it to false, on `sendFile`, `scoringSendFile`, bulk email finder, and domain search.
- Column `0` is sent. That includes the required email and domain columns and optional name, gender, and IP columns. `null` still omits an optional column. `validate` sends IP address `0`. A filter `value` of `0` is sent.
- `validate` rejects a timeout outside 3–60 seconds, and `validateBatch` rejects a timeout outside 10–120 seconds, before a request is sent.

### Security

- API requests do not follow redirects, so a 3xx response cannot send the API key to another host.
- Exception messages no longer include the raw HTML access-denied page.
- Every base URL, and every URL a request is sent to, must be `https://`.
- An API key with surrounding whitespace or control characters is rejected, because the transport would otherwise quote it unredacted in its own error.
- A file or test id of `.` or `..` is rejected rather than walking the request out of its endpoint.
- The Warmup SMTP test masks the username and password out of every error it raises.

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
