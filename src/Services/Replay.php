<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Services;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Results\ReplayedEntry;
use TTBooking\Mailspoon\Results\ReplayResult;

/**
 * Reset stored messages to pending for redelivery by `mailspoon:deliver`.
 *
 * Returns a {@see ReplayResult} listing what was reset, fit for the console
 * command or a host's web layer (`response()->json($result)`).
 */
final class Replay
{
    /**
     * Replay every message matching the criteria.
     *
     * @throws InvalidArgumentException when the criteria selects nothing.
     */
    public function run(ReplayCriteria $criteria): ReplayResult
    {
        if (! $criteria->selectsAnything()) {
            throw new InvalidArgumentException('Nothing selected: pass message ids or use the failed flag.');
        }

        $messages = RelayedMessage::query()
            ->when($criteria->ids !== [], fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereIn('message_id', $criteria->ids)->orWhereIn('fingerprint', $criteria->ids)
            ))
            ->when($criteria->failed, fn (Builder $query) => $query->where('status', RelayedMessage::STATUS_FAILED))
            ->when($criteria->mailbox !== null, fn (Builder $query) => $query->where('mailbox', $criteria->mailbox))
            ->get();

        $entries = [];

        foreach ($messages as $message) {
            $message->replay();

            $entries[] = new ReplayedEntry($message->id, $message->fingerprint, $message->mailbox, $message->endpoint);
        }

        return new ReplayResult($entries);
    }
}
