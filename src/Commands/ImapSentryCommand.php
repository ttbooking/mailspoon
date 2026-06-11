<?php

namespace TTBooking\Mailspoon\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'mailspoon:sentry')]
class ImapSentryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailspoon:sentry {mailbox} {folder?} {--method=idle} {--with=} {--timeout=30} {--attempts=5} {--debug=false}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check a mailbox for new messages and then start watching it.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // The vendor imap:watch runs in-process, so the mailbox name set here
        // reaches the listener for IDLE events as well.
        Context::add('mailspoon.mailbox', $this->argument('mailbox'));

        $with = implode(',', ImapPullCommand::messageParts($this->option('with')));

        $this->call('mailspoon:pull', [
            'mailbox' => $this->argument('mailbox'),
            'folder' => $this->argument('folder'),
            '--with' => $with,
        ]);

        $this->call('imap:watch', [
            'mailbox' => $this->argument('mailbox'),
            'folder' => $this->argument('folder'),
            '--method' => $this->option('method'),
            '--with' => $with,
            '--timeout' => $this->option('timeout'),
            '--attempts' => $this->option('attempts'),
            '--debug' => $this->option('debug'),
        ]);
    }
}
