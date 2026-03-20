<?php

namespace App\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Webklex\IMAP\Commands\ImapIdleCommand as BaseImapIdleCommand;

#[AsCommand(name: 'imap:idle')]
class ImapIdleCommand extends BaseImapIdleCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:idle {account? : Account identifier}';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->account = $this->argument('account') ?? config('imap.default');

        return parent::handle();
    }
}
