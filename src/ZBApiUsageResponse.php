<?php

namespace ZeroBounce\SDK;

class ZBApiUsageResponse extends ZBResponse
{
    /**
     * @var int|null
     */
    public $total;

    /**
     * Total valid email addresses returned by the API
     * @var int|null
     */
    public $statusValid;

    /**
     * Total invalid email addresses returned by the API
     * @var int|null
     */
    public $statusInvalid;

    /**
     * Total catch-all email addresses returned by the API
     * @var int|null
     */
    public $statusCatchAll;

    /**
     * Total do not mail email addresses returned by the API
     * @var int|null
     */
    public $statusDoNotMail;

    /**
     * Total spamtrap email addresses returned by the API
     * @var int|null
     */
    public $statusSpamtrap;

    /**
     * Total unknown email addresses returned by the API
     * @var int|null
     */
    public $statusUnknown;

    /** Total number of times the API has a sub status of "toxic"
     * @var int|null
     */
    public $subStatusToxic;

    /**
     * Total number of times the API has a sub status of "disposable"
     * @var int|null
     */
    public $subStatusDisposable;

    /**
     * Total number of times the API has a sub status of "role_based"
     * @var int|null
     */
    public $subStatusRoleBased;

    /** Total number of times the API has a sub status of "possible_trap"
     *
     */
    public $subStatusPossibleTrap;

    /** Total number of times the API has a sub status of "global_suppression"
     * @var int|null
     */
    public $subStatusGlobalSuppression;

    /**
     * Total number of times the API has a sub status of "timeout_exceeded"
     * @var int|null
     */
    public $subStatusTimeoutExceeded;

    /**
     * Total number of times the API has a sub status of "mail_server_temporary_error"
     * @var int|null
     */
    public $subStatusMailServerTemporaryError;

    /**
     * Total number of times the API has a sub status of "mail_server_did_not_respond"
     * @var int|null
     */
    public $subStatusMailServerDidNotResponse;

    /**
     * Total number of times the API has a sub status of "greylisted"
     * @var int|null
     */
    public $subStatusGreyListed;

    /**
     * Total number of times the API has a sub status of "antispam_system"
     * @var int|null
     */
    public $subStatusAntiSpamSystem;

    /**
     * Total number of times the API has a sub status of "does_not_accept_mail"
     * @var int|null
     */
    public $subStatusDoesNotAcceptMail;

    /**
     * Total number of times the API has a sub status of "exception_occurred"
     * @var int|null
     */
    public $subStatusExceptionOccurred;

    /**
     * Total number of times the API has a sub status of "failed_syntax_check"
     * @var int|null
     */
    public $subStatusFailedSyntaxCheck;

    /**
     * Total number of times the API has a sub status of "mailbox_not_found"
     * @var int|null
     */
    public $subStatusMailboxNotFound;

    /**
     * Total number of times the API has a sub status of "unroutable_ip_address"
     * @var int|null
     */
    public $subStatusUnRoutableIpAddress;

    /**
     * Total number of times the API has a sub status of "possible_typo"
     * @var int|null
     */
    public $subStatusPossibleTypo;

    /**
     * Total number of times the API has a sub status of "no_dns_entries"
     * @var int|null
     */
    public $subStatusNoDnsEntries;

    /**
     * Total role based catch alls the API has a sub status of "role_based_catch_all"
     * @var int|null
     */
    public $subStatusRoleBasedCatchAll;

    /**
     * Total number of times the API has a sub status of "mailbox_quota_exceeded"
     * @var int|null
     */
    public $subStatusMailboxQuotaExceeded;

    /**
     * Total forcible disconnects the API has a sub status of "forcible_disconnect"
     * @var int|null
     */
    public $subStatusForcibleDisconnect;

    /**
     * Total failed SMTP connections the API has a sub status of "failed_smtp_connection"
     * @var int|null
     */
    public $subStatusFailedSmtpConnection;

    /**
     * Start date of query
     * @var string|null
     */
    public $startDate;

    /**
     * End date of query
     * @var string|null
     */
    public $endDate;

    public function __toString()
    {
        return "ZBApiUsageResponse{" .
            "total=" . $this->total .
            ", statusValid=" . $this->statusValid .
            ", statusInvalid=" . $this->statusInvalid .
            ", statusCatchAll=" . $this->statusCatchAll .
            ", statusDoNotMail=" . $this->statusDoNotMail .
            ", statusSpamtrap=" . $this->statusSpamtrap .
            ", statusUnknown=" . $this->statusUnknown .
            ", subStatusToxic=" . $this->subStatusToxic .
            ", subStatusDisposable=" . $this->subStatusDisposable .
            ", subStatusRoleBased=" . $this->subStatusRoleBased .
            ", subStatusPossibleTrap=" . $this->subStatusPossibleTrap .
            ", subStatusGlobalSuppression=" . $this->subStatusGlobalSuppression .
            ", subStatusTimeoutExceeded=" . $this->subStatusTimeoutExceeded .
            ", subStatusMailServerTemporaryError=" . $this->subStatusMailServerTemporaryError .
            ", subStatusMailServerDidNotResponse=" . $this->subStatusMailServerDidNotResponse .
            ", subStatusGreyListed=" . $this->subStatusGreyListed .
            ", subStatusAntiSpamSystem=" . $this->subStatusAntiSpamSystem .
            ", subStatusDoesNotAcceptMail=" . $this->subStatusDoesNotAcceptMail .
            ", subStatusExceptionOccurred=" . $this->subStatusExceptionOccurred .
            ", subStatusFailedSyntaxCheck=" . $this->subStatusFailedSyntaxCheck .
            ", subStatusMailboxNotFound=" . $this->subStatusMailboxNotFound .
            ", subStatusUnRoutableIpAddress=" . $this->subStatusUnRoutableIpAddress .
            ", subStatusPossibleTypo=" . $this->subStatusPossibleTypo .
            ", subStatusNoDnsEntries=" . $this->subStatusNoDnsEntries .
            ", subStatusRoleBasedCatchAll=" . $this->subStatusRoleBasedCatchAll .
            ", subStatusMailboxQuotaExceeded=" . $this->subStatusMailboxQuotaExceeded .
            ", subStatusForcibleDisconnect=" . $this->subStatusForcibleDisconnect .
            ", subStatusFailedSmtpConnection=" . $this->subStatusFailedSmtpConnection .
            ", startDate=" . $this->stringField($this->startDate) .
            ", endDate=" . $this->stringField($this->endDate) .
            "}";
    }
}
