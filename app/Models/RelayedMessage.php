<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RelayedMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'response_code' => 'integer',
            'received_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Scope to messages still awaiting successful delivery.
     *
     * Pending messages have never been attempted; failed messages are retried
     * until they reach the maximum number of attempts.
     */
    public function scopeDeliverable(Builder $query, int $maxAttempts): Builder
    {
        return $query->where(function (Builder $query) use ($maxAttempts) {
            $query->where('status', self::STATUS_PENDING)
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', self::STATUS_FAILED)
                    ->where('attempts', '<', $maxAttempts)
                );
        });
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
            'delivered_at' => now(),
        ])->save();
    }

    /**
     * Mark a delivery attempt as failed. The message remains eligible for retry.
     */
    public function markFailed(int $responseCode, string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'response_code' => $responseCode ?: null,
            'attempts' => $this->attempts + 1,
            'last_error' => Str::limit($error, 1000),
        ])->save();
    }
}
