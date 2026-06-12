<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use TTBooking\Mailspoon\Commands\DeliverMessagesCommand;
use TTBooking\Mailspoon\Commands\DoctorCommand;
use TTBooking\Mailspoon\Commands\ImapPullCommand;
use TTBooking\Mailspoon\Commands\ImapSentryCommand;
use TTBooking\Mailspoon\Commands\ReplayMessagesCommand;
use TTBooking\Mailspoon\Commands\TidyMessagesCommand;
use TTBooking\Mailspoon\Listeners\StoreIncomingMessage;
use TTBooking\Mailspoon\Models\RelayedMessage;

final class MailspoonServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailspoon.php', 'mailspoon');
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Package listeners are not auto-discovered by the host application.
        Event::listen(MessageReceived::class, StoreIncomingMessage::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mailspoon.php' => config_path('mailspoon.php'),
            ], 'mailspoon-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'mailspoon-migrations');

            $this->commands([
                ImapPullCommand::class,
                ImapSentryCommand::class,
                DeliverMessagesCommand::class,
                ReplayMessagesCommand::class,
                TidyMessagesCommand::class,
                DoctorCommand::class,
            ]);

            $this->callAfterResolving(Schedule::class, $this->schedule(...));
        }
    }

    /**
     * Register the package tasks on the host application's scheduler.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Flush stored messages to the endpoint. Needed in every mode, since
        // reading only stores messages — delivery happens here.
        if ($cron = config('mailspoon.schedule.deliver')) {
            $schedule->command('mailspoon:deliver')
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground();
        }

        // Apply after-relay actions to messages whose final outcome is known.
        // Cheap no-op (no IMAP connection) when no actions are configured.
        if ($cron = config('mailspoon.schedule.tidy')) {
            $schedule->command('mailspoon:tidy')
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground();
        }

        if (config('mailspoon.retention.days', 0) > 0 && ($cron = config('mailspoon.schedule.prune'))) {
            $schedule->command('model:prune', [
                '--model' => RelayedMessage::class,
            ])
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground();
        }

        // Optional cron-poll mode: pull each configured mailbox instead of
        // running a long-lived mailspoon:sentry watcher.
        foreach (config('mailspoon.schedule.pull', []) as $mailbox => $cron) {
            $schedule->command('mailspoon:pull', [$mailbox])
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground();
        }
    }
}
