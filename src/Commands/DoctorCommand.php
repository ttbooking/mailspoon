<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Commands;

use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use TTBooking\Mailspoon\Support\MessageArchive;

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
     * Whether any check has failed so far.
     */
    private bool $failed = false;

    /**
     * Execute the console command.
     */
    public function handle(MessageArchive $archive): int
    {
        $names = $this->argument('mailbox') ?: array_keys(config('imap.mailboxes', []));

        $this->line('General:');
        $this->check('database: relayed_messages table', $this->checkDatabase(...));
        $this->check('archive: disk is writable and throws errors', fn () => $this->checkArchive($archive));

        foreach ($names as $name) {
            $this->newLine();
            $this->line("Mailbox [{$name}]:");

            $this->check('route: endpoint and signing key', fn () => $this->checkRoute($name));
            $this->check('imap: connect and log in', fn () => $this->checkImap($name));
            $this->check(
                $this->option('send') ? 'endpoint: signed test message' : 'endpoint: reachable',
                fn () => $this->checkEndpoint($name),
            );

            $this->signatureSample($name);
        }

        $this->newLine();

        if ($this->failed) {
            $this->error('Some checks failed.');

            return self::FAILURE;
        }

        $this->info('All checks passed.');

        return self::SUCCESS;
    }

    /**
     * Run a single probe and report it as a check line.
     *
     * @param  callable(): ?string  $probe
     */
    private function check(string $label, callable $probe): void
    {
        try {
            $detail = $probe();

            $this->line("  <info>✓</info> {$label}".($detail ? " — {$detail}" : ''));
        } catch (Throwable $e) {
            $this->failed = true;

            $this->line("  <error>✗</error> {$label} — {$e->getMessage()}");
        }
    }

    /**
     * Ensure the journal table exists (migrations have run).
     */
    private function checkDatabase(): ?string
    {
        if (! Schema::hasTable('relayed_messages')) {
            throw new RuntimeException('table is missing — run `php artisan migrate`');
        }

        return null;
    }

    /**
     * Write, read back and delete a probe file on the archive disk.
     *
     * Also exercises the MessageArchive guard: a disk without
     * `'throw' => true` fails here instead of at capture time.
     */
    private function checkArchive(MessageArchive $archive): string
    {
        $path = $archive->store('mailspoon doctor probe', '<doctor-probe@mailspoon>', now(), '_doctor');

        $raw = $archive->get($path);

        $archive->delete($path);

        if ($raw !== 'mailspoon doctor probe') {
            throw new RuntimeException("probe file came back corrupted at [{$path}]");
        }

        return 'disk ['.config('mailspoon.archive.disk').']';
    }

    /**
     * Ensure the mailbox resolves to a usable endpoint and signing key.
     */
    private function checkRoute(string $name): string
    {
        $endpoint = $this->endpointFor($name);

        if (! $endpoint) {
            throw new RuntimeException('endpoint is not configured (no route and no global mailspoon.endpoint)');
        }

        if (! filter_var($endpoint, FILTER_VALIDATE_URL) || ! str_starts_with($endpoint, 'http')) {
            throw new RuntimeException("endpoint [{$endpoint}] is not a valid URL");
        }

        if (! $this->keyFor($name)) {
            throw new RuntimeException('signing key is not configured (no route key and no global mailspoon.key)');
        }

        return $endpoint.(str_starts_with($endpoint, 'https://') ? '' : ' (not https!)');
    }

    /**
     * Actually connect and authenticate against the IMAP server.
     */
    private function checkImap(string $name): string
    {
        $mailbox = Imap::mailbox($name);

        try {
            $mailbox->connect();
            $mailbox->inbox();

            return 'connected as ['.$mailbox->config('username').']';
        } finally {
            $mailbox->disconnect();
        }
    }

    /**
     * Probe the endpoint: OPTIONS by default, a signed POST with --send.
     *
     * Without --send any HTTP response counts as reachable — the goal is to
     * catch DNS/TLS/firewall problems without feeding test mail into the
     * receiving application.
     */
    private function checkEndpoint(string $name): string
    {
        $endpoint = $this->endpointFor($name);

        if (! $this->option('send')) {
            $response = Http::timeout(10)->connectTimeout(5)->send('OPTIONS', $endpoint);

            return "reachable (HTTP {$response->status()})";
        }

        $timestamp = now()->getTimestamp();
        $token = bin2hex(random_bytes(25));

        $response = Http::asForm()->timeout(15)->connectTimeout(5)->post($endpoint, [
            'body-mime' => $this->testMime($name),
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, (string) $this->keyFor($name)),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} — check the signing key and the receiving route");
        }

        return "accepted (HTTP {$response->status()})";
    }

    /**
     * Print a signature sample so the receiver's key can be verified offline.
     */
    private function signatureSample(string $name): void
    {
        if (! ($key = $this->keyFor($name))) {
            return;
        }

        $source = config("mailspoon.routes.{$name}.key") !== null ? "route:{$name}" : 'global';

        $this->line(sprintf(
            '  signature sample (key %s, timestamp=1700000000, token=test): %s',
            $source,
            hash_hmac('sha256', '1700000000test', $key),
        ));
    }

    /**
     * Resolve the endpoint for the mailbox route, or the global default.
     */
    private function endpointFor(string $name): ?string
    {
        return config("mailspoon.routes.{$name}.endpoint") ?? config('mailspoon.endpoint');
    }

    /**
     * Resolve the signing key for the mailbox route, or the global default.
     */
    private function keyFor(string $name): ?string
    {
        return config("mailspoon.routes.{$name}.key") ?? config('mailspoon.key');
    }

    /**
     * Build a minimal, clearly marked test message.
     */
    private function testMime(string $name): string
    {
        return implode("\r\n", [
            'From: mailspoon-doctor@localhost',
            'To: '.$name.'@localhost',
            'Subject: Mailspoon doctor test message',
            'Message-Id: <doctor-'.bin2hex(random_bytes(8)).'@mailspoon>',
            'X-Mailspoon-Doctor: true',
            '',
            'This is a connectivity test sent by `mailspoon:doctor --send`.',
        ]);
    }
}
