<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Results;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Tally of a delivery run, suitable for `response()->json()`.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DeliverySummary implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $delivered,
        public int $failed,
    ) {}

    public function total(): int
    {
        return $this->delivered + $this->failed;
    }

    /**
     * Whether nothing was eligible for delivery.
     */
    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /**
     * @return array{delivered: int, failed: int, total: int}
     */
    public function toArray(): array
    {
        return [
            'delivered' => $this->delivered,
            'failed' => $this->failed,
            'total' => $this->total(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
