<?php

namespace ZeroBounce\SDK;

class ZBValidateSubStatus extends BasicEnum
{
    const __default = self::None;

    const None = "";
    const AntispamSystem = "antispam_system";
    const Greylisted = "greylisted";
    const MailServerTemporaryError = "mail_server_temporary_error";
    const ForcibleDisconnect = "forcible_disconnect";
    const MailServerDidNotRespond = "mail_server_did_not_respond";
    const TimeoutExceeded = "mail_server_did_not_respond";
    const FailedSmtpConnection = "failed_smtp_connection";
    const MailboxQuotaExceeded = "mailbox_quota_exceeded";
    const ExceptionOccurred = "exception_occurred";
    const PossibleTraps = "possible_traps";
    const RoleBased = "role_based";
    const GlobalSuppression = "global_suppression";
    const MailboxNotFound = "mailbox_not_found";
    const NoDnsEntries = "no_dns_entries";
    const FailedSyntaxCheck = "failed_syntax_check";
    const PossibleTypo = "possible_typo";
    const UnroutableIpAddress = "unroutable_ip_address";
    const LeadingPeriodRemoved = "leading_period_removed";
    const DoesNotAccpetMail = "does_not_accept_mail";
    const AliasAddress = "alias_address";
    const RoleBasedCatchAll = "role_based_catch_all";
    const Disposable = "disposable";
    const Toxic = "toxic";
}