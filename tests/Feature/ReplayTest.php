<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Results\ReplayedEntry;
use TTBooking\Mailspoon\Results\ReplayResult;
use TTBooking\Mailspoon\Services\Replay;
use TTBooking\Mailspoon\Services\ReplayCriteria;

uses(RefreshDatabase::class);

function replayableMessage(array $attributes = []): RelayedMessage
{
    static $sequence = 0;

    $sequence++;

    return RelayedMessage::create(array_merge([
        'fingerprint' => "<svc-replay-{$sequence}@mailspoon.test>",
        'message_id' => "<svc-replay-{$sequence}@mailspoon.test>",
        'mailbox' => 'default',
        'endpoint' => 'https://hook.test/mime',
        'status' => RelayedMessage::STATUS_FAILED,
        'attempts' => 10,
        'last_error' => 'HTTP 500',
        'next_attempt_at' => now()->addHour(),
        'archive_path' => "mailspoon/svc-replay-{$sequence}.eml",
        'received_at' => now(),
    ], $attributes));
}

it('replays failed messages and returns their entries', function () {
    $failed = replayableMessage();
    $delivered = replayableMessage([
        'status' => RelayedMessage::STATUS_DELIVERED,
        'attempts' => 1,
        'last_error' => null,
        'next_attempt_at' => null,
        'delivered_at' => now(),
    ]);

    $result = app(Replay::class)->run(new ReplayCriteria(failed: true));

    expect($result)->toBeInstanceOf(ReplayResult::class)
        ->and($result->count())->toBe(1)
        ->and($result->entries[0])->toBeInstanceOf(ReplayedEntry::class)
        ->and($result->entries[0]->fingerprint)->toBe($failed->fingerprint)
        ->and($failed->refresh()->status)->toBe(RelayedMessage::STATUS_PENDING)
        ->and($failed->attempts)->toBe(0)
        ->and($delivered->refresh()->status)->toBe(RelayedMessage::STATUS_DELIVERED);
});

it('replays by message id or fingerprint', function () {
    $byId = replayableMessage(['status' => RelayedMessage::STATUS_DELIVERED, 'delivered_at' => now()]);
    $untouched = replayableMessage();

    $result = app(Replay::class)->run(new ReplayCriteria(ids: [$byId->message_id]));

    expect($result->count())->toBe(1)
        ->and($byId->refresh()->status)->toBe(RelayedMessage::STATUS_PENDING)
        ->and($byId->delivered_at)->toBeNull()
        ->and($untouched->refresh()->attempts)->toBe(10);
});

it('limits replay to one mailbox', function () {
    $support = replayableMessage(['mailbox' => 'support']);
    $billing = replayableMessage(['mailbox' => 'billing']);

    $result = app(Replay::class)->run(new ReplayCriteria(failed: true, mailbox: 'support'));

    expect($result->count())->toBe(1)
        ->and($support->refresh()->status)->toBe(RelayedMessage::STATUS_PENDING)
        ->and($billing->refresh()->status)->toBe(RelayedMessage::STATUS_FAILED);
});

it('throws when the criteria selects nothing', function () {
    replayableMessage();

    expect(fn () => app(Replay::class)->run(new ReplayCriteria))
        ->toThrow(InvalidArgumentException::class, 'Nothing selected');

    expect(RelayedMessage::where('status', RelayedMessage::STATUS_PENDING)->count())->toBe(0);
});

it('returns an empty result when nothing matches', function () {
    replayableMessage(['mailbox' => 'support']);

    $result = app(Replay::class)->run(new ReplayCriteria(failed: true, mailbox: 'billing'));

    expect($result->isEmpty())->toBeTrue()
        ->and($result->count())->toBe(0);
});

it('serializes to a json-friendly structure', function () {
    $message = replayableMessage(['mailbox' => 'support']);

    $array = app(Replay::class)->run(new ReplayCriteria(failed: true))->toArray();

    expect($array)->toHaveKeys(['count', 'messages'])
        ->and($array['count'])->toBe(1)
        ->and($array['messages'][0])->toBe([
            'id' => $message->id,
            'fingerprint' => $message->fingerprint,
            'mailbox' => 'support',
            'endpoint' => 'https://hook.test/mime',
        ]);
});
