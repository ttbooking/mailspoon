<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use TTBooking\Mailspoon\Models\RelayedMessage;

uses(RefreshDatabase::class);

function pendingMessage(
    string $raw = 'RAW-MIME-BODY',
    ?string $mailbox = null,
    string $endpoint = 'https://hook.test/mime',
    string $id = '<id@mailspoon.test>',
): RelayedMessage {
    Storage::disk('local')->put($path = 'mailspoon/2026/06/09/'.md5($id).'.eml', $raw);

    return RelayedMessage::create([
        'fingerprint' => $id,
        'message_id' => $id,
        'mailbox' => $mailbox,
        'endpoint' => $endpoint,
        'status' => RelayedMessage::STATUS_PENDING,
        'archive_path' => $path,
        'received_at' => now(),
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    config([
        'mailspoon.key' => 'secret-key',
        // Keep tests fast: no in-process retry sleeps unless a test opts in.
        'mailspoon.delivery.retries' => 1,
        'mailspoon.delivery.backoff' => [60, 300, 900],
    ]);
});

it('delivers a pending message with a signed payload and marks it delivered', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $message = pendingMessage();

    $this->artisan('mailspoon:deliver')->assertSuccessful();

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

it('signs each message with its mailbox route key', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    config(['mailspoon.routes.support.key' => 'support-key']);

    // One message captured by two mailboxes: same fingerprint, two records.
    pendingMessage(mailbox: 'support', endpoint: 'https://support.test/mime', id: '<shared@mailspoon.test>');
    pendingMessage(mailbox: 'billing', endpoint: 'https://billing.test/mime', id: '<shared@mailspoon.test>');

    $this->artisan('mailspoon:deliver')->assertSuccessful();

    // The routed mailbox is signed with its own key...
    Http::assertSent(function ($request) {
        $expected = hash_hmac('sha256', $request['timestamp'].$request['token'], 'support-key');

        return $request->url() === 'https://support.test/mime'
            && $request['signature'] === $expected;
    });

    // ...while a mailbox without a route key falls back to the global one.
    Http::assertSent(function ($request) {
        $expected = hash_hmac('sha256', $request['timestamp'].$request['token'], 'secret-key');

        return $request->url() === 'https://billing.test/mime'
            && $request['signature'] === $expected;
    });
});

it('marks a message failed and schedules a back-off before the next run', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $message = pendingMessage();

    $this->artisan('mailspoon:deliver')->assertSuccessful();

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

    $this->artisan('mailspoon:deliver')->expectsOutputToContain('Nothing to deliver.')->assertSuccessful();

    Http::assertNothingSent();
});

it('retries a transient failure in-process and delivers within a single run', function () {
    config(['mailspoon.delivery.retries' => 3]);

    Http::fake(['*' => Http::sequence()
        ->push('unavailable', 503)
        ->push('ok', 200),
    ]);

    $message = pendingMessage();

    $this->artisan('mailspoon:deliver')->assertSuccessful();

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

    $this->artisan('mailspoon:deliver')->expectsOutputToContain('Nothing to deliver.')->assertSuccessful();

    Http::assertNothingSent();
});

it('does not deliver an empty archived message', function () {
    Http::fake();

    $message = pendingMessage(raw: '');

    $this->artisan('mailspoon:deliver')->assertSuccessful();

    expect($message->refresh()->status)->toBe(RelayedMessage::STATUS_FAILED)
        ->and($message->attempts)->toBe(1)
        ->and($message->last_error)->toContain('Archived message is empty');

    Http::assertNothingSent();
});
