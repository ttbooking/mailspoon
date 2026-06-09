<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ArchiveStorage
{
    /**
     * Resolve an archive disk configured to throw on filesystem failures.
     */
    public static function disk(string $name): Filesystem
    {
        if (config("filesystems.disks.{$name}.throw") !== true) {
            throw new InvalidArgumentException(
                "Archive disk [{$name}] must be configured with [throw => true]."
            );
        }

        return Storage::disk($name);
    }
}
