<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'mailspoon:sentry')]
final class ImapSentryCommand extends Command
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
