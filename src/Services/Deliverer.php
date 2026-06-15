<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Services;

use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Random\RandomException;
use Throwable;
use TTBooking\Mailspoon\Events\DeliveryPermanentlyFailed;
use TTBooking\Mailspoon\Facades\Mailspoon;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Results\DeliverySummary;
use TTBooking\Mailspoon\Support\MessageArchive;

/**
 * Post stored messages to their webhook endpoint.
 *
 * Delivery is decoupled from mailbox reading: each message gets a short
 * in-process retry to ride out transient blips; if it still fails it is
 * rescheduled with a growing back-off and retried on a later run. Returns a
 * {@see DeliverySummary} fit for the console command or a host's web layer.
 */
final class Deliverer
{
    /**
     * Back-off schedule (seconds) between runs, indexed by attempt number.
     *
     * @var list<int>
     */
    private array $backoff;

    /**
     * The attempt ceiling for the current run; failures that reach it are
     * terminal and announced via DeliveryPermanentlyFailed.
     */
    private int $maxAttempts = 1;

    /**
     * @param  list<int>  $backoff
     */
    public function __construct(
        private readonly MessageArchive $archive,
        #[Config('mailspoon.delivery.max_attempts')] private readonly int $defaultMaxAttempts,
        #[Config('mailspoon.delivery.timeout')] private readonly int $timeout,
        #[Config('mailspoon.delivery.connect_timeout')] private readonly int $connectTimeout,
        #[Config('mailspoon.delivery.retries')] private readonly int $retries,
        #[Config('mailspoon.delivery.backoff')] array $backoff,
    ) {
        $this->backoff = $backoff ?: [60];
    }

    /**
     * Messages eligible for delivery now, without sending anything.
     *
     * @return Collection<int, RelayedMessage>
     */
    public function pending(int $limit, ?int $maxAttempts = null): Collection
    {
        return RelayedMessage::deliverable($maxAttempts ?? $this->defaultMaxAttempts)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Deliver eligible messages and report the tally.
     *
     * @throws RandomException
     */
    public function run(int $limit, ?int $maxAttempts = null): DeliverySummary
    {
        $this->maxAttempts = $maxAttempts ?? $this->defaultMaxAttempts;

        $delivered = 0;
        $failed = 0;

        foreach ($this->pending($limit, $this->maxAttempts) as $message) {
            $this->deliver($message) ? $delivered++ : $failed++;
        }

        return new DeliverySummary($delivered, $failed);
    }

    /**
     * Attempt one message; true when delivered, false when failed.
     *
     * @throws RandomException
     */
    private function deliver(RelayedMessage $message): bool
    {
        $raw = $message->archive_path ? $this->archive->get($message->archive_path) : null;

        if ($raw === null) {
            $this->recordFailure($message, 0, "Archived message missing at [{$message->archive_path}].");

            return false;
        }

        if ($raw === '') {
            $this->recordFailure($message, 0, "Archived message is empty at [{$message->archive_path}].");

            return false;
        }

        $signingKey = $this->keyFor($message);

        if ($signingKey === null) {
            $this->recordFailure($message, 0, 'Signing key is not configured (no route key and no global mailspoon.key).');

            return false;
        }

        $timestamp = now()->getTimestamp();
        $token = bin2hex(random_bytes(25));

        try {
            $response = Http::asForm()
                // Delivery is at-least-once: a timeout after the endpoint
                // has processed the request gets retried. These headers
                // let the receiver deduplicate before parsing the MIME.
                ->withHeaders(array_filter([
                    'X-Mailspoon-Message-Id' => $message->message_id,
                    'X-Mailspoon-Attempt' => (string) ($message->attempts + 1),
                ]))
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->throw()
                ->retry($this->retries, fn (int $attempt) => $attempt * 200, $this->shouldRetry(...))
                ->post($message->endpoint, [
                    'body-mime' => $raw,
                    'timestamp' => $timestamp,
                    'token' => $token,
                    'signature' => hash_hmac('sha256', $timestamp.$token, $signingKey),
                ]);
        } catch (RequestException $e) {
            $this->recordFailure($message, $e->response->status(), "HTTP {$e->response->status()}");

            return false;
        } catch (ConnectionException $e) {
            $this->recordFailure($message, 0, $e->getMessage());

            return false;
        }

        $message->markDelivered($response->status());

        return true;
    }

    /**
     * Resolve the signing key for the message's mailbox route.
     *
     * Keys are never stored on the record: resolving them at delivery time
     * means key rotation applies to pending messages immediately. The global
     * key is only a fallback and may be unset when every mailbox has its own
     * route key.
     */
    private function keyFor(RelayedMessage $message): ?string
    {
        return $message->mailbox === null
            ? config('mailspoon.key')
            : Mailspoon::route($message->mailbox)->key();
    }

    /**
     * Determine whether a failed in-process attempt should be retried.
     *
     * Network errors and transient server responses (5xx, 429) are worth
     * retrying; permanent client errors (e.g. 401, 404) are not.
     */
    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->serverError() || $e->response->status() === 429);
    }

    /**
     * Mark a message failed and schedule its next attempt with back-off.
     *
     * A failure that exhausts the attempt ceiling is terminal: the record
     * stays `failed` until an operator replays it, so it is logged and
     * announced for the host application to alert on.
     */
    private function recordFailure(RelayedMessage $message, int $code, string $error): void
    {
        $delay = $this->backoffSeconds($message->attempts + 1);

        $message->markFailed($code, $error, now()->addSeconds($delay));

        if ($message->attempts >= $this->maxAttempts) {
            Log::error("Mailspoon: delivery of message [{$message->id}] permanently failed after {$message->attempts} attempt(s).", [
                'message_id' => $message->message_id,
                'mailbox' => $message->mailbox,
                'endpoint' => $message->endpoint,
                'last_error' => $message->last_error,
            ]);

            Event::dispatch(new DeliveryPermanentlyFailed($message));
        }
    }

    /**
     * Resolve the back-off delay (seconds) before the given attempt number.
     */
    private function backoffSeconds(int $attemptNumber): int
    {
        return $this->backoff[min($attemptNumber, count($this->backoff)) - 1];
    }
}
