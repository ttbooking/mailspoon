<?php

use TTBooking\Mailspoon\Support\CaptureMarker;
use TTBooking\Mailspoon\Support\MailboxRoute;
use TTBooking\Mailspoon\Support\MessageMatcher;

// MailboxRoute is a pure value object: it reads no global state, so these
// exercise it with plain arrays — no config, no application boot.

it('prefers its own options over the defaults', function () {
    $route = new MailboxRoute(
        options: ['endpoint' => 'https://route.test/mime', 'key' => 'route-key'],
        defaults: ['endpoint' => 'https://global.test/mime', 'key' => 'global-key'],
    );

    expect($route->endpoint())->toBe('https://route.test/mime')
        ->and($route->key())->toBe('route-key');
});

it('falls back to the defaults where an option is unset', function () {
    $route = new MailboxRoute(
        options: [],
        defaults: ['endpoint' => 'https://global.test/mime', 'key' => 'global-key'],
    );

    expect($route->endpoint())->toBe('https://global.test/mime')
        ->and($route->key())->toBe('global-key')
        ->and($route->definesKey())->toBeFalse();
});

it('reports definesKey only for its own key, never the default', function () {
    expect((new MailboxRoute(['key' => 'k']))->definesKey())->toBeTrue()
        ->and((new MailboxRoute([], ['key' => 'global']))->definesKey())->toBeFalse();
});

it('is enabled unless its options disable it', function () {
    expect((new MailboxRoute)->enabled())->toBeTrue()
        ->and((new MailboxRoute(['enabled' => false]))->enabled())->toBeFalse();
});

it('resolves the marker from options, then default, then seen', function () {
    expect((new MailboxRoute(['mark' => 'none']))->marker()->mode)->toBe(CaptureMarker::NONE)
        ->and((new MailboxRoute([], ['mark' => 'keyword:Mailspoon']))->marker()->mode)->toBe(CaptureMarker::KEYWORD)
        ->and((new MailboxRoute)->marker()->mode)->toBe(CaptureMarker::SEEN);
});

it('builds the matcher from options, falling back to the defaults', function () {
    $route = new MailboxRoute(
        options: [],
        defaults: ['filters' => ['deny' => ['from' => ['*']]]],
    );

    // An empty allow list with a catch-all deny rejects everything.
    expect($route->matcher())->toBeInstanceOf(MessageMatcher::class);
});
