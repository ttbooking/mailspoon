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
    | `spoon:deliver` posts stored messages to the endpoint. Each attempt has a
    | short in-process retry to absorb transient blips (network errors, 5xx,
    | 429). If it still fails, the message is rescheduled using the back-off
    | schedule below — it is only retried on a later run once that delay has
    | passed — until it reaches `max_attempts`.
    |
    */

    'delivery' => [
        'max_attempts' => (int) env('SPOON_MAX_ATTEMPTS', 10),

        // Total request timeout, and a separate cap on the TCP connect phase
        // so a stalled handshake can't hang the worker.
        'timeout' => (int) env('SPOON_TIMEOUT', 15),
        'connect_timeout' => (int) env('SPOON_CONNECT_TIMEOUT', 3),

        // In-process retries per attempt for transient failures.
        'retries' => (int) env('SPOON_TRIES', 3),

        // Back-off (seconds) between runs, indexed by attempt number.
        'backoff' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('SPOON_BACKOFF', '60,300,900,3600'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Completed message records and their archived MIME are pruned together.
    | The default retention period is three days; zero disables pruning.
    |
    */

    'retention' => [
        'days' => (int) env('SPOON_RETENTION_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Optional cron schedule, run by `php artisan schedule:run`. `spoon:deliver`
    | is scheduled by default since stored messages must be flushed in every
    | mode. The `pull` map lets you poll mailboxes with `imap:pull` instead of
    | running a long-lived `imap:sentry` watcher; it is empty by default. Set a
    | cron value to empty to disable a task.
    |
    */

    'schedule' => [
        'deliver' => env('SPOON_DELIVER_CRON', '* * * * *'),
        'prune' => env('SPOON_PRUNE_CRON', '0 3 * * *'),

        // JSON object: {"default":"*/5 * * * *","secondary":"0 * * * *"}.
        'pull' => json_decode(
            env('SPOON_PULL_SCHEDULE', '{}'),
            flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR,
        ),
    ],

];
