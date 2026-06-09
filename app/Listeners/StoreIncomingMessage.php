<?php

namespace App\Listeners;

use App\Models\RelayedMessage;
use Carbon\CarbonInterface;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Capture an incoming message into durable storage and mark it read.
 *
 * Reading the mailbox is intentionally decoupled from webhook delivery: once
 * the raw message is archived and a delivery record is created, the message is
 * marked as seen so the single-threaded reader is never blocked by a slow or
 * failing endpoint. Actual delivery is handled out-of-band by `spoon:deliver`.
 */
class StoreIncomingMessage
{
    public function __construct(
        #[Config('spoon.endpoint')] protected string $endpoint,
        #[Config('spoon.archive.disk')] protected string $disk,
        #[Config('spoon.archive.path')] protected string $basePath,
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

        // Idempotent capture: if this message is already stored, just make sure
        // it is flagged read and move on (handles re-pulls and retries).
        if (RelayedMessage::where('fingerprint', $fingerprint)->exists()) {
            $message->markSeen();

            return;
        }

        [$mailbox, $folder] = $this->source($message);

        $receivedAt = $message->date() ?? now();

        RelayedMessage::create([
            'fingerprint' => $fingerprint,
            'message_id' => $messageId,
            'mailbox' => $mailbox,
            'folder' => $folder,
            'endpoint' => $this->endpoint,
            'status' => RelayedMessage::STATUS_PENDING,
            'archive_path' => $this->archive($raw, $fingerprint, $receivedAt),
            'received_at' => $receivedAt,
        ]);

        // Safely persisted — reading is now independent of delivery.
        $message->markSeen();
    }

    /**
     * Resolve the originating mailbox account and folder, best-effort.
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
     */
    protected function archive(string $raw, string $fingerprint, CarbonInterface $receivedAt): string
    {
        $name = Str::of($fingerprint)
            ->replace(['<', '>', '/', '\\', ':'], '_')
            ->trim('_')
            ->value();

        $path = sprintf(
            '%s/%s/%s.eml',
            trim($this->basePath, '/'),
            $receivedAt->format('Y/m/d'),
            $name,
        );

        Storage::disk($this->disk)->put($path, $raw);

        return $path;
    }
}
