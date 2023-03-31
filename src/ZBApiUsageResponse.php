<?php

namespace ZeroBounce\SDK;

class ZBApiUsageResponse extends ZBResponse
{
    /**
     * @var int
     */
    public $total;

    /**
     * Total valid email addresses returned by the API
     * @var int
     */
    public $statusValid;

    /**
     * Total invalid email addresses returned by the API
     * @var int
     */
    public $statusInvalid;

    /**
     * Total catch-all email addresses returned by the API
     * @var int
     */
    public $statusCatchAll;

    /**
     * Total do not mail email addresses returned by the API
     * @var int
     */
    public $statusDoNotMail;

    /**
     * Total spamtrap email addresses returned by the API
     * @var int
     */
    public $statusSpamtrap;

    /**
     * Total unknown email addresses returned by the API
     * @var int
     */
    public $statusUnknown;

    /** Total number of times the API has a sub status of "toxic"
     * @var int
     */
    public $subStatusToxic;

    /**
     * Total number of times the API has a sub status of "disposable"
     * @var int
     */
    public $subStatusDisposable;

    /**
     * Total number of times the API has a sub status of "role_based"
     * @var int
     */
    public $subStatusRoleBased;

    /** Total number of times the API has a sub status of "possible_trap"
     *
     */
    public $subStatusPossibleTrap;

    /** Total number of times the API has a sub status of "global_suppression"
     * @var int
     */
    public $subStatusGlobalSuppression;

    /**
     * Total number of times the API has a sub status of "timeout_exceeded"
     * @var int
     */
    public $subStatusTimeoutExceeded;

    /**
     * Total number of times the API has a sub status of "mail_server_temporary_error"
     * @var int
     */
    public $subStatusMailServerTemporaryError;

    /**
     * Total number of times the API has a sub status of "mail_server_did_not_respond"
     * @var int
     */
    public $subStatusMailServerDidNotResponse;

    /**
     * Total number of times the API has a sub status of "greylisted"
     * @var int
     */
    public $subStatusGreyListed;

    /**
     * Total number of times the API has a sub status of "antispam_system"
     * @var int
     */
    public $subStatusAntiSpamSystem;

    /**
     * Total number of times the API has a sub status of "does_not_accept_mail"
     * @var int
     */
    public $subStatusDoesNotAcceptMail;

    /**
     * Total number of times the API has a sub status of "exception_occurred"
     * @var int
     */
    public $subStatusExceptionOccurred;

    /**
     * Total number of times the API has a sub status of "failed_syntax_check"
     * @var int
     */
    public $subStatusFailedSyntaxCheck;

    /**
     * Total number of times the API has a sub status of "mailbox_not_found"
     * @var int
     */
    public $subStatusMailboxNotFound;

    /**
     * Total number of times the API has a sub status of "unroutable_ip_address"
     * @var int
     */
    public $subStatusUnRoutableIpAddress;

    /**
     * Total number of times the API has a sub status of "possible_typo"
     * @var int
     */
    public $subStatusPossibleTypo;

    /**
     * Total number of times the API has a sub status of "no_dns_entries"
     * @var int
     */
    public $subStatusNoDnsEntries;

    /**
     * Total role based catch alls the API has a sub status of "role_based_catch_all"
     * @var int
     */
    public $subStatusRoleBasedCatchAll;

    /**
     * Total number of times the API has a sub status of "mailbox_quota_exceeded"
     * @var int
     */
    public $subStatusMailboxQuotaExceeded;

    /**
     * Total forcible disconnects the API has a sub status of "forcible_disconnect"
     * @var int
     */
    public $subStatusForcibleDisconnect;

    /**
     * Total failed SMTP connections the API has a sub status of "failed_smtp_connection"
     * @var int
     */
    public $subStatusFailedSmtpConnection;

    /**
     * Start date of query
     * @var string
     */
    public $startDate;

    /**
     * End date of query
     * @var string
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