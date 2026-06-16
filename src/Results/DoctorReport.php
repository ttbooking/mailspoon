<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Results;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Structured outcome of a diagnostic run, suitable for `response()->json()`.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DoctorReport implements Arrayable, JsonSerializable
{
    /**
     * @param  list<Check>  $checks
     */
    public function __construct(public array $checks) {}

    /**
     * Whether the run is free of failures. Warnings do not flip this — they are
     * advisory, so a report with only warnings is still ok (exit code 0).
     */
    public function ok(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->failed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The failed checks only.
     *
     * @return list<Check>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, fn (Check $check) => $check->failed()));
    }

    /**
     * The warning checks only — advisory, not failures.
     *
     * @return list<Check>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->checks, fn (Check $check) => $check->status === CheckStatus::Warn));
    }

    /**
     * @return array{ok: bool, checks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'checks' => array_map(fn (Check $check) => $check->toArray(), $this->checks),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
