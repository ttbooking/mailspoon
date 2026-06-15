<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use TTBooking\Mailspoon\Models\RelayedMessage;

uses(RefreshDatabase::class);

function storedMessage(array $attributes = []): RelayedMessage
{
    static $sequence = 0;

    $sequence++;

    return RelayedMessage::create(array_merge([
        'fingerprint' => "<replay-{$sequence}@mailspoon.test>",
        'message_id' => "<replay-{$sequence}@mailspoon.test>",
        'mailbox' => 'default',
        'endpoint' => 'https://hook.test/mime',
        'status' => RelayedMessage::STATUS_FAILED,
        'attempts' => 10,
        'last_error' => 'HTTP 500',
        'next_attempt_at' => now()->addHour(),
        'archive_path' => "mailspoon/replay-{$sequence}.eml",
        'received_at' => now(),
    ], $attributes));
}

it('replays all failed messages with --failed but leaves delivered ones alone', function () {
    $exhausted = storedMessage();
    $delivered = storedMessage([
        'status' => RelayedMessage::STATUS_DELIVERED,
        'attempts' => 1,
        'last_error' => null,
        'next_attempt_at' => null,
        'delivered_at' => now(),
    ]);

    $this->artisan('mailspoon:replay --failed')->assertSuccessful();

    $exhausted->refresh();

    expect($exhausted->status)->toBe(RelayedMessage::STATUS_PENDING)
        ->and($exhausted->attempts)->toBe(0)
        ->and($exhausted->last_error)->toBeNull()
        ->and($exhausted->next_attempt_at)->toBeNull()
        ->and($delivered->refresh()->status)->toBe(RelayedMessage::STATUS_DELIVERED);
});

it('replays a delivered message by its message id', function () {
    $delivered = storedMessage([
        'status' => RelayedMessage::STATUS_DELIVERED,
        'attempts' => 1,
        'last_error' => null,
        'next_attempt_at' => null,
        'delivered_at' => now(),
    ]);
    $untouched = storedMessage();

    $this->artisan('mailspoon:replay', ['id' => [$delivered->message_id]])->assertSuccessful();

    expect($delivered->refresh()->status)->toBe(RelayedMessage::STATUS_PENDING)
        ->and($delivered->delivered_at)->toBeNull()
        ->and($untouched->refresh()->attempts)->toBe(10);
});

it('limits --failed replay to one mailbox', function () {
    $support = storedMessage(['mailbox' => 'support']);
    $billing = storedMessage(['mailbox' => 'billing']);

    $this->artisan('mailspoon:replay --failed --mailbox=support')->assertSuccessful();

    expect($support->refresh()->status)->toBe(RelayedMessage::STATUS_PENDING)
        ->and($billing->refresh()->status)->toBe(RelayedMessage::STATUS_FAILED);
});

it('treats an empty --mailbox as no mailbox filter', function () {
    $support = storedMessage(['mailbox' => 'support']);
    $billing = storedMessage(['mailbox' => 'billing']);

    $this->artisan('mailspoon:replay --failed --mailbox=')->assertSuccessful();

    expect($support->refresh()->status)->toBe(RelayedMessage::STATUS_PENDING)
        ->and($billing->refresh()->status)->toBe(RelayedMessage::STATUS_PENDING);
});

it('requires message ids or --failed', function () {
    storedMessage();

    $this->artisan('mailspoon:replay')->assertExitCode(2);

    expect(RelayedMessage::where('status', RelayedMessage::STATUS_PENDING)->count())->toBe(0);
});

it('hands a replayed message to the next deliver run', function () {
    Storage::fake('local');
    Http::fake(['*' => Http::response('ok', 200)]);
    config(['mailspoon.key' => 'secret-key', 'mailspoon.delivery.retries' => 1]);

    $message = storedMessage();
    Storage::disk('local')->put($message->archive_path, 'RAW-MIME-BODY');

    // Exhausted: the regular deliver run skips it.
    $this->artisan('mailspoon:deliver')->expectsOutputToContain('Nothing to deliver.')->assertSuccessful();

    $this->artisan('mailspoon:replay --failed')->assertSuccessful();
    $this->artisan('mailspoon:deliver')->assertSuccessful();

    expect($message->refresh()->status)->toBe(RelayedMessage::STATUS_DELIVERED);
    Http::assertSentCount(1);
});
