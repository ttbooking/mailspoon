<?php

declare(strict_types=1);

namespace TTBooking\Mailspoon\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * UID cursor for mailboxes captured with `mark => none`.
 *
 * When mailspoon must not leave any trace on the message (neither \Seen nor
 * a custom keyword), the position is tracked here: the highest UID already
 * viewed, scoped by the folder's UIDVALIDITY.
 */
final class RelayCursor extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'uidvalidity' => 'integer',
            'last_uid' => 'integer',
        ];
    }
}
