# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- A 2xx body that is strict JSON but the wrong shape for the call throws `ZBApiException` with status 200 and the message "ZeroBounce returned JSON of an unexpected shape" on every `ZeroBounce` call: an array (`[]`, `[{...}]`) where an object is expected, an object where `listFilters()` expects an array, an object without a `files` array from `listS3Files()`, and an `email_batch` or `errors` in `validateBatch()` that is not an array of objects. Up to 3.0.1 these were returned as results with default fields (`json_decode` reads `[]` and `{}` alike). A scalar or `null` (`0`, `"x"`) still throws with status 200, now with that message rather than "not valid JSON".
- A streamed result-file download whose JSON body is longer than the 64 KiB sniff window throws `ZBApiException` with the response's status and saves nothing, under any content type. Up to 3.0.1 a JSON error body over that size under a CSV content type was saved as the result file.
- A blank 2xx body (spaces, tabs or newlines only) on a `ZeroBounceWarmup` JSON call or on `deleteInboxPlacementTest()` throws `ZBApiException` with status 200: it is not JSON. Only a 204 or a 200 with no body at all is the documented empty success. Up to 3.0.1 a blank body was treated as that empty success too.
- A streamed result-file download (`ZBGetFileOptions::$stream`) whose file cannot be written, for example on a full disk, throws `ZBException("Could not write file")` and leaves nothing at the download path. Up to 3.0.1 the failed writes were ignored and the call returned success with a truncated file.
- A download without streaming writes to a temporary file in the same folder and moves it into place only when the write succeeds, as a streamed download does. When writing fails (a full disk, a read-only folder) it throws `ZBException("Could not write file")`, leaves no partial file, and leaves a file already at the download path as it was. Up to 3.0.1 it threw but left the partial file at the download path, having overwritten the file that was there. Downloads are now created with the usual mode (`0666` less the umask); streamed downloads were `0600` up to 3.0.1.
- `ZeroBounceWarmup::downloadSeeds()` and `downloadStatistics()` throw `ZBApiException` (with the HTTP status) for a JSON object or array under a CSV content type (such as `text/csv` with `{"error": "..."}`). Up to 3.0.1 only a JSON content type was rejected, so that body was returned as the CSV text.
- `ZeroBounceWarmup::addSendingAccount()` parses `$managedSending['weekdays_only']` strictly, as `$ignoreConflict`: `true`/`false`, `"true"`/`"false"` (any case) and `1`/`0` are sent as a JSON boolean, and anything else throws `ZBMissingParameterException` ("weekdays_only must be true or false") before a request is sent. Up to 3.0.1 the value was sent as given, so `"false"` went out as the string `"false"`.
- `ZeroBounceWarmup::downloadSeeds()` and `downloadStatistics()` throw `ZBApiException` (with the HTTP status) for an HTML page on a 200, such as a proxy's "Access denied" page, as the Python SDK does. Up to 3.0.1 the page was returned as the CSV text.
- Every `ZeroBounceWarmup` method that answers in JSON throws `ZBApiException` with status 200 for a `text/html` response, even when its body parses as JSON, as `ZeroBounce` does. Up to 3.0.1 such a body was returned as the result.
- `initialize()` is all-or-nothing: every argument is checked before any is stored, so a call that throws (an `http://` URL) leaves the client exactly as it was. Up to 3.0.1 a bad list evaluator, Inbox Placement or bulk URL threw after the new key and base URL had already been applied.
- `deleteInboxPlacementTest()` returns a `ZBRawJsonResponse` with empty `data` when the API answers with an empty 2xx (204 No Content), as the Go SDK does. Up to 3.0.1 it threw `ZBApiException` ("Empty response"). The other Inbox Placement calls still treat an empty body as an error.
- `ZeroBounceWarmup::addSendingAccount()` parses `$ignoreConflict` strictly, as `ZeroBounce` parses its boolean options: `true`/`false`, `"true"`/`"false"` (any case) and `1`/`0` are accepted, and anything else throws `ZBMissingParameterException` ("ignore_conflict must be true or false") before a request is sent. Up to 3.0.1 the value was cast with `(bool)`, so `"false"` was sent as `true`.
- `sendFile()`, `sendFileStream()`, `scoringSendFile()` and `scoringSendFileStream()` take an optional last argument, `$removeDuplicate`, sent as the documented `remove_duplicate` field when it is not `null` and parsed strictly like the other booleans. Up to 3.0.1 the field could not be sent, so the API's default (remove duplicates) always applied. Nothing changes for callers that leave it out.
- `ZeroBounceWarmup::addSendingAccount()` accepts only an `int` or `float` for `$managedSending['timezone_offset']`, finite and between -11 and 14; anything else throws `ZBMissingParameterException` ("timezone_offset must be -11 to 14") before a request is sent. Up to 3.0.1 a numeric string such as `'5.5'` or `'1e1'` passed and was sent as a JSON string, and `NAN` passed and made `json_encode()` fail, so the request was sent with the body `false`.
- On PHP 7 (checked on 7.0) a response cut short after its header (fewer bytes than `Content-Length`) was taken as complete: PHP 7's `curl_exec()` returns the partial body instead of `false` for curl's "transfer closed" error. Every request now treats any curl error as a transport failure, as PHP 8 already did: `ZeroBounce` throws `ZBException` with curl's message, a streamed download fails and leaves no file, and `ZeroBounceWarmup` throws `ZBApiException` with status 0. Up to 3.0.1 a cut-short JSON body that still parsed, or a cut-short result file, was returned as a success on PHP 7. PHP 8.4 and 8.5 were not affected.

### Security

- Redaction is closed under encoding. After the known spellings of the API key, the Warmup key and the SMTP username and password are masked, every exception message built from a response or transport error is decoded up to four layers deep (the SDK's own request encoding plus three echo layers), in any order (URL-decoding, `+` as a space, JSON unescaping including `\/` and `\uXXXX`); if any decoded view still holds a secret, the message is replaced by "ZeroBounce returned an error; the response body was omitted because it contained a credential." (`ZBSecret::CREDENTIAL_OMITTED_MESSAGE`). Up to 3.0.1 a key URL-encoded twice (a server echoing a URL-encoded copy of a form body) came back unmasked, for example from `validate()` with `$usePost`, `sendEvaluatedFile()`, the S3/SFTP jobs and the filters.
- The stack-trace scrub of `ZBException` uses the same decode check: a string argument that still holds a secret in a decoded view is replaced by `***` whole, and an object argument by its class name (as an exception argument already was). Up to 3.0.1 a key twice JSON-escaped with mixed slash escaping (a server quoting the JSON request body, which PHP sends with `\/`, without escaping `/`) stayed in the arguments of `validateBatch()`'s error trace, and the batch response object passed to the account-level error check kept the text of an `"all"` error, key included.
- No exception thrown by `ZeroBounce` carries the API key when a response body quotes it, whatever the HTTP status: a `{"error": "Invalid API key <key>"}` or `{"success": false, "message": ...}` body, a `validateBatch()` account-level (`"all"`) error, an HTML page's title, a result-file download's error, or a proxy's error page quoting the request URL. Up to 3.0.1 these messages were redacted only on the plain GET requests and on `sendFile()`, `scoringSendFile()`, their stream forms, `getFile()` and `scoringGetFile()`; the `$usePost` forms, `validateBatch()`, the email finder, domain search and list evaluator uploads, `emailFinderGetFile()`, `domainSearchGetFile()`, `addFilter()`, `deleteFilter()`, the S3 and SFTP jobs, `sendEvaluatedFile()` and the Inbox Placement Tester passed them on unchanged. The key is now masked (raw, URL-encoded and JSON-escaped) where every JSON response is read, so each request path is covered.
- `ZeroBounceWarmup` also masks the Warmup API key and the SMTP username and password of `testSmtpConfiguration()` in their JSON-escaped forms (`"` as `\"`, `/` as `\/` or `/`, non-ASCII as `\uXXXX` or as is) and URL-encoded with `+` for spaces, in every exception message built from a response body. Up to 3.0.1 only the raw and `rawurlencode()` forms were masked, so a key or password containing `"` or `/` came back unmasked when a JSON body quoted it.
- The API key, the Warmup key and the SMTP username and password are also masked in the URL-encoded form of their JSON-escaped text (`urlencode()` and `rawurlencode()`, with `/` as `\/` or as is), which a server echoing a URL-encoded copy of a JSON request body returns, and JSON-escaped twice (a JSON error body quoting the JSON request body). Up to 3.0.1 a secret containing `"`, `/` or `\` came back unmasked in those forms.
- No exception thrown by `ZeroBounce` or `ZeroBounceWarmup` carries the API key or the SMTP username and password in its stack trace. With `zend.exception_ignore_args` off (the official Docker images and `php.ini-development` leave it off, and PHP 7.0 to 7.3 have no such switch) PHP records every frame's arguments, so up to 3.0.1 the request URL with `api_key`, the parameter arrays, the `API-Key` header, the body passed to a helper and the original exception handed to the method that rebuilds it were all visible through `getTrace()`, `getTraceAsString()`, the string form, `print_r()`, `var_export()` and `serialize()` of the exception, on error statuses and transport failures alike. `ZBException` now masks every secret a live client holds (raw, URL-encoded and JSON-escaped) in its trace as it is built, and its previous exceptions' traces too, and replaces an exception passed as an argument with its class name; `testSmtpConfiguration()` registers the username and password for the duration of the call. Checked on PHP 7.0, 8.4 and 8.5.

## [3.0.1] - 2026-11-11

### Fixed

- PHP 8.5: requests no longer raise `E_DEPRECATED` ("Function curl_close() is deprecated since 8.5"). `curl_close()` is called only before PHP 8.0, where it still frees the handle; from 8.0 it does nothing.
- `validateBatch` with an account-level error (an `"email_address": "all"` entry, such as "Invalid API Key or your account ran out of credits") on an HTTP 200 throws `ZBApiException` with `getStatusCode()` 200, like the other calls and the other SDKs. 3.0.0 threw a plain `ZBException` with no status; `catch (ZBException $e)` still catches it.
- `ZeroBounceWarmup` requests have the same 30-second connect timeout as the `ZeroBounce` client. In 3.0.0 they had only the 120-second overall limit.
- `validate` without an IP address leaves `ip_address` out of the request instead of sending an empty `ip_address=`, as the Python SDK does. An IP address of `"0"` is still sent.
- `__toString()` of `ZBListEvaluatorResponse` shows every field (the percentages, `abuse`, `doNotMail`, `spamTrap`, `errorMessage` and `data`, not only `fileId`, `status` and `progress`); `ZBBatchValidateResponse` shows each result as its full `ZBValidateResponse` text and the `errors` list; `ZBGuessFormatResponse` adds `otherDomainFormats`; `ZBRawJsonResponse` shows its `data` instead of `ZeroBounce\SDK\ZBRawJsonResponse{}`. `ZBBatchValidateResponse`'s text now starts `ZBBatchValidateResponse{emailBatch=[` instead of `ZBBatchValidateResponse[`.

### Security

- The API key no longer appears in the stack trace of an exception thrown by `ZeroBounce::initialize()` or `new ZeroBounceWarmup()` (an `http://` base URL, a key with surrounding whitespace). PHP prints the first 15 characters of each call argument in a trace unless `zend.exception_ignore_args` is on, which it is in `php.ini-production` but not by default. The key parameters are marked `#[\SensitiveParameter]` (PHP 8.2+), and on older PHP the SDK replaces the key in the trace with `***`.
- `var_export()` and `serialize()` of a `ZeroBounce` or `ZeroBounceWarmup` client no longer contain the API key (3.0.0 hid it from `var_dump()` and `print_r()` only). A client restored with `unserialize()` therefore has no key: `ZeroBounce` throws `ZBMissingApiKeyException` until `initialize()` is called again, and `ZeroBounceWarmup` throws `ZBMissingApiKeyException` on every call.

### Changed

- Development only: PHPUnit fails on deprecations (`failOnDeprecation`), so a PHP deprecation in the SDK fails CI. `composer.lock`'s content hash matches `composer.json` again after the 3.0.0 version bump (no locked package changed). The tests that start a local PHP server stop it reliably, so `phpunit | tail` no longer hangs on Debian-based images.

## [3.0.0] - 2026-11-12

### Breaking

- **Breaking:** boolean request options are parsed strictly. `true`/`false`, `"true"`/`"false"` (any case) and `1`/`0` are accepted; anything else throws `ZBMissingParameterException` ("`<name>` must be true or false") before a request is sent. Up to 2.2.0 any truthy value was sent as `true`, so `$options->activityData = 'false'` sent `true`. Affects `ZBValidateOptions` (`activityData`, `verifyPlus`, `domainInfo`, `greylistProcessing`) for `validate` and `validateBatch`, `ZBGetFileOptions::$activityData`, the `$hasHeaderRow` and `$allowPhase2` arguments of the file uploads, and the S3/SFTP job options (`has_header_row`, `remove_duplicates`, `remove_duplicate`, `allow_phase_2`). The caller's `ZBValidateOptions` object is not modified.
- **Breaking:** `ZBValidateResponse::$mxFound`, `$catchallDomain` and `$freeEmail`, `ZBActivityResponse::$found`, `ZBListEvaluatorResponse::$abuse`, `$doNotMail` and `$spamTrap`, and `$success` on the file responses are `bool` or `null`, as documented. Up to 2.2.0 they kept the API's string, so `"false"` was truthy (`if ($response->mxFound)` was true for a domain with no MX record). A value that is not a boolean (such as `"unknown"`) is now `null`.

### Fixed

- A 2xx `{"error_message": "..."}` with no result fields throws, like a bare `error`/`Error` body. 2.2.0 returned it as the result.
- `guessFormat` throws for a body made only of message fields (the API's answer to a dead key, `{"Message": "Invalid API key or your account ran out of credits"}`), as `findEmail` and `findEmailFormat` already did. 2.2.0 returned it as an empty result.
- A streamed download (`ZBGetFileOptions::$stream`) no longer hangs on a server that stops sending: it is abandoned after 120 seconds without data (`CURLOPT_LOW_SPEED_TIME`), still with no overall limit, so large files finish. Every request of the `ZeroBounce` client now has a 30-second connect timeout.
- A connection failure or timeout throws with cURL's own message (for example `Failed to connect to ... Could not connect to server`) instead of `Empty response from ZeroBounce (HTTP 0)`, and a redirect throws `ZeroBounce answered with a redirect (HTTP 302), which is not followed` instead of `Empty response from ZeroBounce (HTTP 302)`.
- `ZBValidateResponse::$subStatus` from the API is now checked against the known values; the check looked for a `sub_status` property that never exists. A status or sub-status this version does not know is kept as the API sent it; `$status` used to become `""`.
- `ZBValidateResponse::__toString()` includes `activeInDays` and `activeFirstSeen`, and prints flags as `true`/`false`/`NULL`.
- `var_dump()` and `print_r()` of a `ZeroBounce` or `ZeroBounceWarmup` client show `***` instead of the API key (`__debugInfo`).

### Changed

- Errors reported on a 2xx response (a dead key's `error`, `Error` or `error_message`, and a message-only body from the email finder and domain search) throw `ZBApiException` with `getStatusCode()` 200. They threw a plain `ZBException`, which `ZBApiException` extends, so existing `catch (ZBException $e)` blocks are unaffected.
- `composer.json` has a fuller description, the keywords `zerobounce`, `email-validation`, `email-verification`, `email-finder`, `domain-search` and `sdk` (instead of `zero`, `bounce`, `sdk`), a homepage, and `support` links to https://github.com/zerobounce/zero-bounce-php-sdk-setup. The Packagist dist now holds only `src/`, `README.md`, `LICENSE`, `CHANGELOG.md` and `composer.json`.
- Development only: `composer.lock`'s content hash matches `composer.json` again (no locked package changed).

## [2.2.0] - 2026-11-11

This release has breaking changes. Read the **Breaking** entries under Changed before you upgrade from 2.1.x.

### Added

- `ZeroBounce::create()` returns a new client. `Instance()` stays a process-wide singleton.
- `validate` and `validateBatch` take `ZBValidateOptions` with `timeout`, `activityData` and `verifyPlus`. `validate` also takes `usePost` (form-encoded `POST /validate`). A single `timeout` must be 3–60 seconds and a batch `timeout` 10–120. Both ranges are checked before a request is sent. `validateBatch` sends `timeout` as a JSON string, because the live API rejects a number.
- `ZBValidateResponse` reads `active_in_days` and `active_first_seen`.
- `ZBValidateSubStatus::AiAgentMailbox` (`ai_agent_mailbox`).
- `scoreEmail` (`GET /v2/scoring`) and `scoreEmailPost` (form-encoded `POST /v2/scoring`).
- Bulk email finder: `sendEmailFinderFile`, `sendEmailFinderFileStream`, `emailFinderFileStatus`, `emailFinderGetFile` and `emailFinderDeleteFile`. An upload needs `domainColumn` and either `firstNameColumn` or `fullNameColumn`.
- Bulk domain search: `sendDomainSearchFile`, `sendDomainSearchFileStream`, `domainSearchFileStatus`, `domainSearchGetFile` and `domainSearchDeleteFile`.
- List evaluator on its own base URL, `https://bulkapi.zerobounce.net/v2/listevaluator`: `sendListEvaluatorFile`, `sendListEvaluatorFileStream` and `getListEvaluatorResult`. The file id is URL-encoded as one path segment. `getListEvaluatorResult` returns a `ZBListEvaluatorResponse`, and everything the API sent is on `->data`. `sendEvaluatedFile($fileId)` submits a finished evaluation for validation. It posts the list evaluator's `file_id` as a form field to `/v2/send-evaluated-file/` on the bulk host.
- Filters: `addFilter`, `listFilters` and `deleteFilter`.
- S3 bulk: `validateS3File`, `scoreS3File`, `exportS3File`, `listS3Files`, `emailFinderS3File` and `domainSearchS3File`.
- SFTP/FTP bulk: `validateSFTPFile`, `scoreSFTPFile`, `emailFinderSFTPFile`, `domainSearchSFTPFile` and `exportSFTPFile`. The server, path and protocol come from your ZeroBounce settings.
- Every S3 and SFTP/FTP file job (validate, score, email finder, domain search) requires `file_name` and `return_url`. A job without them is refused before it is sent. `has_header_row` and `remove_duplicates` are optional. `exportS3File` takes an optional `$exportBucket` and `exportSFTPFile` an optional `$exportPath`. Without them, the API uses the bucket or path in your ZeroBounce settings.
- `validate` accepts `domainInfo`, `greylistProcessing` and `callbackUrl` on `ZBValidateOptions`. `callbackUrl` needs `greylistProcessing = true` and must not be blank. Either problem is rejected before a request is sent.
- `ZBValidateResponse` carries `domainWebsiteExists`, `domainRegistrantCompanyName` and `message`. `message` is read from the API's `Message` key, which the API returns while greylist processing is still running.
- Inbox Placement Tester on `https://bulkapi.zerobounce.net/api/ipt`: `getInboxPlacementProviders`, `getInboxPlacementTestTypes`, `createInboxPlacementTest`, `getInboxPlacementTest`, `listInboxPlacementTests` (`GET /tests`), `deleteInboxPlacementTest`, `getInboxPlacementWebhook` and `setInboxPlacementWebhook`. The key goes in an `Authorization: Bearer` header, never in an `api_key` parameter. An error body's `detail` text becomes the exception message.
- `initialize` takes optional third and fourth arguments to override the list evaluator and Inbox Placement Tester base URLs.
- `initialize` takes an optional fifth argument, the bulk base URL. It moves every `bulkapi.zerobounce.net` host at once, so the client can be pointed at a test server. That covers bulk files, scoring, bulk email finder, domain search, S3/SFTP, the list evaluator and the Inbox Placement Tester. The third and fourth arguments still override their own host.
- `ZeroBounceWarmup`, a client for the Warmup API. It is a separate product with its own host (`https://warmup-api.zerobounce.net`) and its own team-scoped key, sent in an `API-Key` header. The client covers all 26 documented endpoints, including the seed-list and statistics CSV downloads.
- `getCredits`, `getApiUsage`, `getActivity`, `scoreEmail`, `findEmail` and `findEmailFormat` take a trailing `$usePost`. The API accepts the same parameters form-encoded, which keeps the key out of request logs.
- `getApiUsage` accepts a `YYYY-MM-DD` string as well as a `DateTime`.
- `ZBGetFileOptions::$stream` writes a result file to disk in chunks instead of holding it in memory. The bytes land in a temporary file beside the download path and move into place only once the transfer finishes.
- `ZBApiException::getStatusCode()` returns the HTTP status of a failed response, so callers can tell a Cloudflare 403 from an API 400/404. The SDK throws a `ZBApiException` for an HTTP status above 299 and for an empty, HTML or non-JSON body. It also throws one for `{"success": false}`, a JSON error on `getFile`, and every failed Warmup request. `ZBApiException` extends `ZBException`, so existing `catch (ZBException $e)` blocks still catch it.
- A regional `baseUrl` paired with the global bulk host raises an `E_USER_WARNING` once per client. ZeroBounce documents no regional host for bulk files, the list evaluator, S3/SFTP or the Inbox Placement Tester.
- An opt-in live API suite (`composer run test:live`). It is excluded from `composer test` and from CI and needs keys in the environment. By default it only reads, validates sandbox addresses and sends requests that are expected to fail. Spending credits needs `ZEROBOUNCE_LIVE_SPEND=1`, which also gates a complete S3 job that would start real work on an account with an S3 integration. Changing the account needs `ZEROBOUNCE_LIVE_CHANGES=1`.

### Changed

- **Breaking:** API errors now throw instead of coming back as a result. In 2.1.11 every call except `getFile` deserialized a JSON error body into the response, whatever its HTTP status. `validate` came back with `->error` set. `getActivity`, `getApiUsage`, `findEmail` and `findEmailFormat` came back with every field `null`. Now:
  - Every HTTP status above 299 throws a `ZBApiException`.
  - `{"success": false}` on an HTTP 200 throws a `ZBApiException`. That is the API's answer to an unknown bulk file id.
  - A bare `error` or `Error` body on an HTTP 200 throws a `ZBException`. That is how the API answers an invalid or drained key on most calls, for example `{"error": "Invalid API key or your account ran out of credits"}`. `findEmail` and `findEmailFormat` also throw for a body made only of `Message`, which is what they get for a dead key.
  - `validateBatch` throws when the API reports an account-level error for address `all`. An error for a single address stays on the result.

  `getCredits` still returns `-1` for an invalid key. A bare `Message` is still a result where it is one. A repeated `greylist_processing` validate answers `{"Message": "Greylist validation still in progress. Please try again later."}`.

  To migrate, wrap calls in `try { ... } catch (ZBException $e) { ... }` instead of checking `$response->error` or for empty fields. `$e->getMessage()` is the error text the API sent, with any `Error:` prefix removed. To read the HTTP status, catch `ZBApiException` first and call `getStatusCode()`.
- **Breaking:** `protected static $ApiBaseUrl` is gone. Each `ZeroBounce` instance keeps its own base URL in the protected instance property `$apiBaseUrl`. A subclass that read or wrote `self::$ApiBaseUrl` or `static::$ApiBaseUrl` must use `$this->apiBaseUrl`.
- **Breaking:** `_deleteFile` is private. It was public in 2.1.11. Call `deleteFile` or `scoringDeleteFile`, which are unchanged.
- **Breaking:** the constructor is now `ZBApiException::__construct($message = '', $statusCode = 0)`. The second argument is the HTTP status, not `Exception`'s `$code`, and there is no `$previous`. This only affects code that constructs a `ZBApiException` itself.
- `findEmail` no longer requires a first name. With `null` as `$firstName`, only `domain` or `companyName` is required, and the API answers with the domain's format. The parameter is still positional: call `findEmail('example.com', null)`. `findEmail('example.com')` alone throws an `ArgumentCountError`. `findEmailFormat` and the deprecated `guessFormat` are unchanged.
- Every HTTP call goes through cURL. The `http://` stream wrapper is no longer used.
- Request time limits changed. Every request now has a 120-second total limit, except a streamed download (`ZBGetFileOptions::$stream`), which has no overall limit. The limit includes a non-streamed `getFile`, so use `stream` for a result file that can take longer than 120 seconds to download. In 2.1.11, file uploads stopped at 30 seconds, `validateBatch` had no limit, and the other calls were bounded only by PHP's `default_socket_timeout`.

### Removed

- `src/ZBLegacyHttpResponseHeaders.php`, an internal file that read `$http_response_header` for the stream wrapper.

### Fixed

- On PHP 8.0–8.3, the HTTP status of a `GET` was lost. 2.1.11 read `$http_response_header` from an included file. That works on PHP 7, and PHP 8.4 and later used `http_get_last_response_headers()` instead. On 8.0–8.3 the included file never saw the variable. The protected `request()` returned 0, and `getFile` and `scoringGetFile` could not see an error status, so they saved any non-JSON error body as the result file. cURL reports the status on every PHP version.
- Four `ZBApiUsageResponse` counters were never populated and stayed `null` for every caller. The base class converts `snake_case` to `camelCase`. That turned `sub_status_antispam_system`, `sub_status_greylisted`, `sub_status_unroutable_ip_address` and `sub_status_mail_server_did_not_respond` into names that do not match the declared properties (`$subStatusAntiSpamSystem`, `$subStatusGreyListed`, `$subStatusUnRoutableIpAddress`, `$subStatusMailServerDidNotResponse`). The four are now mapped explicitly. The property names are unchanged, so no caller breaks.
- `ZBApiUsageResponse` gained `$subStatusLeadingPeriodRemoved` and `$subStatusAliasAddress`, two counters the API returns that were silently discarded.
- A custom `baseUrl` is used as given. In 2.1.11, any URL that was not one of the three production constants was silently replaced with `https://api.zerobounce.net/v2`. A client aimed at a mock or regional host talked to production instead. A base URL that is not `https://` now throws.
- A 403 HTML "Access denied" page is a short exception message. An HTML page with status 200 is an error as well, including on `getFile`, which used to save it as the result file.
- Empty and non-JSON bodies give a short error message. A repeated `Error:` / `[Error]:` prefix is removed.
- `has_header_row` is sent as `false` when the caller sets it to false, on `sendFile`, `sendFileStream`, `scoringSendFile` and `scoringSendFileStream`. 2.1.11 left it out.
- `sendFile` and `sendFileStream` accept an email column of `0`, and send optional name, gender and IP columns of `0`. `null` still omits an optional column. `validate` sends an IP address of `0`.

### Security

- API requests do not follow redirects, so a 3xx response cannot send the API key to another host.
- Exception messages no longer include the raw HTML access-denied page.
- The API key is replaced with `***` in exception messages from GET calls such as `validate` and `fileStatus`, and from the validation and scoring `sendFile` and `getFile` calls.
- A file name containing `"`, CR or LF is percent-encoded in a multipart upload. It can no longer break out of the `Content-Disposition` header.
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

[Unreleased]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v3.0.1...HEAD
[3.0.1]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v3.0.0...v3.0.1
[3.0.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.2.0...v3.0.0
[2.2.0]: https://github.com/zerobounce/zero-bounce-php-sdk-setup/compare/v2.1.11...v2.2.0
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
