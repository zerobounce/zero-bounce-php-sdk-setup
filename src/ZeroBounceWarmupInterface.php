<?php

namespace ZeroBounce\SDK;

/**
 * The public API of the ZeroBounce Warmup client.
 *
 * ZeroBounceWarmup is final, so it cannot be mocked or extended. Type-hint
 * this interface where your code takes a Warmup client, pass
 * new ZeroBounceWarmup($apiKey), and mock it in tests:
 *
 *     $warmup = $this->createMock(ZeroBounceWarmupInterface::class);
 *
 * Meant to be type-hinted and mocked, not implemented: new API methods are
 * added here in minor releases, as they are on ZeroBounceWarmup. A class of
 * your own that implements it may then have to add them.
 */
interface ZeroBounceWarmupInterface
{
    /** Every sending account of the key's team. GET /sending-accounts */
    public function listSendingAccounts();

    /** One sending account. GET /sending-account */
    public function getSendingAccount($fromAddress);

    /**
     * Add a from-address to warmup. POST /sending-accounts
     *
     * self_managed runs your own mailbox and needs engagementRuleId 0-4.
     * zb_managed has ZeroBounce provision it and takes emailContent and
     * managedSending; a 200 means accepted, and the mailbox is provisioned
     * afterwards.
     */
    public function addSendingAccount(
        $fromAddress,
        $onboardingModel,
        $engagementRuleId = null,
        $ignoreConflict = null,
        $emailContent = null,
        $managedSending = null
    );

    /**
     * Change an account's engagement rule, status and auto-enable flag.
     * PUT /sending-accounts. statusId is 0 to 8 (4 Paused, 5 Disabled).
     */
    public function updateSendingAccount($fromAddress, $engagementRuleId, $statusId, $autoEnable);

    /** Remove a sending account. DELETE /sending-accounts. Destructive and final. */
    public function deleteSendingAccount($fromAddress);

    /**
     * Move an account and its history to a new from-address.
     * PUT /smtp-credentials — despite the path, no SMTP credentials are stored.
     */
    public function changeFromAddress($oldFromAddress, $newFromAddress);

    /**
     * Registered from-addresses. GET /from-addresses
     *
     * searchFrom takes letters, digits and . - _ @ + only. offset and limit go
     * on the wire as strings.
     */
    public function listFromAddresses($searchFrom = null, $offset = null, $limit = null);

    /** -1 Custom, 0 Default, 1 Slow Pace, 2 Repair, 3 Boost, 4 Protect. */
    public function getEngagementRules();

    /** Set a standard rule (0 to 4) on an address. PUT /engagement-rule */
    public function setEngagementRule($cFrom, $ruleId);

    /** An address's custom rule, with can_update and remaining_seconds. */
    public function getCustomEngagement($cFrom);

    /** Create a custom rule. Starts a 7 day cooldown on updates. */
    public function createCustomEngagement($cFrom, $replyPct, $importantPct, $clickPct);

    /** Update a custom rule. During the 7 day cooldown the API answers 429. */
    public function updateCustomEngagement($cFrom, $replyPct, $importantPct, $clickPct);

    /** Delete a custom rule and fall back to standard rule 1-4. */
    public function deleteCustomEngagement($cFrom, $engagementRuleId);

    /** The seed list. GET /seeds */
    public function getSeeds();

    /** The seed list as CSV (columns seed,provider). GET /seeds/download */
    public function downloadSeeds();

    /** Number of seeds. GET /seed-count */
    public function getSeedCount();

    /** Today's warmup progress. GET /progress-today */
    public function getProgressToday();

    /** Messages sent today. GET /sent-today */
    public function getSentToday();

    /** Current daily volume. GET /emails-per-day */
    public function getEmailsPerDay();

    /** Statistics for an address. An address with no history returns zeros. */
    public function getStatistics($fromAddress);

    /** Daily statistics between two dates, inclusive. GET /statistics-per-day */
    public function getStatisticsPerDay($fromAddress, $startDate, $endDate);

    /** Detailed statistics for an address. GET /statistics-detailed */
    public function getStatisticsDetailed($cFrom);

    /** An address's statistics as CSV. GET /statistics/download */
    public function downloadStatistics($cFrom);

    /** Statistics for one from-address. GET /from-address-statistics */
    public function getFromAddressStatistics($fromAddress);

    /**
     * SPF, DKIM and DMARC status of a from-address's domain.
     * GET /check-domain-dmarc
     *
     * LIVE: pass an EMAIL ADDRESS. The live API answers "Please provide a valid
     * email address" for a bare domain, although the docs describe a domain.
     */
    public function checkDomainDmarc($fromAddress, $dkimSelector = null);

    /**
     * Check that SMTP credentials connect and hand off a message.
     * POST /test-smtp-configuration
     *
     * Nothing is stored, and the test message goes to a ZeroBounce test
     * recipient, not $fromAddress.
     *
     * LIVE: the API answers any failed SMTP login with HTTP 500. That is an API
     * bug. The username and password are masked in every error from this method.
     */
    public function testSmtpConfiguration($fromAddress, $username, $password, $server, $port);
}
