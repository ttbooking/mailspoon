<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Results;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A single message reset to pending by a replay.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ReplayedEntry implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $id,
        public string $fingerprint,
        public ?string $mailbox,
        public ?string $endpoint,
    ) {}

    /**
     * @return array{id: int, fingerprint: string, mailbox: ?string, endpoint: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'mailbox' => $this->mailbox,
            'endpoint' => $this->endpoint,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
