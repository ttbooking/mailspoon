<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Support;

use DirectoryTree\ImapEngine\MessageInterface;
use InvalidArgumentException;

/**
 * How mailspoon marks messages it has already viewed.
 *
 * The default `seen` mode uses the \Seen flag as the processing cursor —
 * right for robot mailboxes. For mailboxes read by humans the read state
 * must stay untouched: `keyword:<name>` marks with a custom IMAP keyword
 * (invisible in mail clients), and `none` does not touch the message at all —
 * selection then relies on the UID cursor kept by `mailspoon:pull`.
 */
final readonly class CaptureMarker
{
    public const string SEEN = 'seen';

    public const string KEYWORD = 'keyword';

    public const string NONE = 'none';

    private function __construct(
        public string $mode,
        public ?string $keyword = null,
    ) {}

    /**
     * Resolve the marker for a mailbox: its route `mark`, or the global one.
     */
    public static function for(string $mailbox): self
    {
        return self::parse(
            MailboxRoute::option($mailbox, 'mark') ?? config('mailspoon.mark', self::SEEN)
        );
    }

    /**
     * Parse a `mark` configuration value.
     */
    public static function parse(string $mark): self
    {
        if (in_array($mark, [self::SEEN, self::NONE], true)) {
            return new self($mark);
        }

        // The keyword must be a plain IMAP atom: letters, digits, $ _ . -
        if (preg_match('/^keyword:([A-Za-z0-9$_.\-]+)$/', $mark, $matches) === 1) {
            return new self(self::KEYWORD, $matches[1]);
        }

        throw new InvalidArgumentException(
            "Invalid mark [{$mark}]; use seen, none or keyword:<name>."
        );
    }

    /**
     * Mark the message as viewed by mailspoon.
     */
    public function apply(MessageInterface $message): void
    {
        match ($this->mode) {
            self::SEEN => $message->markSeen(),
            self::KEYWORD => $message->flag($this->keyword, '+'),
            self::NONE => null,
        };
    }

    /**
     * Human-readable form for diagnostics output.
     */
    public function describe(): string
    {
        return $this->mode === self::KEYWORD ? "keyword [{$this->keyword}]" : $this->mode;
    }
}
