<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon;

use Illuminate\Support\ServiceProvider;

/**
 * Package skeleton stub: config merging, migrations, listener and schedule
 * registration land here in the next stage of the package conversion (#21).
 */
class MailspoonServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spoon.php', 'spoon');
    }
}
