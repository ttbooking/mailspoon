<?php

use DirectoryTree\ImapEngine\FileMessage;
use TTBooking\Mailspoon\Results\FilterDecision;
use TTBooking\Mailspoon\Services\FilterTester;

/**
 * Build a MessageInterface straight from raw MIME — no IMAP needed.
 */
function mime(string $from = 'sender@example.com', string $subject = 'Hello', array $headers = []): FileMessage
{
    $lines = ["From: {$from}", "Subject: {$subject}"];

    foreach ($headers as $name => $value) {
        $lines[] = "{$name}: {$value}";
    }

    $lines[] = '';
    $lines[] = 'Body text.';

    return new FileMessage(implode("\r\n", $lines));
}

it('allows by default when the mailbox has no filters', function () {
    $result = app(FilterTester::class)->test('support', mime());

    expect($result->passes)->toBeTrue()
        ->and($result->decision)->toBe(FilterDecision::AllowedByDefault)
        ->and($result->field)->toBeNull();
});

it('reports the allow rule that passed a message', function () {
    config(['mailspoon.routes.support.filters' => ['allow' => ['subject' => ['/invoice/i']]]]);

    $result = app(FilterTester::class)->test('support', mime(subject: 'Your invoice #42'));

    expect($result->passes)->toBeTrue()
        ->and($result->decision)->toBe(FilterDecision::AllowedByRule)
        ->and($result->field)->toBe('subject')
        ->and($result->pattern)->toBe('/invoice/i');
});

it('reports denial when an allow list exists but nothing matches', function () {
    config(['mailspoon.routes.support.filters' => ['allow' => ['subject' => ['/invoice/i']]]]);

    $result = app(FilterTester::class)->test('support', mime(subject: 'weekly newsletter'));

    expect($result->passes)->toBeFalse()
        ->and($result->decision)->toBe(FilterDecision::DeniedNoMatch)
        ->and($result->field)->toBeNull();
});

it('reports the deny rule that dropped a message, deny winning over allow', function () {
    config(['mailspoon.routes.support.filters' => [
        'allow' => ['subject' => ['*']],
        'deny' => ['from' => ['no-reply@*']],
    ]]);

    $result = app(FilterTester::class)->test('support', mime(from: 'no-reply@acme.com'));

    expect($result->passes)->toBeFalse()
        ->and($result->decision)->toBe(FilterDecision::DeniedByRule)
        ->and($result->field)->toBe('from')
        ->and($result->pattern)->toBe('no-reply@*');
});

it('names the header rule that decided the verdict', function () {
    config(['mailspoon.routes.support.filters' => ['deny' => ['header' => ['Auto-Submitted' => 'auto-*']]]]);

    $result = app(FilterTester::class)->test('support', mime(headers: ['Auto-Submitted' => 'auto-replied']));

    expect($result->passes)->toBeFalse()
        ->and($result->field)->toBe('header:Auto-Submitted')
        ->and($result->pattern)->toBe('auto-*');
});

it('evaluates filters even for a paused mailbox', function () {
    config([
        'mailspoon.routes.support.enabled' => false,
        'mailspoon.routes.support.filters' => ['deny' => ['from' => ['no-reply@*']]],
    ]);

    $result = app(FilterTester::class)->test('support', mime(from: 'no-reply@acme.com'));

    expect($result->passes)->toBeFalse()
        ->and($result->decision)->toBe(FilterDecision::DeniedByRule);
});

it('throws on a malformed filter rule', function () {
    config(['mailspoon.routes.support.filters' => ['allow' => ['subject' => ['/broken[/']]]]);

    app(FilterTester::class)->test('support', mime());
})->throws(InvalidArgumentException::class);

it('serializes to a json-friendly structure', function () {
    config(['mailspoon.routes.support.filters' => ['deny' => ['from' => ['no-reply@*']]]]);

    $array = app(FilterTester::class)->test('support', mime(from: 'no-reply@acme.com'))->toArray();

    expect($array)->toMatchArray([
        'mailbox' => 'support',
        'passes' => false,
        'decision' => 'denied_by_rule',
        'field' => 'from',
        'pattern' => 'no-reply@*',
    ])->and($array['reason'])->toContain('denied by from');
});
