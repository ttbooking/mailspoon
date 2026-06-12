<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Events;

use TTBooking\Mailspoon\Models\RelayedMessage;

/**
 * A message has exhausted its delivery attempts and will not be retried.
 *
 * The record stays in the journal with status `failed` until an operator
 * intervenes (`mailspoon:replay`). Listen to this event in the host
 * application to notify someone — without a listener the only way to learn
 * about a stuck message is polling the table.
 */
final readonly class DeliveryPermanentlyFailed
{
    public function __construct(
        public RelayedMessage $message,
    ) {}
}
