<?php

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use TTBooking\Mailspoon\Commands\ImapPullCommand;
use TTBooking\Mailspoon\Models\RelayCursor;

uses(RefreshDatabase::class);

it('fetches flags headers and body by default', function () {
    Event::fake([MessageReceived::class]);

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('unseen')->once()->andReturnSelf();
    $query->shouldReceive('withFlags')->once()->andReturnSelf();
    $query->shouldReceive('withHeaders')->once()->andReturnSelf();
    $query->shouldReceive('withBody')->once()->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(new MessageCollection([new FakeMessage(123)]));

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->once()->andReturn($query);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->once()->andReturn($folder);

    Imap::shouldReceive('mailbox')->once()->with('default')->andReturn($mailbox);

    $this->artisan('mailspoon:pull default')->assertSuccessful();

    // The listener resolves routes by the mailbox name carried on the event.
    Event::assertDispatched(fn (MessageReceived $event) => $event->mailbox === 'default');
});

it('selects by unkeyword when the mailbox marker is a keyword', function () {
    config(['mailspoon.routes.default.mark' => 'keyword:Mailspoon']);

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('unkeyword')->once()->with('Mailspoon')->andReturnSelf();
    $query->shouldNotReceive('unseen');
    $query->shouldReceive('withFlags')->andReturnSelf();
    $query->shouldReceive('withHeaders')->andReturnSelf();
    $query->shouldReceive('withBody')->andReturnSelf();
    $query->shouldReceive('get')->andReturn(new MessageCollection);

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->andReturn($folder);

    Imap::shouldReceive('mailbox')->with('default')->andReturn($mailbox);

    $this->artisan('mailspoon:pull default')->assertSuccessful();
});

it('tracks a uid cursor when the marker is none', function () {
    config(['mailspoon.routes.default.mark' => 'none']);
    Event::fake([MessageReceived::class]);

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('uid')->once()->with(1, INF)->andReturnSelf();
    $query->shouldReceive('withFlags')->andReturnSelf();
    $query->shouldReceive('withHeaders')->andReturnSelf();
    $query->shouldReceive('withBody')->andReturnSelf();
    $query->shouldReceive('get')->andReturn(new MessageCollection([new FakeMessage(123)]));

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);
    $folder->shouldReceive('status')->andReturn(['UIDVALIDITY' => 7]);
    $folder->shouldReceive('path')->andReturn('INBOX');

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->andReturn($folder);

    Imap::shouldReceive('mailbox')->with('default')->andReturn($mailbox);

    $this->artisan('mailspoon:pull default')->assertSuccessful();

    $cursor = RelayCursor::sole();

    expect($cursor->mailbox)->toBe('default')
        ->and($cursor->folder)->toBe('INBOX')
        ->and($cursor->uidvalidity)->toBe(7)
        ->and($cursor->last_uid)->toBe(123);

    Event::assertDispatched(MessageReceived::class);
});

it('resumes the uid cursor and restarts it when uidvalidity changes', function () {
    config(['mailspoon.routes.default.mark' => 'none']);

    RelayCursor::create([
        'mailbox' => 'default',
        'folder' => 'INBOX',
        'uidvalidity' => 7,
        'last_uid' => 50,
    ]);

    $query = Mockery::mock(MessageQueryInterface::class);
    // Same epoch: resume right after the last viewed UID.
    $query->shouldReceive('uid')->once()->with(51, INF)->andReturnSelf();
    // New epoch: UIDs were renumbered, start over.
    $query->shouldReceive('uid')->once()->with(1, INF)->andReturnSelf();
    $query->shouldReceive('withFlags')->andReturnSelf();
    $query->shouldReceive('withHeaders')->andReturnSelf();
    $query->shouldReceive('withBody')->andReturnSelf();
    $query->shouldReceive('get')->andReturn(new MessageCollection);

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);
    $folder->shouldReceive('status')->andReturn(['UIDVALIDITY' => 7], ['UIDVALIDITY' => 8]);
    $folder->shouldReceive('path')->andReturn('INBOX');

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->andReturn($folder);

    Imap::shouldReceive('mailbox')->with('default')->andReturn($mailbox);

    $this->artisan('mailspoon:pull default')->assertSuccessful();
    $this->artisan('mailspoon:pull default')->assertSuccessful();

    $cursor = RelayCursor::sole();

    expect($cursor->uidvalidity)->toBe(8)
        ->and($cursor->last_uid)->toBe(0);
});

it('uses the full MIME parts when with is omitted or empty', function () {
    expect(ImapPullCommand::messageParts(null))->toBe(['flags', 'headers', 'body'])
        ->and(ImapPullCommand::messageParts(''))->toBe(['flags', 'headers', 'body'])
        ->and(ImapPullCommand::messageParts(' , '))->toBe(['flags', 'headers', 'body'])
        ->and(ImapPullCommand::messageParts('flags, body'))->toBe(['flags', 'body']);
});

it('passes the default full MIME parts to pull and watch', function () {
    Event::fake([MessageReceived::class]);

    // The real mailspoon:pull runs against a mocked, empty mailbox.
    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('unseen')->andReturnSelf();
    $query->shouldReceive('withFlags')->andReturnSelf();
    $query->shouldReceive('withHeaders')->andReturnSelf();
    $query->shouldReceive('withBody')->andReturnSelf();
    $query->shouldReceive('get')->andReturn(new MessageCollection);

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->andReturn($folder);

    Imap::shouldReceive('mailbox')->with('default')->andReturn($mailbox);

    // Replace the vendor watcher with a stub that records its options.
    $watch = null;
    Artisan::command(
        'imap:watch {mailbox} {folder?} {--method=} {--with=} {--timeout=} {--attempts=} {--debug=}',
        function () use (&$watch) {
            $watch = $this->options();
        },
    );

    $this->artisan('mailspoon:sentry default')->assertSuccessful();

    expect($watch['with'])->toBe('flags,headers,body');
});
