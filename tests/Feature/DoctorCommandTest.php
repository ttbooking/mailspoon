<?php

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config([
        'mailspoon.endpoint' => 'https://hook.test/mime',
        'mailspoon.key' => 'secret-key',
        'imap.mailboxes' => ['default' => ['host' => 'imap.test']],
    ]);
});

function healthyImapMock(string $name = 'default'): void
{
    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('connect')->once();
    $mailbox->shouldReceive('inbox')->once()->andReturn(Mockery::mock(FolderInterface::class));
    $mailbox->shouldReceive('config')->with('username')->andReturn('user@example.test');
    $mailbox->shouldReceive('disconnect')->once();

    Imap::shouldReceive('mailbox')->with($name)->andReturn($mailbox);
}

it('passes when configuration, archive, imap and endpoint are healthy', function () {
    Http::fake(['*' => Http::response('', 204)]);
    healthyImapMock();

    $this->artisan('mailspoon:doctor')
        ->expectsOutputToContain('All checks passed.')
        ->assertSuccessful();

    // By default the endpoint is only probed, not fed with test mail.
    Http::assertSent(fn ($request) => $request->method() === 'OPTIONS');
});

it('fails when no endpoint is configured', function () {
    config(['mailspoon.endpoint' => null]);
    Http::fake();
    healthyImapMock();

    $this->artisan('mailspoon:doctor')
        ->expectsOutputToContain('Some checks failed.')
        ->assertFailed();
});

it('fails when the imap login fails', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('connect')->once()->andThrow(new RuntimeException('LOGIN failed'));
    $mailbox->shouldReceive('disconnect')->once();

    Imap::shouldReceive('mailbox')->with('default')->andReturn($mailbox);

    $this->artisan('mailspoon:doctor')
        ->expectsOutputToContain('LOGIN failed')
        ->assertFailed();
});

it('fails when the archive disk suppresses filesystem errors', function () {
    config(['filesystems.disks.local.throw' => false]);
    Http::fake(['*' => Http::response('', 204)]);
    healthyImapMock();

    $this->artisan('mailspoon:doctor')
        ->expectsOutputToContain('throw => true')
        ->assertFailed();
});

it('posts a signed test message with --send', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    healthyImapMock();

    $this->artisan('mailspoon:doctor --send')->assertSuccessful();

    Http::assertSent(function ($request) {
        $expected = hash_hmac('sha256', $request['timestamp'].$request['token'], 'secret-key');

        return $request->method() === 'POST'
            && $request['signature'] === $expected
            && str_contains($request['body-mime'], 'X-Mailspoon-Doctor: true');
    });
});

it('checks only the requested mailbox', function () {
    config(['imap.mailboxes' => ['default' => [], 'secondary' => []]]);
    Http::fake(['*' => Http::response('', 204)]);
    healthyImapMock('secondary');

    // The Imap facade mock only expects [secondary]; touching [default]
    // would fail the test.
    $this->artisan('mailspoon:doctor secondary')->assertSuccessful();
});
