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
use Mockery\MockInterface;
use TTBooking\Mailspoon\Commands\ImapPullCommand;
use TTBooking\Mailspoon\Models\RelayCursor;

uses(RefreshDatabase::class);

/**
 * Stub the chunked fetch: invoke the callback once per given message batch.
 *
 * @param  array<int, array<int, FakeMessage>>  $batches
 */
function expectsChunkedFetch(MockInterface $query, array $batches = [], ?int $size = null): void
{
    $query->shouldReceive('oldest')->andReturnSelf();
    $query->shouldReceive('chunk')
        ->once()
        ->withArgs(fn (callable $callback, int $chunk) => $size === null || $chunk === $size)
        ->andReturnUsing(function (callable $callback) use ($batches) {
            foreach (array_values($batches) as $page => $messages) {
                $callback(new MessageCollection($messages), $page + 1);
            }
        });
}

it('fetches flags headers and body by default', function () {
    Event::fake([MessageReceived::class]);

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('unseen')->once()->andReturnSelf();
    $query->shouldReceive('withFlags')->once()->andReturnSelf();
    $query->shouldReceive('withHeaders')->once()->andReturnSelf();
    $query->shouldReceive('withBody')->once()->andReturnSelf();
    expectsChunkedFetch($query, [[new FakeMessage(123)]]);

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
    expectsChunkedFetch($query);

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
    expectsChunkedFetch($query, [[new FakeMessage(123)]]);

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
    $query->shouldReceive('oldest')->andReturnSelf();
    $query->shouldReceive('chunk')->twice();

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

it('fetches in chunks of the configured size, oldest first', function () {
    config(['mailspoon.pull.chunk' => 25]);
    Event::fake([MessageReceived::class]);

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('unseen')->andReturnSelf();
    $query->shouldReceive('withFlags')->andReturnSelf();
    $query->shouldReceive('withHeaders')->andReturnSelf();
    $query->shouldReceive('withBody')->andReturnSelf();
    expectsChunkedFetch($query, [[new FakeMessage(1)], [new FakeMessage(2)]], size: 25);

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->andReturn($folder);

    Imap::shouldReceive('mailbox')->with('default')->andReturn($mailbox);

    $this->artisan('mailspoon:pull default')->assertSuccessful();

    Event::assertDispatchedTimes(MessageReceived::class, 2);
});

it('lets the --chunk option override the configured chunk size', function () {
    config(['mailspoon.pull.chunk' => 25]);

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('unseen')->andReturnSelf();
    $query->shouldReceive('withFlags')->andReturnSelf();
    $query->shouldReceive('withHeaders')->andReturnSelf();
    $query->shouldReceive('withBody')->andReturnSelf();
    expectsChunkedFetch($query, size: 5);

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->andReturn($folder);

    Imap::shouldReceive('mailbox')->with('default')->andReturn($mailbox);

    $this->artisan('mailspoon:pull default --chunk=5')->assertSuccessful();
});

it('persists the uid cursor after every chunk', function () {
    config(['mailspoon.routes.default.mark' => 'none']);
    Event::fake([MessageReceived::class]);

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('uid')->andReturnSelf();
    $query->shouldReceive('withFlags')->andReturnSelf();
    $query->shouldReceive('withHeaders')->andReturnSelf();
    $query->shouldReceive('withBody')->andReturnSelf();

    // An interrupted backlog run must resume from the last completed chunk:
    // capture the persisted cursor position as each chunk finishes.
    $positions = [];
    $query->shouldReceive('oldest')->andReturnSelf();
    $query->shouldReceive('chunk')->andReturnUsing(function (callable $callback) use (&$positions) {
        foreach ([[new FakeMessage(10)], [new FakeMessage(20)]] as $page => $messages) {
            $callback(new MessageCollection($messages), $page + 1);

            $positions[] = RelayCursor::sole()->last_uid;
        }
    });

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);
    $folder->shouldReceive('status')->andReturn(['UIDVALIDITY' => 7]);
    $folder->shouldReceive('path')->andReturn('INBOX');

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->andReturn($folder);

    Imap::shouldReceive('mailbox')->with('default')->andReturn($mailbox);

    $this->artisan('mailspoon:pull default')->assertSuccessful();

    expect($positions)->toBe([10, 20]);
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
    expectsChunkedFetch($query);

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
