<?php

namespace App\Console\Commands;

use App\Models\RelayedMessage;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Random\RandomException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'spoon:deliver')]
class DeliverMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spoon:deliver {--limit=50} {--max-attempts=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deliver stored messages to their webhook endpoint.';

    /**
     * Execute the console command.
     *
     * Delivery is decoupled from mailbox reading: this command drains pending
     * (and previously failed) messages from storage and posts them. Each run
     * makes a single attempt per message; transient failures are simply left
     * for the next run, so a slow endpoint never blocks the IMAP reader.
     *
     * @throws RandomException
     */
    public function handle(
        #[Config('spoon.key')] string $key,
        #[Config('spoon.archive.disk')] string $disk,
        #[Config('spoon.delivery.max_attempts')] int $defaultMaxAttempts,
    ): int {
        $maxAttempts = (int) ($this->option('max-attempts') ?? $defaultMaxAttempts);

        $messages = RelayedMessage::deliverable($maxAttempts)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($messages->isEmpty()) {
            $this->info('Nothing to deliver.');

            return self::SUCCESS;
        }

        $delivered = 0;
        $failed = 0;

        foreach ($messages as $message) {
            $raw = $this->rawMime($message, $disk);

            if ($raw === null) {
                $message->markFailed(0, "Archived message missing at [{$message->archive_path}].");
                $failed++;

                continue;
            }

            $timestamp = now()->getTimestamp();
            $token = bin2hex(random_bytes(25));

            try {
                $response = Http::asForm()->post($message->endpoint, [
                    'body-mime' => $raw,
                    'timestamp' => $timestamp,
                    'token' => $token,
                    'signature' => hash_hmac('sha256', $timestamp.$token, $key),
                ]);
            } catch (ConnectionException $e) {
                $message->markFailed(0, $e->getMessage());
                $failed++;

                continue;
            }

            if ($response->successful()) {
                $message->markDelivered($response->status());
                $delivered++;
            } else {
                $message->markFailed($response->status(), "HTTP {$response->status()}");
                $failed++;
            }
        }

        $this->info("Delivered: {$delivered}, failed: {$failed}.");

        return self::SUCCESS;
    }

    /**
     * Read the archived raw MIME for the given message, or null if missing.
     */
    protected function rawMime(RelayedMessage $message, string $disk): ?string
    {
        if (! $message->archive_path) {
            return null;
        }

        $storage = Storage::disk($disk);

        return $storage->exists($message->archive_path)
            ? $storage->get($message->archive_path)
            : null;
    }
}
