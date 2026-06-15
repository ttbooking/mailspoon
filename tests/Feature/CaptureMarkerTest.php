<?php

use DirectoryTree\ImapEngine\MessageInterface;
use TTBooking\Mailspoon\Facades\Mailspoon;
use TTBooking\Mailspoon\Support\CaptureMarker;

it('parses the supported marker modes', function () {
    expect(CaptureMarker::parse('seen')->mode)->toBe(CaptureMarker::SEEN)
        ->and(CaptureMarker::parse('none')->mode)->toBe(CaptureMarker::NONE)
        ->and(CaptureMarker::parse('keyword:Mailspoon')->mode)->toBe(CaptureMarker::KEYWORD)
        ->and(CaptureMarker::parse('keyword:Mailspoon')->keyword)->toBe('Mailspoon');
});

it('rejects malformed marker values', function (string $mark) {
    expect(fn () => CaptureMarker::parse($mark))
        ->toThrow(InvalidArgumentException::class, 'Invalid mark');
})->with(['unseen', 'keyword:', 'keyword:has space', 'keyword:за-кириллицу']);

it('marks seen in seen mode', function () {
    $message = Mockery::mock(MessageInterface::class);
    $message->shouldReceive('markSeen')->once();

    CaptureMarker::parse('seen')->apply($message);
});

it('flags a custom keyword without touching seen', function () {
    $message = Mockery::mock(MessageInterface::class);
    $message->shouldReceive('flag')->once()->with('Mailspoon', '+');
    $message->shouldNotReceive('markSeen');

    CaptureMarker::parse('keyword:Mailspoon')->apply($message);
});

it('leaves the message alone in none mode', function () {
    $message = Mockery::mock(MessageInterface::class);
    $message->shouldNotReceive('markSeen');
    $message->shouldNotReceive('flag');

    CaptureMarker::parse('none')->apply($message);
});

it('prefers the route marker over the global one', function () {
    config([
        'mailspoon.mark' => 'seen',
        'mailspoon.routes.operators.mark' => 'keyword:Mailspoon',
    ]);

    expect(Mailspoon::route('operators')->marker()->mode)->toBe(CaptureMarker::KEYWORD)
        ->and(Mailspoon::route('other')->marker()->mode)->toBe(CaptureMarker::SEEN);
});
