<?php

use App\Listeners\StoreIncomingMessage;
use App\Models\RelayedMessage;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

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
        disk: 'local',
        basePath: 'mailspoon',
    );
}

it('archives the raw message, records it as pending and marks it seen', function () {
    Storage::fake('local');

    $message = fakeIncomingMessage();
    $message->shouldReceive('markSeen')->once();

    makeStoreListener()->handle(new MessageReceived($message));

    $record = RelayedMessage::sole();

    expect($record->status)->toBe(RelayedMessage::STATUS_PENDING)
        ->and($record->message_id)->toBe('<id@mailspoon.test>')
        ->and($record->endpoint)->toBe('https://hook.test/mime')
        ->and($record->archive_path)->not->toBeNull();

    Storage::disk('local')->assertExists($record->archive_path);
    expect(Storage::disk('local')->get($record->archive_path))->toBe('RAW-MIME-BODY');
});

it('does not store the same message twice but still marks it seen', function () {
    Storage::fake('local');

    $first = fakeIncomingMessage();
    $first->shouldReceive('markSeen')->once();
    makeStoreListener()->handle(new MessageReceived($first));

    $second = fakeIncomingMessage();
    $second->shouldReceive('markSeen')->once();
    makeStoreListener()->handle(new MessageReceived($second));

    expect(RelayedMessage::count())->toBe(1);
});

it('rejects an empty raw message without storing or marking it seen', function () {
    Storage::fake('local');

    $message = fakeIncomingMessage(raw: '');
    $message->shouldNotReceive('markSeen');

    expect(fn () => makeStoreListener()->handle(new MessageReceived($message)))
        ->toThrow(UnexpectedValueException::class, 'empty raw MIME');

    expect(RelayedMessage::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});
