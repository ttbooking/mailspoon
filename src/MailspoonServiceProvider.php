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
use TTBooking\Mailspoon\Facades\Mailspoon;
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

        // Runtime route registry. Routes are read from config by default and
        // can be added or overridden at runtime via Mailspoon::register(),
        // letting a host application drive them from its own storage.
        $this->app->singleton(MailspoonManager::class);
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

        if (config('mailspoon.retention.days', 0) > 0 && ($cron = config('mailspoon.schedule.prune'))) {
            $schedule->command('model:prune', [
                '--model' => RelayedMessage::class,
            ])
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground();
        }

        // Optional cron-poll mode: pull each scheduled mailbox instead of
        // running a long-lived mailspoon:sentry watcher. The schedule map comes
        // from the route registry (config or runtime registrations), so a host
        // can poll a registered mailbox without editing config. A mailbox
        // paused in its route (`enabled => false`) is not registered at all.
        foreach (Mailspoon::schedules() as $mailbox => $cron) {
            if (empty($cron) || ! Mailspoon::route($mailbox)->enabled()) {
                continue;
            }

            $schedule->command('mailspoon:pull', [$mailbox])
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground();
        }
    }
}
