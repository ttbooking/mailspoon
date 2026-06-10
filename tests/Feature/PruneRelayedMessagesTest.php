<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;
use TTBooking\Mailspoon\Models\RelayedMessage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config([
        'mailspoon.archive.disk' => 'local',
        'mailspoon.delivery.max_attempts' => 3,
        'mailspoon.retention.days' => 30,
    ]);
});

function retainedMessage(array $attributes = []): RelayedMessage
{
    static $sequence = 0;

    $sequence++;
    $path = "mailspoon/retention/message-{$sequence}.eml";

    Storage::disk('local')->put($path, 'RAW-MIME-BODY');

    return RelayedMessage::create(array_merge([
        'fingerprint' => "<retention-{$sequence}@mailspoon.test>",
        'message_id' => "<retention-{$sequence}@mailspoon.test>",
        'endpoint' => 'https://hook.test/mime',
        'status' => RelayedMessage::STATUS_DELIVERED,
        'attempts' => 1,
        'archive_path' => $path,
        'received_at' => now()->subDays(40),
        'delivered_at' => now()->subDays(40),
    ], $attributes));
}

it('prunes an expired delivered record together with its archived MIME', function () {
    $message = retainedMessage();

    $this->artisan('model:prune', ['--model' => [RelayedMessage::class]])
        ->assertSuccessful();

    expect(RelayedMessage::find($message->id))->toBeNull();
    Storage::disk('local')->assertMissing($message->archive_path);
});

it('keeps failed records regardless of age or exhausted retries', function () {
    $terminal = retainedMessage([
        'status' => RelayedMessage::STATUS_FAILED,
        'attempts' => 3,
        'delivered_at' => null,
        'updated_at' => now()->subDays(40),
    ]);
    $retryable = retainedMessage([
        'status' => RelayedMessage::STATUS_FAILED,
        'attempts' => 2,
        'delivered_at' => null,
        'updated_at' => now()->subDays(40),
    ]);

    $this->artisan('model:prune', ['--model' => [RelayedMessage::class]])
        ->assertSuccessful();

    expect(RelayedMessage::find($terminal->id))->not->toBeNull()
        ->and(RelayedMessage::find($retryable->id))->not->toBeNull();
    Storage::disk('local')->assertExists($terminal->archive_path);
    Storage::disk('local')->assertExists($retryable->archive_path);
});

it('keeps pending, recent and all records when retention is disabled', function () {
    $pending = retainedMessage([
        'status' => RelayedMessage::STATUS_PENDING,
        'attempts' => 0,
        'delivered_at' => null,
    ]);
    $recent = retainedMessage([
        'received_at' => now()->subDays(5),
        'delivered_at' => now()->subDays(5),
    ]);

    expect((new RelayedMessage)->pruneAll())->toBe(0);
    expect(RelayedMessage::count())->toBe(2);

    config(['mailspoon.retention.days' => 0]);

    expect((new RelayedMessage)->pruneAll())->toBe(0)
        ->and(RelayedMessage::find($pending->id))->not->toBeNull()
        ->and(RelayedMessage::find($recent->id))->not->toBeNull();
});

it('keeps the database record when its archived MIME cannot be deleted', function () {
    $message = retainedMessage();

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with($message->archive_path)->andReturnTrue();
    $disk->shouldReceive('delete')
        ->once()
        ->with($message->archive_path)
        ->andThrow(UnableToDeleteFile::atLocation($message->archive_path));

    Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

    expect(fn () => $message->prune())
        ->toThrow(UnableToDeleteFile::class);

    expect(RelayedMessage::find($message->id))->not->toBeNull();
});

it('prunes the database record when its archived MIME is already missing', function () {
    $message = retainedMessage();

    Storage::disk('local')->delete($message->archive_path);

    $this->artisan('model:prune', ['--model' => [RelayedMessage::class]])
        ->assertSuccessful();

    expect(RelayedMessage::find($message->id))->toBeNull();
});
