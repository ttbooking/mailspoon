<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mailspoon Relay Configuration
    |--------------------------------------------------------------------------
    */

    'endpoint' => env('MAILSPOON_ENDPOINT'),
    'key' => env('MAILSPOON_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Mailbox Routes
    |--------------------------------------------------------------------------
    |
    | Per-mailbox delivery targets, keyed by the mailbox name from
    | config/imap.php. A mailbox without a route (or with a partial one) falls
    | back to the global endpoint and key above. The endpoint is fixed on the
    | record when the message is captured; the signing key is resolved at
    | delivery time, so key rotation applies to pending messages immediately.
    |
    | A route may also override the capture marker (`mark`) and replace the
    | message filters (`filters`) for its mailbox — see the sections below.
    |
    */

    'routes' => [
        // 'support' => [
        //     // Delivery target; either one falls back to the globals above.
        //     'endpoint' => 'https://support.example.com/api/mailgun/mime',
        //     'key' => 'key-support',
        //
        //     // Optional: how viewed messages are marked in this mailbox
        //     // (`seen`, `keyword:<name>` or `none`) — overrides `mark` below.
        //     'mark' => 'keyword:Mailspoon',
        //
        //     // Optional: capture rules for this mailbox — replace the global
        //     // `filters` below entirely (they are not merged).
        //     'filters' => [
        //         'allow' => ['subject' => ['/invoice/i']],
        //         'deny' => ['from' => ['no-reply@*']],
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture Marker
    |--------------------------------------------------------------------------
    |
    | How mailspoon marks messages it has already viewed. `seen` (default)
    | uses the \Seen flag — right for robot mailboxes. For mailboxes read by
    | humans the read state must stay untouched: `keyword:<name>` marks with
    | a custom IMAP keyword invisible in mail clients (the server must allow
    | custom keywords — PERMANENTFLAGS \*), and `none` leaves the message
    | alone entirely, tracking position by UID instead (cron-poll only).
    | A route may define its own `mark`, which overrides this one.
    |
    */

    'mark' => env('MAILSPOON_MARK', 'seen'),

    /*
    |--------------------------------------------------------------------------
    | Message Filters
    |--------------------------------------------------------------------------
    |
    | Include/exclude rules applied before a message is captured: a filtered
    | message is not journaled, archived or relayed. A deny match always wins;
    | an empty allow list allows everything. Patterns are regular expressions
    | when delimited (`/invoice/i`) and case-insensitive wildcards otherwise
    | (`*@trusted.com`). Fields: from, subject, header, has_attachment.
    | A route may define its own `filters`, which then replace these.
    |
    */

    'filters' => [
        // 'allow' => ['subject' => ['/invoice/i']],
        // 'deny' => [
        //     'from' => ['no-reply@*', 'mailer-daemon@*'],
        //     'header' => ['Auto-Submitted' => 'auto-replied'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Message Archive
    |--------------------------------------------------------------------------
    |
    | Incoming messages are stored to durable storage before they are marked as
    | read, so reading the mailbox is decoupled from webhook delivery. The raw
    | MIME is written to the disk below, delivered later by `mailspoon:deliver`.
    |
    */

    'archive' => [
        'disk' => env('MAILSPOON_ARCHIVE_DISK', 'local'),
        'path' => env('MAILSPOON_ARCHIVE_PATH', 'mailspoon'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | `mailspoon:deliver` posts stored messages to the endpoint. Each attempt has a
    | short in-process retry to absorb transient blips (network errors, 5xx,
    | 429). If it still fails, the message is rescheduled using the back-off
    | schedule below — it is only retried on a later run once that delay has
    | passed — until it reaches `max_attempts`.
    |
    */

    'delivery' => [
        'max_attempts' => (int) env('MAILSPOON_MAX_ATTEMPTS', 10),

        // Total request timeout, and a separate cap on the TCP connect phase
        // so a stalled handshake can't hang the worker.
        'timeout' => (int) env('MAILSPOON_TIMEOUT', 15),
        'connect_timeout' => (int) env('MAILSPOON_CONNECT_TIMEOUT', 3),

        // In-process retries per attempt for transient failures.
        'retries' => (int) env('MAILSPOON_TRIES', 3),

        // Back-off (seconds) between runs, indexed by attempt number.
        'backoff' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('MAILSPOON_BACKOFF', '60,300,900,3600'))
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
        'days' => (int) env('MAILSPOON_RETENTION_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Optional cron schedule, run by `php artisan schedule:run`.
    | `mailspoon:deliver` is scheduled by default since stored messages must be
    | flushed in every mode. The `pull` map lets you poll mailboxes with
    | `mailspoon:pull` instead of running a long-lived `mailspoon:sentry`
    | watcher; it is empty by default. Set a cron value to empty to disable a
    | task.
    |
    */

    'schedule' => [
        'deliver' => env('MAILSPOON_DELIVER_CRON', '* * * * *'),
        'prune' => env('MAILSPOON_PRUNE_CRON', '0 3 * * *'),

        // Mailbox name => cron expression. Override in the published config,
        // e.g. 'default' => '*/5 * * * *'.
        'pull' => [],
    ],

];
