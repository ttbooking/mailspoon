<?php

namespace App\Listeners;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Random\RandomException;
use Webklex\IMAP\Events\MessageNewEvent;

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
    public function handle(MessageNewEvent $event): void
    {
        Http::asForm()->post($this->endpoint, [
            'body-mime' => $event->message->getHeader()->raw."\r\n".$event->message->getRawBody(),
            'timestamp' => $timestamp = $event->message->date->toDate()->getTimestamp(),
            'token' => $token = bin2hex(random_bytes(25)),
            'signature' => hash_hmac('sha256', $timestamp.$token, $this->key),
        ]);
    }
}
