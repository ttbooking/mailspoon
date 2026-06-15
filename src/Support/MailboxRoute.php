<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Support;

use TTBooking\Mailspoon\Contracts\Route;
use TTBooking\Mailspoon\MailspoonManager;

/**
 * A mailbox route resolved from plain options arrays.
 *
 * A pure value object built by {@see MailspoonManager}: `$options` are the
 * route's own settings (config or a runtime registration); `$defaults` are the
 * global fallbacks. Each accessor prefers the route's own value and falls back
 * to the default. It reads no global state, so it can be tested with plain
 * arrays — the manager owns the config reads.
 */
final readonly class MailboxRoute implements Route
{
    /**
     * @param  array<string, mixed>  $options  The route's own settings.
     * @param  array<string, mixed>  $defaults  Global fallbacks: endpoint, key, mark, filters.
     */
    public function __construct(
        private array $options = [],
        private array $defaults = [],
    ) {}

    public function endpoint(): ?string
    {
        return $this->options['endpoint'] ?? $this->defaults['endpoint'] ?? null;
    }

    public function key(): ?string
    {
        return $this->options['key'] ?? $this->defaults['key'] ?? null;
    }

    public function definesKey(): bool
    {
        return ($this->options['key'] ?? null) !== null;
    }

    public function enabled(): bool
    {
        return ($this->options['enabled'] ?? true) !== false;
    }

    public function marker(): CaptureMarker
    {
        return CaptureMarker::parse($this->options['mark'] ?? $this->defaults['mark'] ?? CaptureMarker::SEEN);
    }

    public function matcher(): MessageMatcher
    {
        $filters = $this->options['filters'] ?? $this->defaults['filters'] ?? [];

        return new MessageMatcher($filters['allow'] ?? [], $filters['deny'] ?? []);
    }
}
