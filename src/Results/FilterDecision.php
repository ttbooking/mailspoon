<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Results;

/**
 * Why a message passed or was filtered by a mailbox's rules.
 */
enum FilterDecision: string
{
    /** A deny rule matched — the message is dropped (deny always wins). */
    case DeniedByRule = 'denied_by_rule';

    /** An allow rule matched — the message passes. */
    case AllowedByRule = 'allowed_by_rule';

    /** No allow list is configured, so everything passes. */
    case AllowedByDefault = 'allowed_by_default';

    /** An allow list is configured but nothing matched — the message is dropped. */
    case DeniedNoMatch = 'denied_no_match';
}
