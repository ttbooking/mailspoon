<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Results;

/**
 * Outcome of a single diagnostic check.
 */
enum CheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
}
