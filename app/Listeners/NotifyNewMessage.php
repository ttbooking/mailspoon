<?php

namespace App\Listeners;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Random\RandomException;

class NotifyNewMessage
{
    public function __construct(
        #[Config('spoon.endpoint')] protected string $endpoint,
        #[Config('spoon.key')] protected string $key,
    ) {}

    /**
     * Handle the event.
     *
     * @throws ConnectionException
     * @throws RandomException
     */
    public function handle(MessageReceived $event): void
    {
        Http::asForm()->post($this->endpoint, [
            'body-mime' => (string) $event->message,
            'timestamp' => $timestamp = $event->message->date()?->getTimestamp() ?? time(),
            'token' => $token = bin2hex(random_bytes(25)),
            'signature' => hash_hmac('sha256', $timestamp.$token, $this->key),
        ]);
    }
}
