<?php

namespace ZeroBounce\SDK;

class ZBValidateResponse extends ZBResponse
{
    /**
     * The email address you are validating.
     * @var string|null
     */
    public $address;

    /**
     * [valid, invalid, catch-all, unknown, spamtrap, abuse, do_not_mail]
     * @var string|null
     * @see ZBValidateStatus
     */
    public $status;

    /**
     * [antispam_system, greylisted, mail_server_temporary_error, forcible_disconnect, mail_server_did_not_respond,
     * timeout_exceeded, failed_smtp_connection, mailbox_quota_exceeded, exception_occurred, possible_traps,
     * role_based, global_suppression, mailbox_not_found, no_dns_entries, failed_syntax_check, possible_typo,
     * unroutable_ip_address, leading_period_removed, does_not_accept_mail, alias_address,
     * role_based_catch_all, disposable, toxic]
     * @var string|null
     * @see ZBValidateSubStatus
     */
    public $subStatus;

    /**
     * The portion of the email address before the "@" symbol.
     * @var string|null
     */
    public $account;

    /**
     * The portion of the email address after the "@" symbol.
     * @var string|null
     */
    public $domain;

    /**
     * Suggestive Fix for an email typo
     * @var string|null
     */
    public $didYouMean;

    /**
     * Age of the email domain in days or [null].
     * @var string|null
     */
    public $domainAgeDays;

    /**
     * [true/false] If the email comes from a free provider.
     * @var bool|null
     */
    public $freeEmail;

    /**
     * [true/false] Does the domain have an MX record.
     * @var bool|null
     */
    public $mxFound;

    /**
     * The preferred MX record of the domain
     * @var string|null
     */
    public $mxRecord;

    /**
     * The SMTP Provider of the email or [null] [BETA].
     * @var string|null
     */
    public $smtpProvider;

    /**
     * The first name of the owner of the email when available or [null].
     * @var string|null
     */
    public $firstName;

    /**
     * The last name of the owner of the email when available or [null].
     * @var string|null
     */
    public $lastName;

    /**
     * The gender of the owner of the email when available or [null].
     * @var string|null
     */
    public $gender;

    /**
     * The city of the IP passed in.
     * @var string|null
     */
    public $city;

    /**
     * The region/state of the IP passed in.
     * @var string|null
     */
    public $region;

    /**
     * The zipcode of the IP passed in.
     * @var string|null
     */
    public $zipcode;

    /**
     * The country of the IP passed in.
     * @var string|null
     */
    public $country;

    /**
     * The UTC time the email was validated.
     * @var string|null
     */
    public $processedAt;

    /**
     * @var string|null
     */
    public $error;


    /**
     * @param string $key
     * @return mixed|string
     */
    public function getClassKey($key)
    {
        if ($key == "firstname") return "firstName";
        if ($key == "lastname") return "lastName";
        return parent::getClassKey($key);
    }

    public function getValue($classKey, $value)
    {
        if ($classKey == "status") return ZBValidateStatus::getByValue($value) ?? ZBValidateStatus::__default;
        if ($classKey == "sub_status") return ZBValidateSubStatus::getByValue($value) ?? ZBValidateSubStatus::__default;
        return parent::getValue($classKey, $value);
    }

    public function __toString()
    {
        return "ZBValidateResponse{" .
            "address=" . $this->address . ", " .
            "status=" . $this->status . ", " .
            "subStatus=" . $this->subStatus . ", " .
            "account=" . $this->stringField($this->account) . ", " .
            "domain=" . $this->stringField($this->domain) . ", " .
            "didYouMean=" . $this->stringField($this->didYouMean) . ", " .
            "domainAgeDays=" . $this->stringField($this->domainAgeDays) . ", " .
            "freeEmail=" . $this->freeEmail . ", " .
            "mxFound=" . $this->mxFound . ", " .
            "mxRecord=" . $this->stringField($this->mxRecord) . ", " .
            "smtpProvider=" . $this->stringField($this->smtpProvider) . ", " .
            "firstName=" . $this->stringField($this->firstName) . ", " .
            "lastName=" . $this->stringField($this->lastName) . ", " .
            "gender=" . $this->stringField($this->gender) . ", " .
            "city=" . $this->stringField($this->city) . ", " .
            "region=" . $this->stringField($this->region) . ", " .
            "zipCode=" . $this->stringField($this->zipcode) . ", " .
            "country=" . $this->stringField($this->country) . ", " .
            "processedAt=" . $this->stringField($this->processedAt) . ", " .
            "error=" . $this->stringField($this->error) . ", " .
            "}";
    }
}
