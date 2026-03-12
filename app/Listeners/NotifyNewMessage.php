<?php

namespace App\Listeners;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Random\RandomException;
use Webklex\IMAP\Events\MessageNewEvent;

class NotifyNewMessage
{
    /**
     * Handle the event.
     *
     * @throws ConnectionException
     * @throws RandomException
     * @throws Exception
     */
    public function handle(MessageNewEvent $event): void
    {
        $config = config('spoon');

        if (! isset($config['endpoint'], $config['key'])) {
            throw new Exception('Mailspoon service is not configured.');
        }

        Http::asForm()->post($config['endpoint'], [
            'body-mime' => $event->message->getRawBody(),
            'timestamp' => $timestamp = $event->message->date,
            'token' => $token = bin2hex(random_bytes(25)),
            'signature' => hash_hmac('sha256', $timestamp.$token, $config['key']),
        ]);
    }
}
