<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\Console\Attribute\AsCommand;
use TTBooking\Mailspoon\Models\RelayedMessage;

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
    public function handle(): int
    {
        $ids = $this->argument('id');

        if (! $ids && ! $this->option('failed')) {
            $this->error('Nothing selected: pass message ids or use --failed.');

            return self::INVALID;
        }

        $messages = RelayedMessage::query()
            ->when($ids, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereIn('message_id', $ids)->orWhereIn('fingerprint', $ids)
            ))
            ->when($this->option('failed'), fn (Builder $query) => $query->where('status', RelayedMessage::STATUS_FAILED))
            ->when($this->option('mailbox'), fn (Builder $query, string $mailbox) => $query->where('mailbox', $mailbox))
            ->get();

        if ($messages->isEmpty()) {
            $this->info('Nothing to replay.');

            return self::SUCCESS;
        }

        foreach ($messages as $message) {
            $message->replay();

            $this->line("Replayed [{$message->fingerprint}] from [{$message->mailbox}]: {$message->endpoint}");
        }

        $this->info(sprintf(
            'Replayed %d message(s); they will go out on the next mailspoon:deliver run.',
            $messages->count(),
        ));

        return self::SUCCESS;
    }
}
