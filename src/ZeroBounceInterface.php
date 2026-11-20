<?php

namespace ZeroBounce\SDK;

use DateTime;

/**
 * The public API of the ZeroBounce validation client.
 *
 * ZeroBounce is final, so it cannot be mocked or extended. Type-hint this
 * interface where your code takes a client, and pass ZeroBounce::create()
 * (or ZeroBounce::Instance()) after initialize(). Tests can then mock it:
 *
 *     $zb = $this->createMock(ZeroBounceInterface::class);
 *
 * Setup is not part of the interface: create(), Instance() and initialize()
 * stay on ZeroBounce, so code that receives a client never handles the key.
 *
 * Meant to be type-hinted and mocked, not implemented: new API methods are
 * added here in minor releases, as they are on ZeroBounce. A class of your own
 * that implements it may then have to add them.
 */
interface ZeroBounceInterface
{
    /**
     * @param string $email The email address you want to validate
     * @param string|null $ipAddress The IP Address the email signed up from (Can be blank).
     * @return ZBValidateResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     * @throws ZBException
     */
    public function validate($email, $ipAddress = NULL, $options = NULL);

    /**
     * @param array $emails List of email addresses or list of (email_address, ip_address) tuples to be validated.
     * @return ZBBatchValidateResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     * @throws ZBException
     */
    public function validateBatch($emails, $options = NULL);

    /**
     * This API will tell you how many credits you have left on your account. It's simple, fast and easy to use.
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getCredits($usePost = false);

    /**
     * @param DateTime $startDate The start date of when you want to view API usage
     * @param DateTime $endDate The end date of when you want to view API usage
     * @return ZBApiUsageResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getApiUsage($startDate, $endDate, $usePost = false);

    /**
     * @param string $email The email address to check
     * @return ZBActivityResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getActivity($email, $usePost = false);

    /**
     * Real-time AI scoring for a single email (GET /scoring).
     * @param string $email
     * @return ZBScoreEmailResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoreEmail($email, $usePost = false);

    /**
     * Real-time AI scoring for a single email (POST /scoring, form-encoded).
     * @param string $email
     * @return ZBScoreEmailResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoreEmailPost($email);

    /**
     * The sendfile API allows user to send a file for bulk email validation
     * @param string $filepath
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param int|null $firstNameColumn
     * @param int|null $lastNameColumn
     * @param int|null $genderColumn
     * @param int|null $ipAddressColumn
     * @param bool|null $hasHeaderRow
     * @param bool|null $allowPhase2 When not null, sends allow_phase_2 (validation bulk sendfile only).
     * @param bool|null $removeDuplicate When not null, sends remove_duplicate (the API's default is true).
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function sendFile(
        $filepath, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL, $lastNameColumn = NULL,
        $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL, $allowPhase2 = null,
        $removeDuplicate = null);

    /**
     * The sendfile API allows user to send a file for bulk email validation
     * @param string $filepath
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param bool|null $hasHeaderRow
     * @param bool|null $removeDuplicate When not null, sends remove_duplicate (the API's default is true).
     * @return ZBSendFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoringSendFile(
        $filepath, $emailAddressColumn, $returnUrl = NULL, $hasHeaderRow = NULL, $removeDuplicate = null);

    /**
     * Send a file for bulk email validation from stream content (e.g. in-memory CSV).
     * @param string $streamContent The file content (e.g. from stream_get_contents() or file_get_contents())
     * @param string $fileName The file name to use for the upload (e.g. "emails.csv")
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param int|null $firstNameColumn
     * @param int|null $lastNameColumn
     * @param int|null $genderColumn
     * @param int|null $ipAddressColumn
     * @param bool|null $hasHeaderRow
     * @param bool|null $allowPhase2 When not null, sends allow_phase_2 (validation bulk sendfile only).
     * @param bool|null $removeDuplicate When not null, sends remove_duplicate (the API's default is true).
     * @return ZBSendFileResponse
     */
    public function sendFileStream(
        $streamContent, $fileName, $emailAddressColumn, $returnUrl = NULL, $firstNameColumn = NULL,
        $lastNameColumn = NULL, $genderColumn = NULL, $ipAddressColumn = NULL, $hasHeaderRow = NULL,
        $allowPhase2 = null, $removeDuplicate = null);

    /**
     * Send a file for bulk email scoring from stream content.
     * @param string $streamContent The file content
     * @param string $fileName The file name to use for the upload (e.g. "emails.csv")
     * @param int $emailAddressColumn
     * @param string|null $returnUrl
     * @param bool|null $hasHeaderRow
     * @param bool|null $removeDuplicate When not null, sends remove_duplicate (the API's default is true).
     * @return ZBSendFileResponse
     */
    public function scoringSendFileStream(
        $streamContent, $fileName, $emailAddressColumn, $returnUrl = NULL, $hasHeaderRow = NULL,
        $removeDuplicate = null);

    /**
     * @param string $fileId The returned file ID when calling sendFile API
     * @return ZBFileStatusResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function fileStatus($fileId);

    /**
     * @param string $fileId The returned file ID when calling sendFile API
     * @return ZBFileStatusResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoringFileStatus($fileId);

    /**
     * The getfile API allows users to get the validation results file for the file been submitted using sendfile API
     * @param string $fileId
     * @param string $downloadPath
     * @param ZBGetFileOptions|null $options Optional download_type and activity_data (validation only).
     * @return ZBGetFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function getFile($fileId, $downloadPath, $options = null);

    /**
     * The scoring getfile API allows users to get the validation results file for the file been submitted using sendfile API
     * @param string $fileId
     * @param string $downloadPath
     * @param ZBGetFileOptions|null $options Optional download_type; activity_data is not sent.
     * @return ZBGetFileResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function scoringGetFile($fileId, $downloadPath, $options = null);

    /**
     * @param string $fileId The returned file ID when calling sendfile API.
     * @return ZBDeleteFileResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     */
    public function scoringDeleteFile($fileId);

    /**
     * @param string $fileId The returned file ID when calling sendfile API.
     * @return ZBDeleteFileResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     */
    public function deleteFile($fileId);

    /**
     * @deprecated
     * @param string $domain The domain to check
     * @return ZBGuessFormatResponse
     * @throws ZBMissingApiKeyException
     * @throws ZBException
     */
    public function guessFormat($domain, $firstName, $middleName, $lastName);

    /**
     * Search for new business email addresses
     *
     * @param string|null $domain
     * @param string|null $firstName Optional: the API returns the domain's format without it.
     * @param string|null $companyName
     * @param string|null $middleName
     * @param string|null $lastName
     * @return ZBFindEmailResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     */
    public function findEmail(
        $domain, $firstName, $companyName = null, $middleName = null, $lastName = null, $usePost = false);

    /**
     * Detect possible email patterns a specific company uses
     *
     * @param string $domain
     * @param string|null $companyName
     * @return ZBFindEmailFormatResponse
     * @throws ZBException
     * @throws ZBMissingApiKeyException
     * @throws ZBMissingParameterException
     */
    public function findEmailFormat($domain, $companyName = null, $usePost = false);

    public function sendEmailFinderFile(
        $filepath,
        $domainColumn,
        $firstNameColumn = null,
        $lastNameColumn = null,
        $middleNameColumn = null,
        $fullNameColumn = null,
        $hasHeaderRow = null
    );

    public function sendEmailFinderFileStream(
        $streamContent,
        $fileName,
        $domainColumn,
        $firstNameColumn = null,
        $lastNameColumn = null,
        $middleNameColumn = null,
        $fullNameColumn = null,
        $hasHeaderRow = null
    );

    public function emailFinderFileStatus($fileId);

    public function emailFinderGetFile($fileId, $downloadPath);

    public function emailFinderDeleteFile($fileId);

    public function sendDomainSearchFile($filepath, $domainColumn, $hasHeaderRow = null);

    public function sendDomainSearchFileStream($streamContent, $fileName, $domainColumn, $hasHeaderRow = null);

    public function domainSearchFileStatus($fileId);

    public function domainSearchGetFile($fileId, $downloadPath);

    public function domainSearchDeleteFile($fileId);

    public function sendListEvaluatorFile($filepath, $emailAddressColumn);

    public function sendListEvaluatorFileStream($streamContent, $fileName, $emailAddressColumn);

    /**
     * The evaluation's progress and results. GET /listevaluator/{file_id}/
     *
     * Everything the API sent is also on ->data, including any field this
     * version of the SDK does not name yet.
     *
     * @param string $fileId
     * @return ZBListEvaluatorResponse
     * @throws ZBException
     */
    public function getListEvaluatorResult($fileId);

    /**
     * Submit a finished evaluation for validation. POST /v2/send-evaluated-file/
     *
     * LIVE: this takes the list evaluator's file_id as a form field, not a file
     * upload as the docs describe — an upload is rejected with
     * "file_id: This field is required". It returns a new validation job's
     * file_id, which answers "File cannot be found" for a short while after it
     * is accepted.
     *
     * @param string $fileId the file_id returned by the list evaluator
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function sendEvaluatedFile($fileId);

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listS3Files($bucket = null, $search = null);

    /**
     * Validate a file in your Amazon S3 bucket. POST /validateS3file
     *
     * $options takes file_name and return_url, both required, plus the
     * optional has_header_row, remove_duplicates, remove_duplicate,
     * email_address_column, first_name_column, last_name_column,
     * gender_column, ip_address_column and allow_phase_2.
     *
     * @param array<string, mixed> $options
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function validateS3File(array $options);

    /**
     * Score a file in your Amazon S3 bucket. POST /scoreS3file
     *
     * $options takes file_name and return_url, both required, plus the
     * optional has_header_row, remove_duplicates, remove_duplicate and
     * email_address_column.
     *
     * @param array<string, mixed> $options
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function scoreS3File(array $options);

    /**
     * Export a validated or scored file back to Amazon S3. POST /exportS3file
     *
     * Without $exportBucket the output bucket from your ZeroBounce settings is
     * used, the same way exportSFTPFile falls back to the configured path.
     *
     * @param string $fileId
     * @param string|null $exportBucket
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function exportS3File($fileId, $exportBucket = null);

    public function addFilter($rule, $target, $value);

    /**
     * @return array<string, mixed>|mixed
     */
    public function listFilters($usePost = false);

    /**
     * @return array<string, mixed>
     */
    public function deleteFilter($rule, $target, $value);

    /**
     * Run the email finder on a file in your Amazon S3 bucket. POST /emailFinderS3file
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function emailFinderS3File(array $options);

    /**
     * Run domain search on a file in your Amazon S3 bucket. POST /domainSearchS3file
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function domainSearchS3File(array $options);

    /**
     * Validate a file from your SFTP/FTP server. POST /validateSFTPfile
     *
     * The docs list file_name as optional here, but the live API rejects a job
     * without it, so it is required. The server, path and protocol still come
     * from your ZeroBounce settings.
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function validateSFTPFile(array $options);

    /**
     * Score a file from your SFTP/FTP server. POST /scoreSFTPfile
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function scoreSFTPFile(array $options);

    /**
     * Run the email finder on a file from your SFTP/FTP server. POST /emailFinderSFTPfile
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function emailFinderSFTPFile(array $options);

    /**
     * Run domain search on a file from your SFTP/FTP server. POST /domainSearchSFTPfile
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function domainSearchSFTPFile(array $options);

    /**
     * Export a validated or scored file back to your SFTP/FTP server. POST /exportSFTPfile
     *
     * Without $exportPath the export path from your ZeroBounce settings is used.
     * Note this takes export_path, not the export_bucket that exportS3File uses.
     *
     * @return ZBSendFileResponse
     * @throws ZBException
     */
    public function exportSFTPFile($fileId, $exportPath = null);

    /**
     * Seed providers available for new tests. GET /providers
     *
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function getInboxPlacementProviders();

    /**
     * Supported test types and the default one. GET /test-types
     *
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function getInboxPlacementTestTypes();

    /**
     * Create an inbox placement test. POST /test
     *
     * @param string $name Unique test name, at most 64 characters.
     * @param int|null $testType 1 (Email, needs $senderEmail), 2 (Unique ID) or 3 (Auto Alias).
     * @param string|null $senderEmail The From address to match; required when $testType is 1.
     * @param array<int, int>|null $providerIds Use every active seed of these providers.
     * @param array<int, int>|null $seedIds Use these seed mailboxes.
     * @param array<int, array<string, mixed>>|null $providers array('id' => n, 'maxSeeds' => n).
     * @param string|null $webhookUrl Where the finished result is posted.
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function createInboxPlacementTest(
        $name,
        $testType = null,
        $senderEmail = null,
        $providerIds = null,
        $seedIds = null,
        $providers = null,
        $webhookUrl = null
    );

    /**
     * A test and its placement results. GET /test/{id}
     *
     * @param string|int $testId
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function getInboxPlacementTest($testId);

    /**
     * List tests, newest first. GET /tests
     *
     * @param string|null $status "running" or "finished".
     * @param string|null $createdAfter ISO 8601 date or datetime.
     * @param string|null $createdBefore ISO 8601 date or datetime.
     * @param int|null $page From 1.
     * @param int|null $pageSize 1 to 100.
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function listInboxPlacementTests(
        $status = null,
        $createdAfter = null,
        $createdBefore = null,
        $page = null,
        $pageSize = null
    );

    /**
     * Delete a finished test. DELETE /test/{id}
     *
     * @param string|int $testId
     * @return ZBRawJsonResponse empty data when the API answers with an
     *     empty 2xx (204 No Content)
     * @throws ZBException
     */
    public function deleteInboxPlacementTest($testId);

    /**
     * The webhook URL saved on this API key. GET /webhook
     *
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function getInboxPlacementWebhook();

    /**
     * Save the URL finished results are posted to. PUT /webhook
     *
     * @param string $webhookUrl
     * @return ZBRawJsonResponse
     * @throws ZBException
     */
    public function setInboxPlacementWebhook($webhookUrl);
}
