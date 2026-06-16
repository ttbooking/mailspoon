<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Services;

use DirectoryTree\ImapEngine\MessageInterface;
use InvalidArgumentException;
use TTBooking\Mailspoon\Facades\Mailspoon;
use TTBooking\Mailspoon\Results\FilterDecision;
use TTBooking\Mailspoon\Results\FilterTestResult;

/**
 * Dry-run a message against a mailbox's filter rules without capturing it.
 *
 * Pure evaluation: no IMAP round-trip, no journaling, no marking. The matcher
 * is the live one resolved through {@see Mailspoon::route()} (config or a
 * runtime `Mailspoon::register()`), so the verdict is exactly what capture
 * would decide. The mailbox's `enabled` flag is irrelevant here — this answers
 * "would this be filtered?", which is just as valid for a paused mailbox, so it
 * is safe to call from a dashboard while a route is still being set up.
 *
 * Returns a {@see FilterTestResult} fit for the console command or a host's web
 * layer (`response()->json($result)`).
 */
final class FilterTester
{
    /**
     * Test a message against the mailbox's filters.
     *
     * @throws InvalidArgumentException when the mailbox has a malformed rule
     *                                  (unknown field or broken regex).
     */
    public function test(string $mailbox, MessageInterface $message): FilterTestResult
    {
        $matcher = Mailspoon::route($mailbox)->matcher();

        // Deny always wins, so it is evaluated first.
        if ($deny = $matcher->deniedBy($message)) {
            return new FilterTestResult($mailbox, false, FilterDecision::DeniedByRule, $deny['field'], $deny['pattern']);
        }

        // An empty allow list lets everything through.
        if (! $matcher->hasAllowList()) {
            return new FilterTestResult($mailbox, true, FilterDecision::AllowedByDefault);
        }

        if ($allow = $matcher->allowedBy($message)) {
            return new FilterTestResult($mailbox, true, FilterDecision::AllowedByRule, $allow['field'], $allow['pattern']);
        }

        return new FilterTestResult($mailbox, false, FilterDecision::DeniedNoMatch);
    }
}
