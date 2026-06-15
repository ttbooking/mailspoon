<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Results\DeliverySummary;
use TTBooking\Mailspoon\Services\Deliverer;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config([
        'mailspoon.key' => 'secret-key',
        'mailspoon.delivery.retries' => 1,
        'mailspoon.delivery.backoff' => [60, 300, 900],
    ]);
});

function deliverableMessage(string $raw = 'RAW-MIME-BODY', string $id = '<svc-deliver@mailspoon.test>'): RelayedMessage
{
    Storage::disk('local')->put($path = 'mailspoon/'.md5($id).'.eml', $raw);

    return RelayedMessage::create([
        'fingerprint' => $id,
        'message_id' => $id,
        'mailbox' => null,
        'endpoint' => 'https://hook.test/mime',
        'status' => RelayedMessage::STATUS_PENDING,
        'archive_path' => $path,
        'received_at' => now(),
    ]);
}

it('delivers eligible messages and reports the tally', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $message = deliverableMessage();

    $summary = app(Deliverer::class)->run(50);

    expect($summary)->toBeInstanceOf(DeliverySummary::class)
        ->and($summary->delivered)->toBe(1)
        ->and($summary->failed)->toBe(0)
        ->and($summary->total())->toBe(1)
        ->and($message->refresh()->status)->toBe(RelayedMessage::STATUS_DELIVERED);
});

it('counts a failed delivery without aborting the run', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $message = deliverableMessage();

    $summary = app(Deliverer::class)->run(50);

    expect($summary->delivered)->toBe(0)
        ->and($summary->failed)->toBe(1)
        ->and($message->refresh()->status)->toBe(RelayedMessage::STATUS_FAILED);
});

it('returns an empty summary when nothing is eligible', function () {
    Http::fake();

    $summary = app(Deliverer::class)->run(50);

    expect($summary->isEmpty())->toBeTrue()
        ->and($summary->total())->toBe(0);

    Http::assertNothingSent();
});

it('lists pending messages without sending or mutating them', function () {
    Http::fake();

    $message = deliverableMessage();

    $pending = app(Deliverer::class)->pending(50);

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->is($message))->toBeTrue()
        ->and($message->refresh()->status)->toBe(RelayedMessage::STATUS_PENDING);

    Http::assertNothingSent();
});

it('serializes to a json-friendly structure', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    deliverableMessage();

    expect(app(Deliverer::class)->run(50)->toArray())->toBe([
        'delivered' => 1,
        'failed' => 0,
        'total' => 1,
    ]);
});
