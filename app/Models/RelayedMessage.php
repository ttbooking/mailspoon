<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ArchiveStorage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Str;

final class RelayedMessage extends Model
{
    use Prunable;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_DELIVERED = 'delivered';

    public const string STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'response_code' => 'integer',
            'received_at' => 'datetime',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    /**
     * Select completed messages whose retention period has elapsed.
     */
    public function prunable(): Builder
    {
        $days = max(0, (int) config('spoon.retention.days', 0));
        $maxAttempts = (int) config('spoon.delivery.max_attempts', 10);
        $cutoff = now()->subDays($days);

        if ($days === 0) {
            return self::query()->whereRaw('1 = 0');
        }

        return self::query()
            ->where(function (Builder $query) use ($cutoff, $maxAttempts) {
                $query
                    ->where(fn (Builder $query) => $query
                        ->where('status', self::STATUS_DELIVERED)
                        ->where('delivered_at', '<=', $cutoff)
                    )
                    ->orWhere(fn (Builder $query) => $query
                        ->where('status', self::STATUS_FAILED)
                        ->where('attempts', '>=', $maxAttempts)
                        ->where('updated_at', '<=', $cutoff)
                    );
            });
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
        if (! $this->archive_path) {
            return;
        }

        $storage = ArchiveStorage::disk(config('spoon.archive.disk'));

        if ($storage->exists($this->archive_path)) {
            $storage->delete($this->archive_path);
        }
    }
}
