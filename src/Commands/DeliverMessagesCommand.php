<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Random\RandomException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Support\MessageArchive;

#[AsCommand(name: 'mailspoon:deliver')]
final class DeliverMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailspoon:deliver {--limit=50} {--max-attempts=}';

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
        MessageArchive $archive,
        #[Config('mailspoon.key')] string $key,
        #[Config('mailspoon.delivery.max_attempts')] int $defaultMaxAttempts,
        #[Config('mailspoon.delivery.timeout')] int $timeout,
        #[Config('mailspoon.delivery.connect_timeout')] int $connectTimeout,
        #[Config('mailspoon.delivery.retries')] int $retries,
        #[Config('mailspoon.delivery.backoff')] array $backoff,
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
            $raw = $message->archive_path ? $archive->get($message->archive_path) : null;

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
                        'signature' => hash_hmac('sha256', $timestamp.$token, $this->keyFor($message, $key)),
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
     * Resolve the signing key for the message's mailbox route.
     *
     * Keys are never stored on the record: resolving them at delivery time
     * means key rotation applies to pending messages immediately.
     */
    protected function keyFor(RelayedMessage $message, string $default): string
    {
        if ($message->mailbox === null) {
            return $default;
        }

        return config("mailspoon.routes.{$message->mailbox}.key") ?? $default;
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
}
