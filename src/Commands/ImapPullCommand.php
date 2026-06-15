<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Commands\ConfigureIdleQuery;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Attribute\AsCommand;
use TTBooking\Mailspoon\Facades\Mailspoon;
use TTBooking\Mailspoon\Models\RelayCursor;
use TTBooking\Mailspoon\Support\CaptureMarker;

#[AsCommand(name: 'mailspoon:pull')]
final class ImapPullCommand extends Command
{
    public const DEFAULT_WITH = ['flags', 'headers', 'body'];

    public const int DEFAULT_CHUNK = 100;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailspoon:pull {mailbox} {folder?} {--with=} {--chunk=}';

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
        $name = $this->argument('mailbox');

        // A paused mailbox is left untouched: no connection, no cursor advance.
        // Messages already captured are still delivered by mailspoon:deliver.
        if (! Mailspoon::route($name)->enabled()) {
            $this->warn("Mailbox [$name] is disabled in its route; skipping pull.");

            return;
        }

        $mailbox = Imap::mailbox($name);

        $with = self::messageParts($this->option('with'));

        $this->info("Checking mailbox [$name]...");

        $folder = $this->folder($mailbox);

        [$query, $cursor] = $this->select($folder, $name);

        $query = (new ConfigureIdleQuery($with))($query);

        $lastUid = $cursor?->last_uid ?? 0;

        // Fetch in bounded batches, oldest first: a single FETCH listing
        // thousands of UIDs (a fresh mailbox, a first run with a keyword/none
        // marker) exceeds the server's command length limit — Dovecot rejects
        // it with "Too long argument".
        $query->oldest()->chunk(function (MessageCollection $messages) use ($name, $cursor, &$lastUid) {
            foreach ($messages as $message) {
                Event::dispatch(new MessageReceived($message, $name));

                $lastUid = max($lastUid, $message->uid());
            }

            // Ascending order makes the cursor safe to persist per chunk: an
            // interrupted backlog run resumes here instead of re-fetching
            // (deduplication would drop re-captures, but not re-downloads).
            $cursor?->forceFill(['last_uid' => $lastUid])->save();
        }, $this->chunkSize());
    }

    /**
     * Resolve how many messages are fetched per FETCH command.
     */
    protected function chunkSize(): int
    {
        $chunk = (int) ($this->option('chunk') ?: config('mailspoon.pull.chunk', self::DEFAULT_CHUNK));

        return max(1, $chunk);
    }

    /**
     * Build the unprocessed-messages query for the mailbox's capture marker.
     *
     * `seen` selects unseen messages, `keyword` selects messages without the
     * keyword, and `none` selects by UID above the stored cursor.
     *
     * @return array{0: MessageQueryInterface, 1: ?RelayCursor}
     */
    protected function select(FolderInterface $folder, string $name): array
    {
        $marker = Mailspoon::route($name)->marker();

        if ($marker->mode === CaptureMarker::KEYWORD) {
            return [$folder->messages()->unkeyword($marker->keyword), null];
        }

        if ($marker->mode === CaptureMarker::NONE) {
            $cursor = $this->cursor($folder, $name);

            return [$folder->messages()->uid($cursor->last_uid + 1, INF), $cursor];
        }

        return [$folder->messages()->unseen(), null];
    }

    /**
     * Resolve the folder's UID cursor, restarting it when UIDVALIDITY changes.
     *
     * After a restart everything is re-fetched; deduplication by fingerprint
     * prevents re-delivery of messages that were already captured.
     */
    protected function cursor(FolderInterface $folder, string $name): RelayCursor
    {
        $validity = (int) ($folder->status()['UIDVALIDITY'] ?? 0);

        $cursor = RelayCursor::firstOrCreate(
            ['mailbox' => $name, 'folder' => $folder->path()],
            ['uidvalidity' => $validity],
        );

        if ($cursor->uidvalidity !== $validity) {
            $cursor->forceFill(['uidvalidity' => $validity, 'last_uid' => 0])->save();
        }

        return $cursor;
    }

    /**
     * Resolve the message parts that must be fetched from IMAP.
     *
     * @return list<string>
     */
    public static function messageParts(mixed $with): array
    {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string) $with)),
        ));

        return $parts ?: self::DEFAULT_WITH;
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
