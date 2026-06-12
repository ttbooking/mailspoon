<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use TTBooking\Mailspoon\Models\RelayedMessage;
use TTBooking\Mailspoon\Support\AfterAction;

#[AsCommand(name: 'mailspoon:tidy')]
final class TidyMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailspoon:tidy {--limit=100}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply configured after-relay actions to messages in their mailboxes.';

    /**
     * Execute the console command.
     *
     * Delivery happens without an IMAP connection, so `delivered`/`failed`
     * after-actions cannot run there. This command picks up journal records
     * whose final outcome is known, reconnects to their mailboxes and applies
     * the configured action to each message. Without configured actions it is
     * a no-op and never touches IMAP.
     */
    public function handle(#[Config('mailspoon.delivery.max_attempts')] int $maxAttempts): int
    {
        $actions = $this->configuredActions();

        if ($actions === []) {
            $this->info('No after-relay actions configured.');

            return self::SUCCESS;
        }

        $messages = RelayedMessage::tidyable($maxAttempts)
            ->whereIn('mailbox', array_keys($actions))
            ->orderBy('mailbox')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($messages->isEmpty()) {
            $this->info('Nothing to tidy.');

            return self::SUCCESS;
        }

        $applied = 0;
        $skipped = 0;

        foreach ($messages->groupBy('mailbox') as $name => $group) {
            try {
                [$mailboxApplied, $mailboxSkipped] = $this->tidyMailbox((string) $name, $group, $actions[$name]);
            } catch (Throwable $e) {
                // Records stay untidied and are retried on the next run.
                $this->warn("Mailbox [{$name}] failed: {$e->getMessage()}");

                continue;
            }

            $applied += $mailboxApplied;
            $skipped += $mailboxSkipped;
        }

        $this->info("Tidied: {$applied} action(s) applied, {$skipped} message(s) skipped.");

        return self::SUCCESS;
    }

    /**
     * Apply after-actions to one mailbox's journal records.
     *
     * @param  Collection<int, RelayedMessage>  $group
     * @param  array<string, AfterAction>  $actions
     * @return array{0: int, 1: int}
     */
    protected function tidyMailbox(string $name, Collection $group, array $actions): array
    {
        $mailbox = Imap::mailbox($name);

        $applied = 0;
        $skipped = 0;

        try {
            /** @var array<string, FolderInterface> $folders */
            $folders = [];

            foreach ($group as $message) {
                $outcome = $message->status === RelayedMessage::STATUS_DELIVERED ? 'delivered' : 'failed';
                $action = $actions[$outcome] ?? null;

                // This outcome has no action for the mailbox — finalize the
                // record so it is not rescanned on every run.
                if ($action === null) {
                    $message->markTidied();

                    continue;
                }

                $folder = $folders[$message->folder ?? ''] ??= $this->folder($mailbox, $message->folder);

                $found = $this->find($folder, $message);

                // Gone, moved by hand, UIDVALIDITY changed, or captured
                // without a UID — nothing safe to act on.
                if ($found === null) {
                    $message->markTidied();
                    $skipped++;

                    $this->line("Skipped [{$message->fingerprint}] in [{$name}]: message not found by UID.");

                    continue;
                }

                $action->apply($found);
                $message->markTidied();
                $applied++;

                $this->line("Applied [{$action->describe()}] to [{$message->fingerprint}] in [{$name}].");
            }
        } finally {
            $mailbox->disconnect();
        }

        return [$applied, $skipped];
    }

    /**
     * Resolve non-none delivery-outcome actions for every configured mailbox.
     *
     * The action is resolved at tidy time (like the signing key at delivery
     * time), so configuration changes apply to pending records immediately.
     *
     * @return array<string, array<string, AfterAction>>
     */
    protected function configuredActions(): array
    {
        $actions = [];

        foreach (array_keys(config('imap.mailboxes', [])) as $name) {
            foreach (['delivered', 'failed'] as $outcome) {
                $action = AfterAction::for($name, $outcome);

                if ($action->mode !== AfterAction::NONE) {
                    $actions[$name][$outcome] = $action;
                }
            }
        }

        return $actions;
    }

    /**
     * Resolve the folder the message was captured from.
     */
    protected function folder(MailboxInterface $mailbox, ?string $path): FolderInterface
    {
        return $path ? $mailbox->folders()->findOrFail($path) : $mailbox->inbox();
    }

    /**
     * Find the journaled message in the folder and verify its identity.
     *
     * UIDs are only stable within one UIDVALIDITY epoch, so acting on the UID
     * alone could hit the wrong message. The fetched message must match the
     * record: by Message-Id, or by raw fingerprint when the header is absent.
     */
    protected function find(FolderInterface $folder, RelayedMessage $record): ?MessageInterface
    {
        if ($record->uid === null) {
            return null;
        }

        $query = $folder->messages()->withHeaders();

        if ($record->message_id === null) {
            $query = $query->withBody();
        }

        $message = $query->uid($record->uid)->get()->first();

        if ($message === null) {
            return null;
        }

        $matches = $record->message_id !== null
            ? $message->messageId() === $record->message_id
            : 'sha256:'.hash('sha256', (string) $message) === $record->fingerprint;

        return $matches ? $message : null;
    }
}
