<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Support;

use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Declarative include/exclude rules applied to a message before capture.
 *
 * Rules match on `from`, `subject`, `header` and `has_attachment`. A deny
 * match always wins; an empty allow list allows everything. String patterns
 * are regular expressions when delimited (`/invoice/i`), case-insensitive
 *
 * wildcards otherwise (`*@trusted.com`).
 */
final readonly class MessageMatcher
{
    private const array FIELDS = ['from', 'subject', 'header', 'has_attachment'];

    public function __construct(
        private array $allow = [],
        private array $deny = [],
    ) {
        $this->validate($this->allow);
        $this->validate($this->deny);
    }

    /**
     * Build the matcher for a mailbox: its route filters, or the global ones.
     */
    public static function for(string $mailbox): self
    {
        $filters = MailboxRoute::option($mailbox, 'filters')
            ?? config('mailspoon.filters', []);

        return new self($filters['allow'] ?? [], $filters['deny'] ?? []);
    }

    /**
     * Determine whether the message should be captured and relayed.
     */
    public function passes(MessageInterface $message): bool
    {
        if ($this->matches($this->deny, $message)) {
            return false;
        }

        return $this->allow === [] || $this->matches($this->allow, $message);
    }

    /**
     * Determine whether any rule of the given set matches the message.
     */
    private function matches(array $rules, MessageInterface $message): bool
    {
        foreach ($rules as $field => $patterns) {
            $matched = match ($field) {
                'from' => $this->matchesAny((array) $patterns, $message->from()?->email()),
                'subject' => $this->matchesAny((array) $patterns, $message->subject()),
                'header' => $this->matchesHeaders((array) $patterns, $message),
                'has_attachment' => $message->hasAttachments() === (bool) $patterns,
            };

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match `Header-Name => pattern` pairs against the message headers.
     */
    private function matchesHeaders(array $patterns, MessageInterface $message): bool
    {
        foreach ($patterns as $name => $pattern) {
            if ($this->matchesPattern($pattern, $message->header($name)?->getValue())) {
                return true;
            }
        }

        return false;
    }

    private function matchesAny(array $patterns, ?string $value): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $pattern, ?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        if ($this->isRegex($pattern)) {
            return (bool) preg_match($pattern, $value);
        }

        return Str::is($pattern, $value, ignoreCase: true);
    }

    private function isRegex(string $pattern): bool
    {
        return strlen($pattern) > 2
            && str_starts_with($pattern, '/')
            && str_contains(substr($pattern, 1), '/');
    }

    /**
     * Fail fast on unknown fields and malformed regular expressions, so a
     * broken rule surfaces in `mailspoon:doctor` instead of killing capture.
     */
    private function validate(array $rules): void
    {
        foreach ($rules as $field => $patterns) {
            if (! in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown filter field [%s]; supported: %s.',
                    $field,
                    implode(', ', self::FIELDS),
                ));
            }

            if ($field === 'has_attachment') {
                continue;
            }

            foreach ((array) $patterns as $pattern) {
                if ($this->isRegex($pattern) && @preg_match($pattern, '') === false) {
                    throw new InvalidArgumentException("Invalid filter pattern [{$pattern}].");
                }
            }
        }
    }
}
