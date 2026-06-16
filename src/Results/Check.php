<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Results;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The result of one diagnostic check.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Check implements Arrayable, JsonSerializable
{
    /**
     * @param  ?string  $mailbox  Mailbox the check belongs to, or null for a general check.
     * @param  string  $name  Stable check key: database, archive, route, capture, imap, endpoint.
     */
    public function __construct(
        public ?string $mailbox,
        public string $name,
        public CheckStatus $status,
        public ?string $message = null,
    ) {}

    public function passed(): bool
    {
        return $this->status === CheckStatus::Ok;
    }

    public function failed(): bool
    {
        return $this->status === CheckStatus::Fail;
    }

    /**
     * @return array{mailbox: ?string, name: string, status: string, message: ?string}
     */
    public function toArray(): array
    {
        return [
            'mailbox' => $this->mailbox,
            'name' => $this->name,
            'status' => $this->status->value,
            'message' => $this->message,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
