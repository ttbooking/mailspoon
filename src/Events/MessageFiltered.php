<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Events;

use DirectoryTree\ImapEngine\MessageInterface;

/**
 * An incoming message was rejected by the mailbox filter rules.
 *
 * The message is marked as viewed but never reaches the journal, archive or
 * endpoint, so this event is the only trace of it. Listen to it in the host
 * application to count, log or alert on filtered mail — a broken allow rule
 * is otherwise indistinguishable from an empty mailbox.
 */
final readonly class MessageFiltered
{
    public function __construct(
        public MessageInterface $message,
        public string $mailbox,
    ) {}
}
