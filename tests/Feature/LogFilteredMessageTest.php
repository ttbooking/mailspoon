<?php

use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use TTBooking\Mailspoon\Events\MessageFiltered;
use TTBooking\Mailspoon\Listeners\LogFilteredMessage;

function fakeFilteredMessage(string $id = '<id@mailspoon.test>'): MessageInterface
{
    $message = Mockery::mock(MessageInterface::class);
    $message->shouldReceive('messageId')->andReturn($id);

    return $message;
}

it('logs a filtered message with mailbox and message id', function () {
    Log::spy();

    (new LogFilteredMessage)->handle(new MessageFiltered(fakeFilteredMessage(), 'default'));

    Log::shouldHaveReceived('info')->once()->withArgs(
        fn (string $message, array $context) => str_contains($message, '[default]')
            && $context['mailbox'] === 'default'
            && $context['message_id'] === '<id@mailspoon.test>'
    );
});

it('logs filtered messages out of the box via the default subscriber', function () {
    Log::spy();

    Event::dispatch(new MessageFiltered(fakeFilteredMessage(), 'default'));

    Log::shouldHaveReceived('info')->once();
});
