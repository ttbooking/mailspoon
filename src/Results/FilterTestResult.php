<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Results;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Structured verdict of a filter dry-run, suitable for `response()->json()`.
 *
 * Says whether a message would be captured, and which rule decided it — so a
 * dashboard or `mailspoon:filter-test` can explain the outcome, not just show
 * a pass/fail.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class FilterTestResult implements Arrayable, JsonSerializable
{
    /**
     * @param  string  $mailbox  The mailbox whose rules were applied.
     * @param  bool  $passes  Whether the message would be captured and relayed.
     * @param  FilterDecision  $decision  Why it passed or was filtered.
     * @param  ?string  $field  Deciding field (`from`, `subject`, `header:Name`, `has_attachment`), when a rule matched.
     * @param  ?string  $pattern  Deciding pattern, when a rule matched.
     */
    public function __construct(
        public string $mailbox,
        public bool $passes,
        public FilterDecision $decision,
        public ?string $field = null,
        public ?string $pattern = null,
    ) {}

    /**
     * A human-readable explanation of the verdict.
     */
    public function reason(): string
    {
        return match ($this->decision) {
            FilterDecision::DeniedByRule => "denied by {$this->field} rule [{$this->pattern}]",
            FilterDecision::AllowedByRule => "allowed by {$this->field} rule [{$this->pattern}]",
            FilterDecision::AllowedByDefault => 'allowed — no allow list configured',
            FilterDecision::DeniedNoMatch => 'denied — no allow rule matched',
        };
    }

    /**
     * @return array{mailbox: string, passes: bool, decision: string, field: ?string, pattern: ?string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'mailbox' => $this->mailbox,
            'passes' => $this->passes,
            'decision' => $this->decision->value,
            'field' => $this->field,
            'pattern' => $this->pattern,
            'reason' => $this->reason(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
