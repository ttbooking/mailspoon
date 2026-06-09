<?php

use App\Console\Commands\DeliverMessagesCommand;
use App\Console\Commands\ImapPullCommand;
use App\Console\Commands\ImapSentryCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        ImapPullCommand::class,
        ImapSentryCommand::class,
        DeliverMessagesCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule) {
        // Flush stored messages to the endpoint. Needed in every mode, since
        // reading only stores messages — delivery happens here.
        if ($cron = config('spoon.schedule.deliver')) {
            $schedule->command('spoon:deliver')
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground();
        }

        // Optional cron-poll mode: pull each configured mailbox instead of
        // running a long-lived imap:sentry watcher.
        foreach (config('spoon.schedule.pull', []) as $mailbox => $cron) {
            $schedule->command('imap:pull', [$mailbox])
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground();
        }
    })
    ->withExceptions()
    ->create();
