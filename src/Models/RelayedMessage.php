<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Str;
use TTBooking\Mailspoon\Support\MessageArchive;

final class RelayedMessage extends Model
{
    use Prunable;

    public const string TARGET_DEFAULT = 'default';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DELIVERED = 'delivered';

    public const string STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'attempts' => 'integer',
            'response_code' => 'integer',
            'received_at' => 'datetime',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'tidied_at' => 'datetime',
        ];
    }

    /**
     * Select completed messages whose retention period has elapsed.
     */
    public function prunable(): Builder
    {
        $days = max(0, (int) config('mailspoon.retention.days', 0));
        $cutoff = now()->subDays($days);

        if ($days === 0) {
            return self::query()->whereRaw('1 = 0');
        }

        return self::query()
            ->where('status', self::STATUS_DELIVERED)
            ->where('delivered_at', '<=', $cutoff);
    }

    /**
     * Scope to messages due for a delivery attempt.
     *
     * Pending messages have never been attempted; failed messages are retried
     * until they reach the maximum number of attempts, but only once their
     * back-off window (`next_attempt_at`) has elapsed.
     */
    public function scopeDeliverable(Builder $query, int $maxAttempts): Builder
    {
        return $query
            ->where(function (Builder $query) use ($maxAttempts) {
                $query->where('status', self::STATUS_PENDING)
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', self::STATUS_FAILED)
                        ->where('attempts', '<', $maxAttempts)
                    );
            })
            ->where(fn (Builder $query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now())
            );
    }

    /**
     * Scope to messages whose final outcome awaits an after-relay action.
     *
     * Delivered messages are final immediately; failed messages only once
     * their delivery attempts are exhausted — while retries are still
     * possible the message must stay where it is.
     */
    public function scopeTidyable(Builder $query, int $maxAttempts): Builder
    {
        return $query
            ->whereNull('tidied_at')
            ->whereNotNull('mailbox')
            ->where(fn (Builder $query) => $query
                ->where('status', self::STATUS_DELIVERED)
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', self::STATUS_FAILED)
                    ->where('attempts', '>=', $maxAttempts)
                )
            );
    }

    /**
     * Record that the after-relay action was applied (or is inapplicable).
     */
    public function markTidied(): void
    {
        $this->forceFill(['tidied_at' => now()])->save();
    }

    /**
     * Mark the message as successfully delivered.
     */
    public function markDelivered(int $responseCode): void
    {
        $this->forceFill([
            'status' => self::STATUS_DELIVERED,
            'response_code' => $responseCode ?: null,
            'attempts' => $this->attempts + 1,
            'last_error' => null,
            'next_attempt_at' => null,
            'delivered_at' => now(),
        ])->save();
    }

    /**
     * Reset the message to pending so `mailspoon:deliver` picks it up again.
     *
     * An explicit operator action: deduplication is intentionally bypassed and
     * the attempt counter starts over, so even an exhausted message is retried.
     */
    public function replay(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'response_code' => null,
            'attempts' => 0,
            'last_error' => null,
            'next_attempt_at' => null,
            'delivered_at' => null,
            // The new outcome gets its own after-relay action.
            'tidied_at' => null,
        ])->save();
    }

    /**
     * Mark a delivery attempt as failed and schedule the next retry.
     *
     * The message remains eligible for retry once `$retryAt` has passed (until
     * it reaches the maximum number of attempts).
     */
    public function markFailed(int $responseCode, string $error, ?CarbonInterface $retryAt = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'response_code' => $responseCode ?: null,
            'attempts' => $this->attempts + 1,
            'last_error' => Str::limit($error, 1000),
            'next_attempt_at' => $retryAt,
        ])->save();
    }

    /**
     * Remove the archived MIME before deleting its database record.
     */
    protected function pruning(): void
    {
        if ($this->archive_path) {
            resolve(MessageArchive::class)->delete($this->archive_path);
        }
    }
}
