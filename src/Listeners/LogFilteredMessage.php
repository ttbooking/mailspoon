<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Listeners;

use Illuminate\Support\Facades\Log;
use TTBooking\Mailspoon\Events\MessageFiltered;

/**
 * Zero-config observability for filtered messages: write a Laravel log line.
 *
 * A filtered message leaves no other trace — it is never journaled, archived
 * or delivered — so the package logs it out of the box. This keeps logging a
 * subscriber's concern rather than the captor's: registered only when
 * `mailspoon.log_filtered` is true, it can be turned off (and {@see
 * MessageFiltered} subscribed to directly) to apply a different logging policy.
 */
final class LogFilteredMessage
{
    public function handle(MessageFiltered $event): void
    {
        Log::info("Mailspoon: message filtered out on mailbox [{$event->mailbox}].", [
            'mailbox' => $event->mailbox,
            'message_id' => $event->message->messageId(),
        ]);
    }
}
