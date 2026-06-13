<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Support;

/**
 * Per-mailbox route configuration lookup.
 *
 * Mailbox names may contain dots (`noreply@example.ru`), so route options
 * cannot be read with dot notation — `config("mailspoon.routes.{$name}.key")`
 * would treat the name as nested segments. Routes are indexed as plain
 * array keys instead.
 */
final readonly class MailboxRoute
{
    /**
     * Read a route option for the mailbox, or null when not configured.
     */
    public static function option(string $mailbox, string $option): mixed
    {
        $route = config('mailspoon.routes', [])[$mailbox] ?? null;

        return is_array($route) ? $route[$option] ?? null : null;
    }

    /**
     * Whether the mailbox may be pulled, or is paused by its route.
     *
     * A mailbox is enabled unless its route explicitly sets `enabled` to
     * false. This pauses capture (`mailspoon:pull`/`mailspoon:sentry`) only;
     * already-stored messages are still delivered by `mailspoon:deliver`.
     */
    public static function enabled(string $mailbox): bool
    {
        return self::option($mailbox, 'enabled') !== false;
    }
}
