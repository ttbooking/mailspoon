<?php

use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\MessageInterface;
use TTBooking\Mailspoon\Support\MessageMatcher;
use ZBateson\MailMimeParser\Header\IHeader;

function matchableMessage(
    ?string $from = 'sender@example.com',
    ?string $subject = 'Hello',
    array $headers = [],
    bool $hasAttachments = false,
): MessageInterface {
    $message = Mockery::mock(MessageInterface::class);
    $message->allows('from')->andReturn(
        $from === null ? null : new Address($from, '')
    );
    $message->allows('subject')->andReturn($subject);
    $message->allows('hasAttachments')->andReturn($hasAttachments);
    $message->allows('header')->andReturnUsing(function (string $name) use ($headers) {
        if (! isset($headers[$name])) {
            return null;
        }

        $header = Mockery::mock(IHeader::class);
        $header->allows('getValue')->andReturn($headers[$name]);

        return $header;
    });

    return $message;
}

it('allows everything when no rules are configured', function () {
    expect((new MessageMatcher)->passes(matchableMessage()))->toBeTrue();
});

it('matches subjects against regular expressions', function () {
    $matcher = new MessageMatcher(allow: ['subject' => ['/⚡/u']]);

    expect($matcher->passes(matchableMessage(subject: 'Заявка ⚡ срочно')))->toBeTrue()
        ->and($matcher->passes(matchableMessage(subject: 'Обычное письмо')))->toBeFalse();
});

it('matches senders against case-insensitive wildcards', function () {
    $matcher = new MessageMatcher(allow: ['from' => ['*@Trusted.com']]);

    expect($matcher->passes(matchableMessage(from: 'boss@trusted.com')))->toBeTrue()
        ->and($matcher->passes(matchableMessage(from: 'spam@evil.com')))->toBeFalse();
});

it('lets deny win over allow', function () {
    $matcher = new MessageMatcher(
        allow: ['subject' => ['*']],
        deny: ['from' => ['no-reply@*']],
    );

    expect($matcher->passes(matchableMessage(from: 'no-reply@example.com')))->toBeFalse()
        ->and($matcher->passes(matchableMessage(from: 'human@example.com')))->toBeTrue();
});

it('denies by header value', function () {
    $matcher = new MessageMatcher(deny: ['header' => ['Auto-Submitted' => 'auto-*']]);

    expect($matcher->passes(matchableMessage(headers: ['Auto-Submitted' => 'auto-replied'])))->toBeFalse()
        ->and($matcher->passes(matchableMessage()))->toBeTrue();
});

it('matches on attachment presence', function () {
    $matcher = new MessageMatcher(allow: ['has_attachment' => true]);

    expect($matcher->passes(matchableMessage(hasAttachments: true)))->toBeTrue()
        ->and($matcher->passes(matchableMessage(hasAttachments: false)))->toBeFalse();
});

it('treats a missing sender as a non-match', function () {
    $matcher = new MessageMatcher(allow: ['from' => ['*@trusted.com']]);

    expect($matcher->passes(matchableMessage(from: null)))->toBeFalse();
});

it('rejects an invalid regular expression at construction', function () {
    expect(fn () => new MessageMatcher(allow: ['subject' => ['/broken[/']]))
        ->toThrow(InvalidArgumentException::class, 'Invalid filter pattern');
});

it('rejects an unknown filter field at construction', function () {
    expect(fn () => new MessageMatcher(deny: ['body' => ['*']]))
        ->toThrow(InvalidArgumentException::class, 'Unknown filter field [body]');
});

it('prefers route filters over global ones', function () {
    config([
        'mailspoon.filters' => ['deny' => ['from' => ['*']]],
        'mailspoon.routes.operators.filters' => ['allow' => ['subject' => ['/⚡/u']]],
    ]);

    $message = matchableMessage(subject: '⚡ go');

    expect(MessageMatcher::for('operators')->passes($message))->toBeTrue()
        ->and(MessageMatcher::for('other')->passes($message))->toBeFalse();
});
