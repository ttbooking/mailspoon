<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use TTBooking\Mailspoon\Services\Replay;
use TTBooking\Mailspoon\Services\ReplayCriteria;

#[AsCommand(name: 'mailspoon:replay')]
final class ReplayMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailspoon:replay
        {id?* : Message-Id or fingerprint of the messages to replay}
        {--failed : Replay all failed messages}
        {--mailbox= : Limit replay to one mailbox}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset stored messages to pending for redelivery by mailspoon:deliver.';

    /**
     * Execute the console command.
     */
    public function handle(Replay $replay): int
    {
        $criteria = new ReplayCriteria(
            ids: $this->argument('id'),
            failed: (bool) $this->option('failed'),
            mailbox: $this->option('mailbox') ?: null,
        );

        if (! $criteria->selectsAnything()) {
            $this->error('Nothing selected: pass message ids or use --failed.');

            return self::INVALID;
        }

        $result = $replay->run($criteria);

        if ($result->isEmpty()) {
            $this->info('Nothing to replay.');

            return self::SUCCESS;
        }

        foreach ($result->entries as $entry) {
            $this->line("Replayed [{$entry->fingerprint}] from [{$entry->mailbox}]: {$entry->endpoint}");
        }

        $this->info(sprintf(
            'Replayed %d message(s); they will go out on the next mailspoon:deliver run.',
            $result->count(),
        ));

        return self::SUCCESS;
    }
}
