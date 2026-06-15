<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use TTBooking\Mailspoon\Facades\Mailspoon;
use TTBooking\Mailspoon\Results\Check;
use TTBooking\Mailspoon\Results\DoctorReport;
use TTBooking\Mailspoon\Services\Doctor;

#[AsCommand(name: 'mailspoon:doctor')]
final class DoctorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailspoon:doctor
        {mailbox?* : Mailbox names to check (defaults to all configured)}
        {--send : POST a signed test message to each endpoint}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check configuration, database, archive, IMAP connectivity and endpoints.';

    /**
     * Execute the console command.
     */
    public function handle(Doctor $doctor): int
    {
        $report = $doctor->run($this->argument('mailbox') ?: [], (bool) $this->option('send'));

        $this->renderReport($report);

        if (! $report->ok()) {
            $this->error('Some checks failed.');

            return self::FAILURE;
        }

        $this->info('All checks passed.');

        return self::SUCCESS;
    }

    /**
     * Render the report as grouped check lines with per-mailbox signature samples.
     */
    private function renderReport(DoctorReport $report): void
    {
        $this->line('General:');
        foreach ($report->checks as $check) {
            if ($check->mailbox === null) {
                $this->renderCheck($check);
            }
        }

        foreach ($this->mailboxes($report) as $mailbox) {
            $this->newLine();
            $this->line("Mailbox [{$mailbox}]:");

            foreach ($report->checks as $check) {
                if ($check->mailbox === $mailbox) {
                    $this->renderCheck($check);
                }
            }

            $this->signatureSample($mailbox);
        }

        $this->newLine();
    }

    /**
     * Mailbox names present in the report, in order of first appearance.
     *
     * @return list<string>
     */
    private function mailboxes(DoctorReport $report): array
    {
        $names = [];

        foreach ($report->checks as $check) {
            if ($check->mailbox !== null && ! in_array($check->mailbox, $names, true)) {
                $names[] = $check->mailbox;
            }
        }

        return $names;
    }

    /**
     * Render one check as a ✓/✗ line.
     */
    private function renderCheck(Check $check): void
    {
        $label = $this->label($check);

        if ($check->passed()) {
            $this->line("  <info>✓</info> {$label}".($check->message ? " — {$check->message}" : ''));

            return;
        }

        $this->line("  <error>✗</error> {$label} — {$check->message}");
    }

    /**
     * Human-readable label for a check.
     */
    private function label(Check $check): string
    {
        return match ($check->name) {
            'database' => 'database: relayed_messages table',
            'archive' => 'archive: disk is writable and throws errors',
            'route' => 'route: endpoint and signing key',
            'capture' => 'capture: mark and filters',
            'imap' => 'imap: connect and log in',
            'endpoint' => $this->option('send') ? 'endpoint: signed test message' : 'endpoint: reachable',
            default => $check->name,
        };
    }

    /**
     * Print a signature sample so the receiver's key can be verified offline.
     */
    private function signatureSample(string $name): void
    {
        $route = Mailspoon::route($name);

        if (! ($key = $route->key())) {
            return;
        }

        $source = $route->definesKey() ? "route:{$name}" : 'global';

        $this->line(sprintf(
            '  signature sample (key %s, timestamp=1700000000, token=test): %s',
            $source,
            hash_hmac('sha256', '1700000000test', $key),
        ));
    }
}
