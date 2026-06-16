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
     * Determine whether the message should be captured and relayed.
     */
    public function passes(MessageInterface $message): bool
    {
        return $this->deniedBy($message) === null
            && (! $this->hasAllowList() || $this->allowedBy($message) !== null);
    }

    /**
     * The first deny rule that matches the message, or null.
     *
     * Exposed so a dry-run (`mailspoon:filter-test`, a dashboard) can explain
     * the verdict, not just return a boolean.
     *
     * @return array{field: string, pattern: string}|null
     */
    public function deniedBy(MessageInterface $message): ?array
    {
        return $this->firstMatch($this->deny, $message);
    }

    /**
     * The first allow rule that matches the message, or null.
     *
     * @return array{field: string, pattern: string}|null
     */
    public function allowedBy(MessageInterface $message): ?array
    {
        return $this->firstMatch($this->allow, $message);
    }

    /**
     * Whether an allow list is configured at all; an empty one allows everything.
     */
    public function hasAllowList(): bool
    {
        return $this->allow !== [];
    }

    /**
     * The first rule in the set that matches, as a `field`/`pattern` pair, or null.
     *
     * @return array{field: string, pattern: string}|null
     */
    private function firstMatch(array $rules, MessageInterface $message): ?array
    {
        foreach ($rules as $field => $patterns) {
            $match = match ($field) {
                'from' => $this->firstHit((array) $patterns, $message->from()?->email()),
                'subject' => $this->firstHit((array) $patterns, $message->subject()),
                'header' => $this->firstHeaderHit((array) $patterns, $message),
                'has_attachment' => $message->hasAttachments() === (bool) $patterns
                    ? ['field' => 'has_attachment', 'pattern' => ((bool) $patterns) ? 'true' : 'false']
                    : null,
            };

            if ($match === null) {
                continue;
            }

            // from/subject yield the bare matching pattern; header and
            // has_attachment already carry a full descriptor.
            return is_array($match) ? $match : ['field' => $field, 'pattern' => $match];
        }

        return null;
    }

    /**
     * The first `Header-Name => pattern` pair that matches, as a descriptor, or null.
     *
     * @return array{field: string, pattern: string}|null
     */
    private function firstHeaderHit(array $patterns, MessageInterface $message): ?array
    {
        foreach ($patterns as $name => $pattern) {
            if ($this->matchesPattern($pattern, $message->header($name)?->getValue())) {
                return ['field' => "header:{$name}", 'pattern' => $pattern];
            }
        }

        return null;
    }

    /**
     * The first pattern that matches the value, or null.
     */
    private function firstHit(array $patterns, ?string $value): ?string
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($pattern, $value)) {
                return $pattern;
            }
        }

        return null;
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
