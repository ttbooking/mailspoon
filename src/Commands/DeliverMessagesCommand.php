<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Random\RandomException;
use Symfony\Component\Console\Attribute\AsCommand;
use TTBooking\Mailspoon\Facades\Mailspoon;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Services\Deliverer;
use TTBooking\Mailspoon\Support\MessageArchive;

#[AsCommand(name: 'mailspoon:deliver')]
final class DeliverMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailspoon:deliver
        {--limit=50}
        {--max-attempts=}
        {--dry-run : Show what would be delivered without sending or changing records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deliver stored messages to their webhook endpoint.';

    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle(Deliverer $deliverer, MessageArchive $archive): int
    {
        $limit = (int) $this->option('limit');
        $maxAttempts = $this->option('max-attempts') !== null ? (int) $this->option('max-attempts') : null;

        if ($this->option('dry-run')) {
            $messages = $deliverer->pending($limit, $maxAttempts);

            if ($messages->isEmpty()) {
                $this->info('Nothing to deliver.');

                return self::SUCCESS;
            }

            return $this->dryRun($messages, $archive);
        }

        $summary = $deliverer->run($limit, $maxAttempts);

        if ($summary->isEmpty()) {
            $this->info('Nothing to deliver.');

            return self::SUCCESS;
        }

        $this->info("Delivered: {$summary->delivered}, failed: {$summary->failed}.");

        return self::SUCCESS;
    }

    /**
     * Report what would be delivered without sending or mutating anything.
     *
     * @param  Collection<int, RelayedMessage>  $messages
     */
    private function dryRun($messages, MessageArchive $archive): int
    {
        $this->table(
            ['ID', 'Mailbox', 'Status', 'Attempts', 'Endpoint', 'Key', 'Archive'],
            $messages->map(fn (RelayedMessage $message) => [
                $message->id,
                $message->mailbox ?? '—',
                $message->status,
                $message->attempts,
                $message->endpoint,
                $message->mailbox !== null && Mailspoon::route($message->mailbox)->definesKey()
                    ? "route:{$message->mailbox}"
                    : 'global',
                match (true) {
                    ! $message->archive_path => 'missing',
                    ($raw = $archive->get($message->archive_path)) === null => 'missing',
                    $raw === '' => 'empty',
                    default => strlen($raw).' B',
                },
            ])->all(),
        );

        $this->info(sprintf(
            'Dry-run: %d message(s) would be delivered. No requests were sent.',
            $messages->count(),
        ));

        return self::SUCCESS;
    }
}
