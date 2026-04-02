<?php

namespace App\Console\Commands;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Commands\ConfigureIdleQuery;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'imap:pull')]
class ImapPullCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:pull {mailbox} {folder?} {--with=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check a mailbox for new messages.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $mailbox = Imap::mailbox($name = $this->argument('mailbox'));

        $with = explode(',', $this->option('with'));

        $this->info("Checking mailbox [$name]...");

        $query = $this->folder($mailbox)->messages()->unseen();
        $query = (new ConfigureIdleQuery($with))($query);

        foreach ($query->get() as $message) {
            Event::dispatch(new MessageReceived($message));
        }
    }

    /**
     * Get the mailbox folder to check.
     */
    protected function folder(MailboxInterface $mailbox): FolderInterface
    {
        return ($folder = $this->argument('folder'))
            ? $mailbox->folders()->findOrFail($folder)
            : $mailbox->inbox();
    }
}
