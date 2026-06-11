<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Support;

use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Durable storage for the raw MIME of captured messages.
 *
 * Owns the archive layout ({base}/{mailbox}/{Y/m/d}/{fingerprint}.eml) so the
 * capture, delivery and pruning sides never compose paths themselves.
 */
final readonly class MessageArchive
{
    public function __construct(
        #[Config('mailspoon.archive.disk')] private string $disk,
        #[Config('mailspoon.archive.path')] private string $basePath,
    ) {}

    /**
     * Store raw MIME and return its archive path.
     *
     * The mailbox name is part of the path so that copies of one message
     * captured from different mailboxes do not overwrite each other.
     */
    public function store(string $raw, string $fingerprint, CarbonInterface $receivedAt, string $mailbox): string
    {
        $path = sprintf(
            '%s/%s/%s/%s.eml',
            trim($this->basePath, '/'),
            $this->pathSegment($mailbox),
            $receivedAt->format('Y/m/d'),
            $this->pathSegment($fingerprint),
        );

        $this->disk()->put($path, $raw);

        return $path;
    }

    /**
     * Read an archived raw MIME, or null when it is missing.
     */
    public function get(string $path): ?string
    {
        $disk = $this->disk();

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    /**
     * Remove an archived message; a already-missing one is not an error.
     */
    public function delete(string $path): void
    {
        $disk = $this->disk();

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    /**
     * Resolve the archive disk, requiring it to throw filesystem errors.
     *
     * A disk that swallows failures would let a message be marked captured
     * (and seen) without its MIME actually persisted. Resolved per call so
     * runtime reconfiguration (and Storage::fake in tests) is respected.
     */
    private function disk(): Filesystem
    {
        if (config("filesystems.disks.{$this->disk}.throw") !== true) {
            throw new InvalidArgumentException(
                "Archive disk [{$this->disk}] must be configured with [throw => true]."
            );
        }

        return Storage::disk($this->disk);
    }

    /**
     * Sanitize a value for use as a single archive path segment.
     */
    private function pathSegment(string $value): string
    {
        return Str::of($value)
            ->replace(['<', '>', '/', '\\', ':'], '_')
            ->trim('_.')
            ->value();
    }
}
