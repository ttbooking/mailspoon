<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Webklex\IMAP\Facades\Client as ClientFacade;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Traits\HasEvents;

#[AsCommand(name: 'imap:pull')]
class ImapPullCommand extends Command
{
    use HasEvents;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:pull {account? : Account identifier}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch new messages once';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $account = $this->argument('account') ?? config('imap.default');

        $client = ClientFacade::account($account)->connect();

        $this->events['message'] = $client->getDefaultEvents('message');

        $messages = $client->getFolder('INBOX')->messages()->unseen()->markAsRead()->get();

        foreach ($messages as $message) {
            $this->onNewMessage($message);
            $this->dispatch('message', 'new', $message);
        }
    }

    /**
     * Callback used for the pull command and triggered for every new received message
     */
    protected function onNewMessage(Message $message): void
    {
        $this->info('New message received: '.$message->subject);
    }
}
