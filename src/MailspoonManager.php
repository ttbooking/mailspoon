<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon;

use TTBooking\Mailspoon\Contracts\Route;
use TTBooking\Mailspoon\Support\MailboxRoute;

/**
 * Runtime registry of per-mailbox routes, mirroring imapengine's ImapManager.
 *
 * A route describes the delivery side of a mailbox — endpoint, signing key,
 * capture marker, filters and whether it is enabled. Routes are read from
 * `config('mailspoon.routes')` and may be added or overridden at runtime with
 * register(), so a host application can drive them from its own storage (a
 * database, cache, ...) instead of the published config file:
 *
 *     Mailspoon::register('tenant-42', [
 *         'endpoint' => 'https://tenant-42.example.com/api/mailgun/mime',
 *         'key' => 'key-42',
 *     ]);
 */
final class MailspoonManager
{
    /**
     * Routes registered at runtime, keyed by mailbox name. These take
     * precedence over the published config.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $routes = [];

    /**
     * Register or override the route for a mailbox at runtime.
     *
     * A registered route replaces the mailbox's config route entirely; it is
     * not merged (the same rule the per-mailbox filters and marker follow).
     *
     * @param  array<string, mixed>  $route
     */
    public function register(string $mailbox, array $route): void
    {
        $this->routes[$mailbox] = $route;
    }

    /**
     * Resolve the route for a mailbox as a first-class object.
     *
     * A runtime registration wins; otherwise the published config is read.
     * Mailbox names may contain dots (`noreply@example.ru`), so the config map
     * is indexed by plain key, not dot notation. An unknown mailbox yields a
     * route backed by empty options (it falls back to the globals throughout).
     */
    public function route(string $mailbox): Route
    {
        $options = $this->routes[$mailbox] ?? config('mailspoon.routes', [])[$mailbox] ?? [];

        // The global fallbacks a route inherits where it leaves an option
        // unset. Read here so the route object itself stays free of config.
        $defaults = [
            'endpoint' => config('mailspoon.endpoint'),
            'key' => config('mailspoon.key'),
            'mark' => config('mailspoon.mark'),
            'filters' => config('mailspoon.filters', []),
        ];

        return new MailboxRoute($options, $defaults);
    }

    /**
     * All known routes keyed by mailbox: the published config as the baseline,
     * runtime registrations layered on top.
     *
     * @return array<string, array<string, mixed>>
     */
    public function routes(): array
    {
        return array_merge(config('mailspoon.routes', []), $this->routes);
    }

    /**
     * Mailboxes to poll on a schedule, as `mailbox => cron expression`.
     *
     * Assembled from the legacy `schedule.pull` config map and any route that
     * carries its own `schedule` (from config or a runtime registration), so a
     * host can start polling a registered mailbox without editing config. A
     * route-level schedule takes precedence over the legacy map entry for the
     * same mailbox.
     *
     * @return array<string, string>
     */
    public function schedules(): array
    {
        $schedules = config('mailspoon.schedule.pull', []);

        foreach ($this->routes() as $mailbox => $options) {
            if (isset($options['schedule'])) {
                $schedules[$mailbox] = $options['schedule'];
            }
        }

        return $schedules;
    }
}
