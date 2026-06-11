<?php

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use TTBooking\Mailspoon\Listeners\StoreIncomingMessage;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Support\MessageArchive;

uses(RefreshDatabase::class);

function fakeIncomingMessage(string $id = '<id@mailspoon.test>', string $raw = 'RAW-MIME-BODY'): MessageInterface
{
    $message = Mockery::mock(MessageInterface::class);
    $message->shouldReceive('date')->andReturn(null);
    $message->shouldReceive('messageId')->andReturn($id);
    $message->shouldReceive('__toString')->andReturn($raw);
    // folder() is left unstubbed so source() falls back to nulls.

    return $message;
}

function makeStoreListener(): StoreIncomingMessage
{
    return new StoreIncomingMessage(
        endpoint: 'https://hook.test/mime',
        archive: new MessageArchive(disk: 'local', basePath: 'mailspoon'),
    );
}

it('archives the raw message, records it as pending and marks it seen', function () {
    Storage::fake('local');

    $message = fakeIncomingMessage();
    $message->shouldReceive('markSeen')->once();

    makeStoreListener()->handle(new MessageReceived($message, 'default'));

    $record = RelayedMessage::sole();

    expect($record->status)->toBe(RelayedMessage::STATUS_PENDING)
        ->and($record->message_id)->toBe('<id@mailspoon.test>')
        ->and($record->mailbox)->toBe('default')
        ->and($record->endpoint)->toBe('https://hook.test/mime')
        ->and($record->archive_path)->not->toBeNull();

    Storage::disk('local')->assertExists($record->archive_path);
    expect(Storage::disk('local')->get($record->archive_path))->toBe('RAW-MIME-BODY');
});

it('does not store the same message twice but still marks it seen', function () {
    Storage::fake('local');

    $first = fakeIncomingMessage();
    $first->shouldReceive('markSeen')->once();
    makeStoreListener()->handle(new MessageReceived($first, 'default'));

    $second = fakeIncomingMessage();
    $second->shouldReceive('markSeen')->once();
    makeStoreListener()->handle(new MessageReceived($second, 'default'));

    expect(RelayedMessage::count())->toBe(1);
});

it('rejects an empty raw message without storing or marking it seen', function () {
    Storage::fake('local');

    $message = fakeIncomingMessage(raw: '');
    $message->shouldNotReceive('markSeen');

    expect(fn () => makeStoreListener()->handle(new MessageReceived($message, 'default')))
        ->toThrow(UnexpectedValueException::class, 'empty raw MIME');

    expect(RelayedMessage::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

it('stores the route endpoint and mailbox name for a routed mailbox', function () {
    Storage::fake('local');
    config(['mailspoon.routes.support.endpoint' => 'https://support.test/mime']);

    $message = fakeIncomingMessage();
    $message->shouldReceive('markSeen')->once();

    makeStoreListener()->handle(new MessageReceived($message, 'support'));

    $record = RelayedMessage::sole();

    expect($record->endpoint)->toBe('https://support.test/mime')
        ->and($record->mailbox)->toBe('support')
        ->and($record->target)->toBe(RelayedMessage::TARGET_DEFAULT)
        ->and($record->archive_path)->toStartWith('mailspoon/support/');

    Storage::disk('local')->assertExists($record->archive_path);
});

it('falls back to the global endpoint for a mailbox without a route', function () {
    Storage::fake('local');

    $message = fakeIncomingMessage();
    $message->shouldReceive('markSeen')->once();

    makeStoreListener()->handle(new MessageReceived($message, 'billing'));

    $record = RelayedMessage::sole();

    expect($record->endpoint)->toBe('https://hook.test/mime')
        ->and($record->mailbox)->toBe('billing');
});

it('captures the same message separately for each mailbox', function () {
    Storage::fake('local');
    config([
        'mailspoon.routes.support.endpoint' => 'https://support.test/mime',
        'mailspoon.routes.billing.endpoint' => 'https://billing.test/mime',
    ]);

    foreach (['support', 'billing'] as $mailbox) {
        $message = fakeIncomingMessage();
        $message->shouldReceive('markSeen')->once();

        makeStoreListener()->handle(new MessageReceived($message, $mailbox));
    }

    $records = RelayedMessage::orderBy('id')->get();

    expect($records)->toHaveCount(2)
        ->and($records->pluck('endpoint')->all())
        ->toBe(['https://support.test/mime', 'https://billing.test/mime'])
        // One message, two mailboxes: each copy is archived under its own path.
        ->and($records->pluck('archive_path')->unique())->toHaveCount(2);
});

it('marks a filtered message seen without journaling or archiving it', function () {
    Storage::fake('local');
    config(['mailspoon.filters' => ['allow' => ['subject' => ['/⚡/u']]]]);

    $message = fakeIncomingMessage();
    $message->shouldReceive('subject')->andReturn('Обычное письмо');
    $message->shouldReceive('markSeen')->once();

    makeStoreListener()->handle(new MessageReceived($message, 'default'));

    expect(RelayedMessage::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

it('captures a message that passes the subject filter', function () {
    Storage::fake('local');
    config(['mailspoon.filters' => ['allow' => ['subject' => ['/⚡/u']]]]);

    $message = fakeIncomingMessage();
    $message->shouldReceive('subject')->andReturn('Заявка ⚡ срочно');
    $message->shouldReceive('markSeen')->once();

    makeStoreListener()->handle(new MessageReceived($message, 'default'));

    expect(RelayedMessage::sole()->status)->toBe(RelayedMessage::STATUS_PENDING);
});

it('marks captured and duplicate messages with the route keyword instead of seen', function () {
    Storage::fake('local');
    config(['mailspoon.routes.default.mark' => 'keyword:Mailspoon']);

    $first = fakeIncomingMessage();
    $first->shouldReceive('flag')->once()->with('Mailspoon', '+');
    $first->shouldNotReceive('markSeen');

    makeStoreListener()->handle(new MessageReceived($first, 'default'));

    // The deduplicated re-pull is marked the same way, not with \Seen.
    $second = fakeIncomingMessage();
    $second->shouldReceive('flag')->once()->with('Mailspoon', '+');
    $second->shouldNotReceive('markSeen');

    makeStoreListener()->handle(new MessageReceived($second, 'default'));

    expect(RelayedMessage::count())->toBe(1);
});

it('leaves a filtered message untouched when the marker is none', function () {
    Storage::fake('local');
    config([
        'mailspoon.routes.default.mark' => 'none',
        'mailspoon.routes.default.filters' => ['allow' => ['subject' => ['/⚡/u']]],
    ]);

    $message = fakeIncomingMessage();
    $message->shouldReceive('subject')->andReturn('Обычное письмо');
    $message->shouldNotReceive('markSeen');
    $message->shouldNotReceive('flag');

    makeStoreListener()->handle(new MessageReceived($message, 'default'));

    expect(RelayedMessage::count())->toBe(0);
});

it('requires the archive disk to throw filesystem errors', function () {
    Storage::fake('local');
    config(['filesystems.disks.local.throw' => false]);

    $message = fakeIncomingMessage();
    $message->shouldNotReceive('markSeen');

    expect(fn () => makeStoreListener()->handle(new MessageReceived($message, 'default')))
        ->toThrow(InvalidArgumentException::class, 'must be configured with [throw => true]');

    expect(RelayedMessage::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});
