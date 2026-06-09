<?php

namespace Tests;

use DirectoryTree\ImapEngine\Laravel\ImapServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;
use TTBooking\Mailspoon\MailspoonServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ImapServiceProvider::class,
            MailspoonServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);

        // The archive disk contract: ArchiveStorage refuses disks that
        // swallow filesystem errors (the skeleton default is throw => false).
        $app['config']->set('filesystems.disks.local.throw', true);
    }
}
