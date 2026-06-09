<?php

use App\Models\RelayedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function pendingMessage(string $raw = 'RAW-MIME-BODY'): RelayedMessage
{
    Storage::disk('local')->put($path = 'mailspoon/2026/06/09/id.eml', $raw);

    return RelayedMessage::create([
        'fingerprint' => '<id@mailspoon.test>',
        'message_id' => '<id@mailspoon.test>',
        'endpoint' => 'https://hook.test/mime',
        'status' => RelayedMessage::STATUS_PENDING,
        'archive_path' => $path,
        'received_at' => now(),
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    config([
        'spoon.key' => 'secret-key',
        // Keep tests fast: no in-process retry sleeps unless a test opts in.
        'spoon.delivery.retries' => 1,
        'spoon.delivery.backoff' => [60, 300, 900],
    ]);
});

it('delivers a pending message with a signed payload and marks it delivered', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $message = pendingMessage();

    $this->artisan('spoon:deliver')->assertSuccessful();

    $message->refresh();

    expect($message->status)->toBe(RelayedMessage::STATUS_DELIVERED)
        ->and($message->response_code)->toBe(200)
        ->and($message->attempts)->toBe(1)
        ->and($message->delivered_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        $expected = hash_hmac('sha256', $request['timestamp'].$request['token'], 'secret-key');

        return $request->url() === 'https://hook.test/mime'
            && $request['body-mime'] === 'RAW-MIME-BODY'
            && $request['signature'] === $expected;
    });
});

it('marks a message failed and schedules a back-off before the next run', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $message = pendingMessage();

    $this->artisan('spoon:deliver')->assertSuccessful();

    $message->refresh();

    expect($message->status)->toBe(RelayedMessage::STATUS_FAILED)
        ->and($message->response_code)->toBe(500)
        ->and($message->attempts)->toBe(1)
        ->and($message->delivered_at)->toBeNull()
        // First failure waits backoff[0] = 60s before becoming deliverable again.
        ->and($message->next_attempt_at->timestamp)->toEqualWithDelta(now()->addSeconds(60)->timestamp, 5);
});

it('skips a failed message until its back-off window has elapsed', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $message = pendingMessage();
    $message->forceFill([
        'status' => RelayedMessage::STATUS_FAILED,
        'attempts' => 1,
        'next_attempt_at' => now()->addMinutes(5),
    ])->save();

    $this->artisan('spoon:deliver')->expectsOutputToContain('Nothing to deliver.')->assertSuccessful();

    Http::assertNothingSent();
});

it('retries a transient failure in-process and delivers within a single run', function () {
    config(['spoon.delivery.retries' => 3]);

    Http::fake(['*' => Http::sequence()
        ->push('unavailable', 503)
        ->push('ok', 200),
    ]);

    $message = pendingMessage();

    $this->artisan('spoon:deliver')->assertSuccessful();

    expect($message->refresh()->status)->toBe(RelayedMessage::STATUS_DELIVERED);
    Http::assertSentCount(2);
});

it('stops retrying once the maximum number of attempts is reached', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $message = pendingMessage();
    $message->forceFill([
        'status' => RelayedMessage::STATUS_FAILED,
        'attempts' => 10,
    ])->save();

    $this->artisan('spoon:deliver')->expectsOutputToContain('Nothing to deliver.')->assertSuccessful();

    Http::assertNothingSent();
});
