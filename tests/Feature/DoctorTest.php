<?php

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use TTBooking\Mailspoon\Results\Check;
use TTBooking\Mailspoon\Results\CheckStatus;
use TTBooking\Mailspoon\Results\DoctorReport;
use TTBooking\Mailspoon\Services\Doctor;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config([
        'mailspoon.endpoint' => 'https://hook.test/mime',
        'mailspoon.key' => 'secret-key',
        'imap.mailboxes' => ['default' => ['host' => 'imap.test']],
    ]);
});

function doctorImapMock(string $name = 'default'): void
{
    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('connect')->once();
    $mailbox->shouldReceive('inbox')->once()->andReturn(Mockery::mock(FolderInterface::class));
    $mailbox->shouldReceive('config')->with('username')->andReturn('user@example.test');
    $mailbox->shouldReceive('disconnect')->once();

    Imap::shouldReceive('mailbox')->with($name)->andReturn($mailbox);
}

function checkFor(DoctorReport $report, ?string $mailbox, string $name): Check
{
    foreach ($report->checks as $check) {
        if ($check->mailbox === $mailbox && $check->name === $name) {
            return $check;
        }
    }

    throw new RuntimeException("no check [{$name}] for mailbox [".($mailbox ?? 'general').']');
}

it('returns a passing report when everything is healthy', function () {
    Http::fake(['*' => Http::response('', 204)]);
    doctorImapMock();

    $report = app(Doctor::class)->run();

    expect($report->ok())->toBeTrue()
        ->and($report->failures())->toBe([])
        ->and(checkFor($report, null, 'database')->status)->toBe(CheckStatus::Ok)
        ->and(checkFor($report, 'default', 'imap')->message)->toBe('connected as [user@example.test]');
});

it('captures a failed imap login as a fail check without aborting the run', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('connect')->once()->andThrow(new RuntimeException('LOGIN failed'));
    $mailbox->shouldReceive('disconnect')->once();
    Imap::shouldReceive('mailbox')->with('default')->andReturn($mailbox);

    $report = app(Doctor::class)->run();

    $imap = checkFor($report, 'default', 'imap');

    expect($report->ok())->toBeFalse()
        ->and($imap->status)->toBe(CheckStatus::Fail)
        ->and($imap->message)->toContain('LOGIN failed')
        // Other checks still ran and passed.
        ->and(checkFor($report, 'default', 'route')->passed())->toBeTrue();
});

it('fails the route check when no endpoint resolves', function () {
    config(['mailspoon.endpoint' => null]);
    Http::fake();
    doctorImapMock();

    $report = app(Doctor::class)->run();

    expect(checkFor($report, 'default', 'route')->status)->toBe(CheckStatus::Fail)
        ->and(checkFor($report, 'default', 'route')->message)->toContain('endpoint is not configured');
});

it('reports the resolved capture marker', function () {
    config(['mailspoon.routes.default.mark' => 'keyword:Mailspoon']);
    Http::fake(['*' => Http::response('', 204)]);
    doctorImapMock();

    expect(checkFor(app(Doctor::class)->run(), 'default', 'capture')->message)
        ->toContain('keyword [Mailspoon]');
});

it('warns when a mailbox has no cron-pull schedule, without failing the run', function () {
    config(['mailspoon.schedule.pull' => []]);
    Http::fake(['*' => Http::response('', 204)]);
    doctorImapMock();

    $report = app(Doctor::class)->run();

    $schedule = checkFor($report, 'default', 'schedule');

    expect($schedule->status)->toBe(CheckStatus::Warn)
        ->and($schedule->message)->toContain('no cron-pull schedule')
        // A warning is advisory: the run is still ok and has no failures.
        ->and($report->ok())->toBeTrue()
        ->and($report->failures())->toBe([])
        ->and($report->warnings())->toContain($schedule);
});

it('passes the schedule check when a config schedule is set', function () {
    config(['mailspoon.schedule.pull' => ['default' => '*/5 * * * *']]);
    Http::fake(['*' => Http::response('', 204)]);
    doctorImapMock();

    $schedule = checkFor(app(Doctor::class)->run(), 'default', 'schedule');

    expect($schedule->status)->toBe(CheckStatus::Ok)
        ->and($schedule->message)->toContain('*/5 * * * *');
});

it('passes the schedule check when a route carries its own schedule', function () {
    config([
        'mailspoon.schedule.pull' => [],
        'mailspoon.routes.default.schedule' => '*/10 * * * *',
    ]);
    Http::fake(['*' => Http::response('', 204)]);
    doctorImapMock();

    $schedule = checkFor(app(Doctor::class)->run(), 'default', 'schedule');

    expect($schedule->status)->toBe(CheckStatus::Ok)
        ->and($schedule->message)->toContain('*/10 * * * *');
});

it('warns about a paused mailbox that has no schedule, so setup gaps are not hidden', function () {
    // Doctor is a preflight tool, often run while a mailbox is still paused
    // during setup: a missing schedule must surface then, not stay hidden.
    config([
        'mailspoon.schedule.pull' => [],
        'mailspoon.routes.default.enabled' => false,
    ]);
    Http::fake(['*' => Http::response('', 204)]);
    doctorImapMock();

    $schedule = checkFor(app(Doctor::class)->run(), 'default', 'schedule');

    expect($schedule->status)->toBe(CheckStatus::Warn)
        ->and($schedule->message)->toContain('paused')
        ->and($schedule->message)->toContain('no cron-pull schedule');
});

it('passes a paused mailbox that does have a schedule, noting the pause', function () {
    config([
        'mailspoon.schedule.pull' => ['default' => '*/5 * * * *'],
        'mailspoon.routes.default.enabled' => false,
    ]);
    Http::fake(['*' => Http::response('', 204)]);
    doctorImapMock();

    $schedule = checkFor(app(Doctor::class)->run(), 'default', 'schedule');

    expect($schedule->status)->toBe(CheckStatus::Ok)
        ->and($schedule->message)->toContain('paused')
        ->and($schedule->message)->toContain('*/5 * * * *');
});

it('checks only the requested mailbox', function () {
    config(['imap.mailboxes' => ['default' => [], 'secondary' => []]]);
    Http::fake(['*' => Http::response('', 204)]);
    doctorImapMock('secondary');

    $report = app(Doctor::class)->run(['secondary']);

    expect($report->checks)->each->toBeInstanceOf(Check::class)
        ->and(collect($report->checks)->pluck('mailbox')->unique()->filter()->values()->all())
        ->toBe(['secondary']);
});

it('serializes to a json-friendly structure', function () {
    Http::fake(['*' => Http::response('', 204)]);
    doctorImapMock();

    $array = app(Doctor::class)->run()->toArray();

    expect($array)->toHaveKeys(['ok', 'checks'])
        ->and($array['ok'])->toBeTrue()
        ->and($array['checks'][0])->toBe([
            'mailbox' => null,
            'name' => 'database',
            'status' => 'ok',
            'message' => null,
        ]);
});
