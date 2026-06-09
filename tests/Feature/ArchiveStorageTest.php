<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use TTBooking\Mailspoon\Support\ArchiveStorage;

it('resolves an archive disk configured to throw filesystem errors', function () {
    Config::set('filesystems.disks.archive', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/archive'),
        'throw' => true,
    ]);

    Storage::fake('archive');

    expect(ArchiveStorage::disk('archive'))->toBeInstanceOf(FilesystemAdapter::class);
});

it('rejects an archive disk that suppresses filesystem errors', function () {
    Config::set('filesystems.disks.archive', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/archive'),
        'throw' => false,
    ]);

    expect(fn () => ArchiveStorage::disk('archive'))
        ->toThrow(InvalidArgumentException::class, 'must be configured with [throw => true]');
});
