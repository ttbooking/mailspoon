<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Services;

/**
 * Selection for a {@see Replay} run.
 *
 * A `mailbox` only narrows the selection; on its own it selects nothing —
 * either `ids` or `failed` must be set (see {@see selectsAnything()}).
 */
final readonly class ReplayCriteria
{
    /**
     * @param  list<string>  $ids  Message-Ids or fingerprints to replay.
     * @param  bool  $failed  Replay all failed messages.
     * @param  ?string  $mailbox  Limit the selection to one mailbox.
     */
    public function __construct(
        public array $ids = [],
        public bool $failed = false,
        public ?string $mailbox = null,
    ) {}

    /**
     * Whether the criteria selects anything at all.
     */
    public function selectsAnything(): bool
    {
        return $this->ids !== [] || $this->failed;
    }
}
