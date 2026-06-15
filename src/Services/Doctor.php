<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Services;

use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use TTBooking\Mailspoon\Facades\Mailspoon;
use TTBooking\Mailspoon\Results\Check;
use TTBooking\Mailspoon\Results\CheckStatus;
use TTBooking\Mailspoon\Results\DoctorReport;
use TTBooking\Mailspoon\Support\CaptureMarker;
use TTBooking\Mailspoon\Support\MessageArchive;

/**
 * Diagnose configuration, database, archive, IMAP connectivity and endpoints.
 *
 * Each probe is captured into a {@see Check} rather than thrown, so a single
 * run yields the full picture — a {@see DoctorReport} fit for the console
 * command or a host's web layer (`response()->json($report)`).
 */
final class Doctor
{
    public function __construct(private readonly MessageArchive $archive) {}

    /**
     * Run the diagnostics.
     *
     * @param  list<string>  $mailboxes  Mailboxes to check; empty checks all configured.
     * @param  bool  $send  POST a signed test message to each endpoint instead of just probing it.
     */
    public function run(array $mailboxes = [], bool $send = false): DoctorReport
    {
        $names = $mailboxes ?: array_keys(config('imap.mailboxes', []));

        $checks = [
            $this->probe(null, 'database', $this->checkDatabase(...)),
            $this->probe(null, 'archive', $this->checkArchive(...)),
        ];

        foreach ($names as $name) {
            $checks[] = $this->probe($name, 'route', fn () => $this->checkRoute($name));
            $checks[] = $this->probe($name, 'capture', fn () => $this->checkCapture($name));
            $checks[] = $this->probe($name, 'imap', fn () => $this->checkImap($name));
            $checks[] = $this->probe($name, 'endpoint', fn () => $this->checkEndpoint($name, $send));
        }

        return new DoctorReport($checks);
    }

    /**
     * Run one probe, capturing success detail or the failure message.
     *
     * @param  callable(): ?string  $probe
     */
    private function probe(?string $mailbox, string $name, callable $probe): Check
    {
        try {
            return new Check($mailbox, $name, CheckStatus::Ok, $probe());
        } catch (Throwable $e) {
            return new Check($mailbox, $name, CheckStatus::Fail, $e->getMessage());
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
    private function checkArchive(): string
    {
        $path = $this->archive->store('mailspoon doctor probe', '<doctor-probe@mailspoon>', now(), '_doctor');

        $raw = $this->archive->get($path);

        $this->archive->delete($path);

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
        $endpoint = Mailspoon::route($name)->endpoint();

        if (! $endpoint) {
            throw new RuntimeException('endpoint is not configured (no route and no global mailspoon.endpoint)');
        }

        if (! filter_var($endpoint, FILTER_VALIDATE_URL) || ! str_starts_with($endpoint, 'http')) {
            throw new RuntimeException("endpoint [{$endpoint}] is not a valid URL");
        }

        if (! Mailspoon::route($name)->key()) {
            throw new RuntimeException('signing key is not configured (no route key and no global mailspoon.key)');
        }

        return $endpoint.(str_starts_with($endpoint, 'https://') ? '' : ' (not https!)');
    }

    /**
     * Validate the capture marker and filter rules for the mailbox.
     *
     * Constructing the matcher rejects broken regular expressions and unknown
     * fields, so a bad rule fails here instead of silently skipping mail.
     */
    private function checkCapture(string $name): string
    {
        $route = Mailspoon::route($name);

        $marker = $route->marker();

        // Constructing the matcher validates the filter rules.
        $route->matcher();

        return 'mark: '.$marker->describe().($marker->mode === CaptureMarker::KEYWORD
            ? ' — the server must allow custom keywords (PERMANENTFLAGS \*)'
            : '');
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
     * Probe the endpoint: OPTIONS by default, a signed POST with $send.
     *
     * Without $send any HTTP response counts as reachable — the goal is to
     * catch DNS/TLS/firewall problems without feeding test mail into the
     * receiving application.
     */
    private function checkEndpoint(string $name, bool $send): string
    {
        $endpoint = Mailspoon::route($name)->endpoint();

        if (! $endpoint) {
            throw new RuntimeException('cannot probe — no endpoint (see the route check)');
        }

        if (! $send) {
            $response = Http::timeout(10)->connectTimeout(5)->send('OPTIONS', $endpoint);

            return "reachable (HTTP {$response->status()})";
        }

        if (! ($key = Mailspoon::route($name)->key())) {
            throw new RuntimeException('cannot sign the test message — no signing key (see the route check)');
        }

        $timestamp = now()->getTimestamp();
        $token = bin2hex(random_bytes(25));

        $response = Http::asForm()->timeout(15)->connectTimeout(5)->post($endpoint, [
            'body-mime' => $this->testMime($name),
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, $key),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} — check the signing key and the receiving route");
        }

        return "accepted (HTTP {$response->status()})";
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
