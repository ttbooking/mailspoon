<?php

use TTBooking\Mailspoon\Facades\Mailspoon;
use TTBooking\Mailspoon\MailspoonManager;

it('resolves a route from config when none is registered', function () {
    config(['mailspoon.routes' => ['support' => ['endpoint' => 'https://support.test/mime']]]);

    expect(Mailspoon::route('support')->endpoint())->toBe('https://support.test/mime');
});

it('falls back to the global endpoint and key for an unknown mailbox', function () {
    config(['mailspoon.endpoint' => 'https://global.test/mime', 'mailspoon.key' => 'global-key']);

    $route = Mailspoon::route('nobody');

    expect($route->endpoint())->toBe('https://global.test/mime')
        ->and($route->key())->toBe('global-key')
        ->and($route->definesKey())->toBeFalse()
        ->and($route->enabled())->toBeTrue();
});

it('registers a route at runtime', function () {
    Mailspoon::register('tenant-42', ['endpoint' => 'https://t42.test/mime', 'key' => 'k42']);

    $route = Mailspoon::route('tenant-42');

    expect($route->endpoint())->toBe('https://t42.test/mime')
        ->and($route->key())->toBe('k42')
        ->and($route->definesKey())->toBeTrue();
});

it('lets a registered route override the config route', function () {
    config(['mailspoon.routes' => ['support' => ['endpoint' => 'https://config.test/mime']]]);

    Mailspoon::register('support', ['endpoint' => 'https://runtime.test/mime']);

    expect(Mailspoon::route('support')->endpoint())->toBe('https://runtime.test/mime');
});

it('replaces the config route entirely rather than merging it', function () {
    config([
        'mailspoon.key' => null,
        'mailspoon.routes' => ['support' => [
            'endpoint' => 'https://config.test/mime',
            'key' => 'config-key',
        ]],
    ]);

    Mailspoon::register('support', ['endpoint' => 'https://runtime.test/mime']);

    // The registered route dropped the key, so it falls back to the (unset)
    // global rather than keeping the config route's key.
    expect(Mailspoon::route('support')->key())->toBeNull()
        ->and(Mailspoon::route('support')->definesKey())->toBeFalse();
});

it('merges config and runtime routes, runtime winning', function () {
    config(['mailspoon.routes' => ['a' => ['key' => 'a'], 'b' => ['key' => 'b']]]);

    Mailspoon::register('b', ['key' => 'b2']);
    Mailspoon::register('c', ['key' => 'c']);

    expect(Mailspoon::routes())->toBe([
        'a' => ['key' => 'a'],
        'b' => ['key' => 'b2'],
        'c' => ['key' => 'c'],
    ]);
});

it('reads a route for a dotted mailbox name by plain key', function () {
    config(['mailspoon.routes' => ['noreply@example.ru' => ['enabled' => false]]]);

    expect(Mailspoon::route('noreply@example.ru')->enabled())->toBeFalse();
});

it('treats a mailbox as enabled unless its route disables it', function () {
    expect(Mailspoon::route('whatever')->enabled())->toBeTrue();

    Mailspoon::register('paused', ['enabled' => false]);

    expect(Mailspoon::route('paused')->enabled())->toBeFalse();
});

it('assembles pull schedules from the config map and route schedules', function () {
    config([
        'mailspoon.schedule.pull' => ['legacy' => '0 * * * *'],
        'mailspoon.routes' => ['support' => ['schedule' => '*/10 * * * *']],
    ]);

    Mailspoon::register('tenant', ['schedule' => '*/5 * * * *']);

    expect(Mailspoon::schedules())->toBe([
        'legacy' => '0 * * * *',
        'support' => '*/10 * * * *',
        'tenant' => '*/5 * * * *',
    ]);
});

it('lets a route schedule override a legacy pull entry for the same mailbox', function () {
    config([
        'mailspoon.schedule.pull' => ['default' => '0 * * * *'],
        'mailspoon.routes' => ['default' => ['schedule' => '*/5 * * * *']],
    ]);

    expect(Mailspoon::schedules()['default'])->toBe('*/5 * * * *');
});

it('resolves the facade to the singleton manager', function () {
    Mailspoon::register('x', ['key' => 'y']);

    expect(app(MailspoonManager::class)->route('x')->key())->toBe('y');
});
