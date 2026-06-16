<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use DirectoryTree\ImapEngine\FileMessage;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use TTBooking\Mailspoon\Results\FilterTestResult;
use TTBooking\Mailspoon\Services\FilterTester;

#[AsCommand(name: 'mailspoon:filter-test')]
final class FilterTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailspoon:filter-test
        {mailbox : Mailbox whose filter rules to apply}
        {--uid= : Test the message with this UID, fetched from the mailbox}
        {--file= : Test a raw MIME message read from this file (.eml)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dry-run a message against a mailbox filter rules (no capture, works on a paused mailbox).';

    /**
     * Execute the console command.
     */
    public function handle(FilterTester $tester): int
    {
        $mailbox = $this->argument('mailbox');

        try {
            $message = $this->resolveMessage($mailbox);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        if ($message === null) {
            return self::INVALID;
        }

        try {
            $result = $tester->test($mailbox, $message);
        } catch (InvalidArgumentException $e) {
            // A malformed filter rule (unknown field, broken regex) surfaces here.
            $this->error("Filter rules for [{$mailbox}] are invalid: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->render($result);

        return self::SUCCESS;
    }

    /**
     * Build the message to test from exactly one of --file or --uid.
     *
     * @throws InvalidArgumentException on a usage error (none/both, missing file).
     */
    private function resolveMessage(string $mailbox): ?MessageInterface
    {
        $file = $this->option('file');
        $uid = $this->option('uid');

        if (($file === null) === ($uid === null)) {
            throw new InvalidArgumentException('Pass exactly one of --file or --uid.');
        }

        if ($file !== null) {
            if (! is_file($file)) {
                throw new InvalidArgumentException("File [{$file}] does not exist.");
            }

            return new FileMessage((string) file_get_contents($file));
        }

        // --uid: fetch straight from the mailbox. This does not honour the
        // route's `enabled` flag — a dry-run is just as valid for a paused
        // mailbox being set up.
        try {
            $message = Imap::mailbox($mailbox)->inbox()->messages()
                ->withHeaders()->withBody()->find((int) $uid);
        } catch (Throwable $e) {
            $this->error("Could not fetch UID [{$uid}] from [{$mailbox}]: {$e->getMessage()}");

            return null;
        }

        if ($message === null) {
            $this->error("No message with UID [{$uid}] in mailbox [{$mailbox}].");
        }

        return $message;
    }

    /**
     * Print the verdict as a single clear line.
     */
    private function render(FilterTestResult $result): void
    {
        if ($result->passes) {
            $this->line("<info>✓ PASS</info> — message would be captured and relayed ({$result->reason()})");

            return;
        }

        $this->line("<comment>✗ FILTERED</comment> — message would be dropped ({$result->reason()})");
    }
}
