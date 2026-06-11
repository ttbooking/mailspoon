<?php

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Illuminate\Support\Facades\Context;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TTBooking\Mailspoon\Commands\ImapPullCommand;
use TTBooking\Mailspoon\Commands\ImapSentryCommand;

it('fetches flags headers and body by default', function () {
    $query = Mockery::mock(MessageQueryInterface::class);
    $query->shouldReceive('unseen')->once()->andReturnSelf();
    $query->shouldReceive('withFlags')->once()->andReturnSelf();
    $query->shouldReceive('withHeaders')->once()->andReturnSelf();
    $query->shouldReceive('withBody')->once()->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(new MessageCollection);

    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->once()->andReturn($query);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('inbox')->once()->andReturn($folder);

    Imap::shouldReceive('mailbox')->once()->with('default')->andReturn($mailbox);

    $this->artisan('mailspoon:pull default')->assertSuccessful();

    // The listener resolves routes by the mailbox name from the context.
    expect(Context::get('mailspoon.mailbox'))->toBe('default');
});

it('uses the full MIME parts when with is omitted or empty', function () {
    expect(ImapPullCommand::messageParts(null))->toBe(['flags', 'headers', 'body'])
        ->and(ImapPullCommand::messageParts(''))->toBe(['flags', 'headers', 'body'])
        ->and(ImapPullCommand::messageParts(' , '))->toBe(['flags', 'headers', 'body'])
        ->and(ImapPullCommand::messageParts('flags, body'))->toBe(['flags', 'body']);
});

it('passes the default full MIME parts to pull and watch', function () {
    $command = new class extends ImapSentryCommand
    {
        public array $calls = [];

        public function call($command, array $arguments = [])
        {
            $this->calls[$command] = $arguments;

            return self::SUCCESS;
        }
    };
    $command->setLaravel($this->app);

    $command->run(
        new ArrayInput(['mailbox' => 'default']),
        new BufferedOutput,
    );

    expect($command->calls['mailspoon:pull']['--with'])->toBe('flags,headers,body')
        ->and($command->calls['imap:watch']['--with'])->toBe('flags,headers,body')
        // Set before imap:watch, so IDLE events resolve routes too.
        ->and(Context::get('mailspoon.mailbox'))->toBe('default');
});
