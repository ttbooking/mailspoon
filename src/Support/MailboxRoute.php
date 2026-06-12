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
}
