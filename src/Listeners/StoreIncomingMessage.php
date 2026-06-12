<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Listeners;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Container\Attributes\Config;
use RuntimeException;
use Throwable;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Support\AfterAction;
use TTBooking\Mailspoon\Support\CaptureMarker;
use TTBooking\Mailspoon\Support\MailboxRoute;
use TTBooking\Mailspoon\Support\MessageArchive;
use TTBooking\Mailspoon\Support\MessageMatcher;
use UnexpectedValueException;

/**
 * Capture an incoming message into durable storage and mark it read.
 *
 * Reading the mailbox is intentionally decoupled from webhook delivery: once
 * the raw message is archived and a delivery record is created, the message is
 * marked as seen so the single-threaded reader is never blocked by a slow or
 * failing endpoint. Actual delivery is handled out-of-band by `mailspoon:deliver`.
 */
final readonly class StoreIncomingMessage
{
    public function __construct(
        #[Config('mailspoon.endpoint')] protected ?string $endpoint,
        protected MessageArchive $archive,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(MessageReceived $event): void
    {
        $message = $event->message;

        $raw = (string) $message;

        if ($raw === '') {
            throw new UnexpectedValueException(
                'Incoming message has empty raw MIME; headers and body must be fetched from IMAP.'
            );
        }

        $messageId = $message->messageId();
        $fingerprint = $messageId ?: 'sha256:'.hash('sha256', $raw);

        // The configured mailbox name from config/imap.php is the route key
        // (imapengine-laravel ^1.3 carries it on the event).
        $mailbox = $event->mailbox;

        // How this mailbox marks viewed messages: \Seen for robot mailboxes,
        // a custom keyword or nothing at all for mailboxes read by humans.
        $marker = CaptureMarker::for($mailbox);

        // A filtered message is still marked as viewed (it must not be picked
        // up again), but never reaches the journal, archive or endpoint. Its
        // outcome is known right here, while the connection is open, so the
        // after-action (e.g. move:Spam) runs immediately — marker first, so
        // the message is not re-picked even if the action fails.
        if (! MessageMatcher::for($mailbox)->passes($message)) {
            $marker->apply($message);

            AfterAction::for($mailbox, 'filtered')->apply($message);

            return;
        }

        // Idempotent capture, scoped per mailbox and target: the same message
        // delivered to two mailboxes must reach both endpoints, but re-pulls
        // and retries of one mailbox are deduplicated.
        $exists = RelayedMessage::query()
            ->where('mailbox', $mailbox)
            ->where('target', RelayedMessage::TARGET_DEFAULT)
            ->where('fingerprint', $fingerprint)
            ->exists();

        if ($exists) {
            $marker->apply($message);

            return;
        }

        [$account, $folder] = $this->source($message);

        $receivedAt = $message->date() ?? now();

        RelayedMessage::create([
            'fingerprint' => $fingerprint,
            'message_id' => $messageId,
            'mailbox' => $mailbox,
            'account' => $account,
            'folder' => $folder,
            'uid' => $this->uid($message),
            'target' => RelayedMessage::TARGET_DEFAULT,
            'endpoint' => $this->endpointFor($mailbox),
            'status' => RelayedMessage::STATUS_PENDING,
            'archive_path' => $this->archive->store($raw, $fingerprint, $receivedAt, $mailbox),
            'received_at' => $receivedAt,
        ]);

        // Safely persisted — reading is now independent of delivery.
        $marker->apply($message);
    }

    /**
     * Resolve the endpoint for the given mailbox route, or the global default.
     *
     * The global endpoint is only a fallback: when every mailbox has its own
     * route, it may be left unset. The message stays unmarked on failure, so
     * it is picked up again once the configuration is fixed.
     */
    protected function endpointFor(string $mailbox): string
    {
        $endpoint = MailboxRoute::option($mailbox, 'endpoint') ?? $this->endpoint;

        if ($endpoint === null) {
            throw new RuntimeException(
                "Mailbox [{$mailbox}] has no endpoint (no route and no global mailspoon.endpoint)."
            );
        }

        return $endpoint;
    }

    /**
     * Resolve the message UID, best-effort.
     *
     * `mailspoon:tidy` uses it to find the message again for after-relay
     * actions; a message without one (e.g. a FileMessage) cannot be tidied.
     */
    protected function uid(MessageInterface $message): ?int
    {
        try {
            return $message->uid();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the originating IMAP account and folder, best-effort.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function source(MessageInterface $message): array
    {
        try {
            $folder = $message->folder();

            return [$folder->mailbox()->config('username'), $folder->path()];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
