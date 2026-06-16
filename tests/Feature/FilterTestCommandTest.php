<?php

use DirectoryTree\ImapEngine\FileMessage;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;

/**
 * Write a raw MIME message to a temp .eml and return its path.
 */
function emlFile(string $from = 'sender@example.com', string $subject = 'Hello'): string
{
    $path = tempnam(sys_get_temp_dir(), 'mailspoon').'.eml';
    file_put_contents($path, "From: {$from}\r\nSubject: {$subject}\r\n\r\nBody.");

    return $path;
}

it('reports PASS for a message that survives the filters, from a file', function () {
    $path = emlFile(from: 'human@example.com');

    $this->artisan('mailspoon:filter-test', ['mailbox' => 'support', '--file' => $path])
        ->expectsOutputToContain('PASS')
        ->assertSuccessful();

    @unlink($path);
});

it('reports FILTERED with the deciding rule, from a file', function () {
    config(['mailspoon.routes.support.filters' => ['deny' => ['from' => ['no-reply@*']]]]);
    $path = emlFile(from: 'no-reply@acme.com');

    // The verdict and its reason render on one line, so assert one substring.
    $this->artisan('mailspoon:filter-test', ['mailbox' => 'support', '--file' => $path])
        ->expectsOutputToContain('message would be dropped (denied by from rule [no-reply@*])')
        ->assertSuccessful();

    @unlink($path);
});

it('works on a paused mailbox', function () {
    config([
        'mailspoon.routes.support.enabled' => false,
        'mailspoon.routes.support.filters' => ['deny' => ['from' => ['no-reply@*']]],
    ]);
    $path = emlFile(from: 'no-reply@acme.com');

    $this->artisan('mailspoon:filter-test', ['mailbox' => 'support', '--file' => $path])
        ->expectsOutputToContain('FILTERED')
        ->assertSuccessful();

    @unlink($path);
});

it('fetches the message by uid when --uid is given', function () {
    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('withHeaders')->andReturnSelf();
    $query->shouldReceive('withBody')->andReturnSelf();
    $query->shouldReceive('find')->with(42)->andReturn(
        new FileMessage("From: no-reply@acme.com\r\nSubject: x\r\n\r\nbody")
    );

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->andReturn($folder);

    Imap::shouldReceive('mailbox')->with('support')->andReturn($mailbox);

    config(['mailspoon.routes.support.filters' => ['deny' => ['from' => ['no-reply@*']]]]);

    $this->artisan('mailspoon:filter-test', ['mailbox' => 'support', '--uid' => 42])
        ->expectsOutputToContain('FILTERED')
        ->assertSuccessful();
});

it('rejects passing neither --file nor --uid', function () {
    $this->artisan('mailspoon:filter-test', ['mailbox' => 'support'])
        ->expectsOutputToContain('exactly one of --file or --uid')
        ->assertExitCode(2);
});

it('rejects passing both --file and --uid', function () {
    $path = emlFile();

    $this->artisan('mailspoon:filter-test', ['mailbox' => 'support', '--file' => $path, '--uid' => 1])
        ->expectsOutputToContain('exactly one of --file or --uid')
        ->assertExitCode(2);

    @unlink($path);
});

it('errors when the file does not exist', function () {
    $this->artisan('mailspoon:filter-test', ['mailbox' => 'support', '--file' => '/no/such/file.eml'])
        ->expectsOutputToContain('does not exist')
        ->assertExitCode(2);
});

it('fails on malformed filter rules', function () {
    config(['mailspoon.routes.support.filters' => ['allow' => ['subject' => ['/broken[/']]]]);
    $path = emlFile();

    $this->artisan('mailspoon:filter-test', ['mailbox' => 'support', '--file' => $path])
        ->expectsOutputToContain('are invalid')
        ->assertExitCode(1);

    @unlink($path);
});
