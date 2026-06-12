<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Support;

use DirectoryTree\ImapEngine\MessageInterface;
use InvalidArgumentException;

/**
 * What happens to a message in its mailbox once a relay outcome is known.
 *
 * Outcomes are reached in different phases of store-and-forward: `filtered`
 * is known at capture, while the IMAP connection is open, so the action runs
 * right away in the listener. `delivered` and `failed` (final, after all
 * attempts) are reached later in `mailspoon:deliver` without a connection —
 * their actions are applied by the scheduled `mailspoon:tidy` command, which
 * reconnects and finds the message by the UID stored at capture.
 */
final readonly class AfterAction
{
    public const string NONE = 'none';

    public const string SEEN = 'seen';

    public const string KEYWORD = 'keyword';

    public const string MOVE = 'move';

    public const string DELETE = 'delete';

    /**
     * Outcomes an action can be configured for.
     */
    public const array OUTCOMES = ['delivered', 'failed', 'filtered'];

    private function __construct(
        public string $mode,
        public ?string $argument = null,
    ) {}

    /**
     * Resolve the action for a mailbox and outcome: route `after`, or global.
     */
    public static function for(string $mailbox, string $outcome): self
    {
        $route = MailboxRoute::option($mailbox, 'after');

        $action = (is_array($route) ? $route[$outcome] ?? null : null)
            ?? config("mailspoon.after.{$outcome}");

        return self::parse($action ?? self::NONE);
    }

    /**
     * Parse an `after` configuration value.
     */
    public static function parse(string $action): self
    {
        if (in_array($action, [self::NONE, self::SEEN, self::DELETE], true)) {
            return new self($action);
        }

        // The keyword must be a plain IMAP atom, same as capture markers.
        if (preg_match('/^keyword:([A-Za-z0-9$_.\-]+)$/', $action, $matches) === 1) {
            return new self(self::KEYWORD, $matches[1]);
        }

        if (preg_match('/^move:(.+)$/', $action, $matches) === 1) {
            return new self(self::MOVE, $matches[1]);
        }

        throw new InvalidArgumentException(
            "Invalid after action [{$action}]; use none, seen, delete, keyword:<name> or move:<folder>."
        );
    }

    /**
     * Apply the action to a message in its mailbox.
     *
     * `delete` only flags \Deleted — the message disappears on the server's
     * next expunge. `move` needs a live, folder-bound message (`folder()` and
     * `move()` are not part of MessageInterface); both capture and tidy
     * provide one.
     */
    public function apply(MessageInterface $message): void
    {
        match ($this->mode) {
            self::NONE => null,
            self::SEEN => $message->markSeen(),
            self::KEYWORD => $message->flag($this->argument, '+'),
            self::DELETE => $message->markDeleted(),
            self::MOVE => $this->move($message),
        };
    }

    /**
     * Move the message, creating the target folder when it does not exist.
     */
    private function move(MessageInterface $message): void
    {
        $message->folder()->mailbox()->folders()->firstOrCreate($this->argument);

        $message->move($this->argument);
    }

    /**
     * Human-readable form for diagnostics output.
     */
    public function describe(): string
    {
        return $this->argument === null ? $this->mode : "{$this->mode} [{$this->argument}]";
    }
}
