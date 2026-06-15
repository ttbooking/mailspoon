<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Results;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Structured outcome of a replay, suitable for `response()->json()`.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ReplayResult implements Arrayable, JsonSerializable
{
    /**
     * @param  list<ReplayedEntry>  $entries
     */
    public function __construct(public array $entries) {}

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return array{count: int, messages: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count(),
            'messages' => array_map(fn (ReplayedEntry $entry) => $entry->toArray(), $this->entries),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
