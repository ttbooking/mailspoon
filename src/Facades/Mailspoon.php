<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Facades;

use Illuminate\Support\Facades\Facade;
use TTBooking\Mailspoon\MailspoonManager;

/**
 * @method static void register(string $mailbox, array $route)
 * @method static array route(string $mailbox)
 * @method static array routes()
 *
 * @see MailspoonManager
 */
final class Mailspoon extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MailspoonManager::class;
    }
}
