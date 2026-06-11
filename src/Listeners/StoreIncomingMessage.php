<?php

namespace TTBooking\Mailspoon\Listeners;

use Carbon\CarbonInterface;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Support\ArchiveStorage;
use UnexpectedValueException;

/**
 * Capture an incoming message into durable storage and mark it read.
 *
 * Reading the mailbox is intentionally decoupled from webhook delivery: once
 * the raw message is archived and a delivery record is created, the message is
 * marked as seen so the single-threaded reader is never blocked by a slow or
 * failing endpoint. Actual delivery is handled out-of-band by `mailspoon:deliver`.
 */
class StoreIncomingMessage
{
    public function __construct(
        #[Config('mailspoon.endpoint')] protected string $endpoint,
        #[Config('mailspoon.archive.disk')] protected string $disk,
        #[Config('mailspoon.archive.path')] protected string $basePath,
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

        // The configured mailbox name (the route key) is propagated by the
        // mailspoon commands; the message itself only knows the IMAP login.
        $mailbox = Context::get('mailspoon.mailbox');

        // Idempotent capture, scoped per mailbox and target: the same message
        // delivered to two mailboxes must reach both endpoints, but re-pulls
        // and retries of one mailbox are deduplicated.
        $exists = RelayedMessage::query()
            ->where('mailbox', $mailbox)
            ->where('target', RelayedMessage::TARGET_DEFAULT)
            ->where('fingerprint', $fingerprint)
            ->exists();

        if ($exists) {
            $message->markSeen();

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
            'target' => RelayedMessage::TARGET_DEFAULT,
            'endpoint' => $this->endpointFor($mailbox),
            'status' => RelayedMessage::STATUS_PENDING,
            'archive_path' => $this->archive($raw, $fingerprint, $receivedAt, $mailbox),
            'received_at' => $receivedAt,
        ]);

        // Safely persisted — reading is now independent of delivery.
        $message->markSeen();
    }

    /**
     * Resolve the endpoint for the given mailbox route, or the global default.
     */
    protected function endpointFor(?string $mailbox): string
    {
        if ($mailbox === null) {
            return $this->endpoint;
        }

        return config("mailspoon.routes.{$mailbox}.endpoint") ?? $this->endpoint;
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

    /**
     * Archive the raw MIME to the storage disk and return its path.
     *
     * The mailbox name is part of the path so that copies of one message
     * captured from different mailboxes do not overwrite each other.
     */
    protected function archive(string $raw, string $fingerprint, CarbonInterface $receivedAt, ?string $mailbox): string
    {
        $path = sprintf(
            '%s/%s%s/%s.eml',
            trim($this->basePath, '/'),
            $mailbox === null ? '' : $this->pathSegment($mailbox).'/',
            $receivedAt->format('Y/m/d'),
            $this->pathSegment($fingerprint),
        );

        ArchiveStorage::disk($this->disk)->put($path, $raw);

        return $path;
    }

    /**
     * Sanitize a value for use as a single archive path segment.
     */
    protected function pathSegment(string $value): string
    {
        return Str::of($value)
            ->replace(['<', '>', '/', '\\', ':'], '_')
            ->trim('_.')
            ->value();
    }
}
