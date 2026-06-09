<?php

namespace App\Console\Commands;

use App\Models\RelayedMessage;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Random\RandomException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

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
     * Back-off schedule (seconds) between runs, indexed by attempt number.
     *
     * @var list<int>
     */
    protected array $backoff = [60];

    /**
     * Execute the console command.
     *
     * Delivery is decoupled from mailbox reading. Each message gets a short
     * in-process retry to ride out transient blips; if it still fails it is
     * rescheduled with a growing back-off and retried on a later run, so a slow
     * endpoint never blocks the IMAP reader.
     *
     * @param  list<int>  $backoff
     *
     * @throws RandomException
     */
    public function handle(
        #[Config('spoon.key')] string $key,
        #[Config('spoon.archive.disk')] string $disk,
        #[Config('spoon.delivery.max_attempts')] int $defaultMaxAttempts,
        #[Config('spoon.delivery.timeout')] int $timeout,
        #[Config('spoon.delivery.connect_timeout')] int $connectTimeout,
        #[Config('spoon.delivery.retries')] int $retries,
        #[Config('spoon.delivery.backoff')] array $backoff,
    ): int {
        $this->backoff = $backoff ?: [60];

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
                $this->recordFailure($message, 0, "Archived message missing at [{$message->archive_path}].");
                $failed++;

                continue;
            }

            if ($raw === '') {
                $this->recordFailure($message, 0, "Archived message is empty at [{$message->archive_path}].");
                $failed++;

                continue;
            }

            $timestamp = now()->getTimestamp();
            $token = bin2hex(random_bytes(25));

            try {
                $response = Http::asForm()
                    ->timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->throw()
                    ->retry($retries, fn (int $attempt) => $attempt * 200, $this->shouldRetry(...))
                    ->post($message->endpoint, [
                        'body-mime' => $raw,
                        'timestamp' => $timestamp,
                        'token' => $token,
                        'signature' => hash_hmac('sha256', $timestamp.$token, $key),
                    ]);
            } catch (RequestException $e) {
                $this->recordFailure($message, $e->response->status(), "HTTP {$e->response->status()}");
                $failed++;

                continue;
            } catch (ConnectionException $e) {
                $this->recordFailure($message, 0, $e->getMessage());
                $failed++;

                continue;
            }

            $message->markDelivered($response->status());
            $delivered++;
        }

        $this->info("Delivered: {$delivered}, failed: {$failed}.");

        return self::SUCCESS;
    }

    /**
     * Determine whether a failed in-process attempt should be retried.
     *
     * Network errors and transient server responses (5xx, 429) are worth
     * retrying; permanent client errors (e.g. 401, 404) are not.
     */
    protected function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->serverError() || $e->response->status() === 429);
    }

    /**
     * Mark a message failed and schedule its next attempt with back-off.
     */
    protected function recordFailure(RelayedMessage $message, int $code, string $error): void
    {
        $delay = $this->backoffSeconds($message->attempts + 1);

        $message->markFailed($code, $error, now()->addSeconds($delay));
    }

    /**
     * Resolve the back-off delay (seconds) before the given attempt number.
     */
    protected function backoffSeconds(int $attemptNumber): int
    {
        return $this->backoff[min($attemptNumber, count($this->backoff)) - 1];
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
