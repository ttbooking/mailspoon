<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mailspoon Relay Configuration
    |--------------------------------------------------------------------------
    */

    'endpoint' => env('SPOON_ENDPOINT'),
    'key' => env('SPOON_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Message Archive
    |--------------------------------------------------------------------------
    |
    | Incoming messages are stored to durable storage before they are marked as
    | read, so reading the mailbox is decoupled from webhook delivery. The raw
    | MIME is written to the disk below and delivered later by `spoon:deliver`.
    |
    */

    'archive' => [
        'disk' => env('SPOON_ARCHIVE_DISK', 'local'),
        'path' => env('SPOON_ARCHIVE_PATH', 'mailspoon'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | A failed message is retried on subsequent `spoon:deliver` runs until it
    | reaches this many attempts, after which it is left for manual inspection.
    |
    */

    'delivery' => [
        'max_attempts' => (int) env('SPOON_MAX_ATTEMPTS', 10),
    ],

];
