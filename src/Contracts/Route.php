<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Contracts;

use TTBooking\Mailspoon\MailspoonManager;
use TTBooking\Mailspoon\Support\CaptureMarker;
use TTBooking\Mailspoon\Support\MessageMatcher;

/**
 * How a single mailbox is handled: where its mail is delivered, how it is
 * signed, whether it is pulled, and how it is captured and filtered.
 *
 * Resolved from {@see MailspoonManager} — the route's own
 * options, falling back to the global config where a route leaves them unset.
 */
interface Route
{
    /**
     * Delivery endpoint: the route's own, or the global fallback (null when
     * neither is set — the caller decides whether that is an error).
     */
    public function endpoint(): ?string;

    /**
     * Signing key: the route's own, or the global fallback.
     */
    public function key(): ?string;

    /**
     * Whether the route defines its own signing key (rather than inheriting
     * the global one) — for diagnostics that distinguish the two.
     */
    public function definesKey(): bool;

    /**
     * Whether the mailbox may be pulled; false pauses capture for it.
     */
    public function enabled(): bool;

    /**
     * The capture marker for this mailbox (how viewed messages are flagged).
     */
    public function marker(): CaptureMarker;

    /**
     * The capture filters for this mailbox (include/exclude rules).
     */
    public function matcher(): MessageMatcher;
}
