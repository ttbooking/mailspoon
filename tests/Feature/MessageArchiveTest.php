<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use TTBooking\Mailspoon\Support\MessageArchive;

beforeEach(function () {
    Storage::fake('local');
});

it('stores raw MIME under the mailbox and date and reads it back', function () {
    $archive = app(MessageArchive::class);

    $path = $archive->store(
        'RAW-MIME-BODY',
        '<id@mailspoon.test>',
        Carbon::parse('2026-06-11'),
        'support',
    );

    expect($path)->toBe('mailspoon/support/2026/06/11/id@mailspoon.test.eml')
        ->and($archive->get($path))->toBe('RAW-MIME-BODY');

    Storage::disk('local')->assertExists($path);
});

it('returns null for a missing archived message', function () {
    expect(app(MessageArchive::class)->get('mailspoon/nope.eml'))->toBeNull();
});

it('deletes an archived message and tolerates an already-missing one', function () {
    $archive = app(MessageArchive::class);

    $path = $archive->store('RAW', '<id@mailspoon.test>', now(), 'default');

    $archive->delete($path);
    Storage::disk('local')->assertMissing($path);

    // Deleting again must not throw.
    $archive->delete($path);
});

it('rejects an archive disk that suppresses filesystem errors', function () {
    config(['filesystems.disks.local.throw' => false]);

    expect(fn () => app(MessageArchive::class)->get('mailspoon/any.eml'))
        ->toThrow(InvalidArgumentException::class, 'must be configured with [throw => true]');
});
