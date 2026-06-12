<?php

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use TTBooking\Mailspoon\Models\RelayedMessage;

uses(RefreshDatabase::class);

function tidyableMessage(array $attributes = []): RelayedMessage
{
    static $sequence = 0;

    $sequence++;

    return RelayedMessage::create(array_merge([
        'fingerprint' => "<tidy-{$sequence}@mailspoon.test>",
        'message_id' => "<tidy-{$sequence}@mailspoon.test>",
        'mailbox' => 'support',
        'uid' => $sequence,
        'endpoint' => 'https://hook.test/mime',
        'status' => RelayedMessage::STATUS_DELIVERED,
        'attempts' => 1,
        'received_at' => now(),
        'delivered_at' => now(),
    ], $attributes));
}

/**
 * Mock the IMAP mailbox so its inbox query returns the given messages by UID.
 *
 * @param  array<int, MessageInterface>  $messages  uid => message
 */
function inboxWithMessages(array $messages, string $name = 'support'): void
{
    $current = null;

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('withHeaders')->andReturnSelf();
    $query->shouldReceive('withBody')->andReturnSelf();
    $query->shouldReceive('uid')->andReturnUsing(function (int $uid) use ($query, &$current) {
        $current = $uid;

        return $query;
    });
    $query->shouldReceive('get')->andReturnUsing(function () use ($messages, &$current) {
        return new MessageCollection(isset($messages[$current]) ? [$messages[$current]] : []);
    });

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->andReturn($folder);
    $mailbox->shouldReceive('disconnect');

    Imap::shouldReceive('mailbox')->with($name)->andReturn($mailbox);
}

beforeEach(function () {
    config(['imap.mailboxes' => ['support' => ['host' => 'imap.test']]]);
});

it('does nothing and never connects when no actions are configured', function () {
    tidyableMessage();

    $this->artisan('mailspoon:tidy')
        ->expectsOutputToContain('No after-relay actions configured.')
        ->assertSuccessful();

    expect(RelayedMessage::sole()->tidied_at)->toBeNull();
});

it('moves a delivered message to the configured folder', function () {
    config(['mailspoon.after' => ['delivered' => 'move:Processed']]);

    $record = tidyableMessage();

    $found = Mockery::mock(MessageInterface::class);
    $found->shouldReceive('messageId')->andReturn($record->message_id);
    $found->shouldReceive('folder->mailbox->folders->firstOrCreate')->once()->with('Processed');
    $found->shouldReceive('move')->once()->with('Processed');

    inboxWithMessages([$record->uid => $found]);

    $this->artisan('mailspoon:tidy')
        ->expectsOutputToContain('Tidied: 1 action(s) applied, 0 message(s) skipped.')
        ->assertSuccessful();

    expect($record->refresh()->tidied_at)->not->toBeNull();
});

it('resolves the action from the mailbox route', function () {
    config(['mailspoon.routes' => ['support' => ['after' => ['delivered' => 'keyword:Relayed']]]]);

    $record = tidyableMessage();

    $found = Mockery::mock(MessageInterface::class);
    $found->shouldReceive('messageId')->andReturn($record->message_id);
    $found->shouldReceive('flag')->once()->with('Relayed', '+');

    inboxWithMessages([$record->uid => $found]);

    $this->artisan('mailspoon:tidy')->assertSuccessful();

    expect($record->refresh()->tidied_at)->not->toBeNull();
});

it('flags a finally failed message as deleted once attempts are exhausted', function () {
    config([
        'mailspoon.after' => ['failed' => 'delete'],
        'mailspoon.delivery.max_attempts' => 3,
    ]);

    $final = tidyableMessage([
        'status' => RelayedMessage::STATUS_FAILED,
        'attempts' => 3,
        'delivered_at' => null,
    ]);
    $retrying = tidyableMessage([
        'status' => RelayedMessage::STATUS_FAILED,
        'attempts' => 2,
        'delivered_at' => null,
    ]);

    $found = Mockery::mock(MessageInterface::class);
    $found->shouldReceive('messageId')->andReturn($final->message_id);
    $found->shouldReceive('markDeleted')->once();

    inboxWithMessages([$final->uid => $found]);

    $this->artisan('mailspoon:tidy')->assertSuccessful();

    // The exhausted message is acted on; the one still retrying is left alone.
    expect($final->refresh()->tidied_at)->not->toBeNull()
        ->and($retrying->refresh()->tidied_at)->toBeNull();
});

it('skips a message whose identity does not match the journal record', function () {
    config(['mailspoon.after' => ['delivered' => 'move:Processed']]);

    $record = tidyableMessage();

    // Same UID, different message: UIDVALIDITY must have changed.
    $found = Mockery::mock(MessageInterface::class);
    $found->shouldReceive('messageId')->andReturn('<imposter@mailspoon.test>');
    $found->shouldNotReceive('move');

    inboxWithMessages([$record->uid => $found]);

    $this->artisan('mailspoon:tidy')
        ->expectsOutputToContain('message not found by UID')
        ->assertSuccessful();

    expect($record->refresh()->tidied_at)->not->toBeNull();
});

it('verifies a message without a Message-Id by its raw fingerprint', function () {
    config(['mailspoon.after' => ['delivered' => 'move:Processed']]);

    $record = tidyableMessage([
        'message_id' => null,
        'fingerprint' => 'sha256:'.hash('sha256', 'RAW-MIME-BODY'),
    ]);

    $found = Mockery::mock(MessageInterface::class);
    $found->shouldReceive('__toString')->andReturn('RAW-MIME-BODY');
    $found->shouldReceive('folder->mailbox->folders->firstOrCreate')->once()->with('Processed');
    $found->shouldReceive('move')->once()->with('Processed');

    inboxWithMessages([$record->uid => $found]);

    $this->artisan('mailspoon:tidy')->assertSuccessful();

    expect($record->refresh()->tidied_at)->not->toBeNull();
});

it('skips a message that is gone from the folder', function () {
    config(['mailspoon.after' => ['delivered' => 'move:Processed']]);

    $record = tidyableMessage();

    inboxWithMessages([]);

    $this->artisan('mailspoon:tidy')
        ->expectsOutputToContain('message not found by UID')
        ->assertSuccessful();

    expect($record->refresh()->tidied_at)->not->toBeNull();
});

it('finalizes a record whose outcome has no configured action', function () {
    config(['mailspoon.after' => ['failed' => 'move:Failed']]);

    // Delivered, but only `failed` has an action: nothing to do, finalize.
    $record = tidyableMessage();

    inboxWithMessages([]);

    $this->artisan('mailspoon:tidy')
        ->expectsOutputToContain('Tidied: 0 action(s) applied, 0 message(s) skipped.')
        ->assertSuccessful();

    expect($record->refresh()->tidied_at)->not->toBeNull();
});

it('leaves records untouched when the mailbox connection fails', function () {
    config(['mailspoon.after' => ['delivered' => 'move:Processed']]);

    $record = tidyableMessage();

    Imap::shouldReceive('mailbox')->with('support')->andThrow(new RuntimeException('LOGIN failed'));

    $this->artisan('mailspoon:tidy')
        ->expectsOutputToContain('LOGIN failed')
        ->assertSuccessful();

    expect($record->refresh()->tidied_at)->toBeNull();
});
