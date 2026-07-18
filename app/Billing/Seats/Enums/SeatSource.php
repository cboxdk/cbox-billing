<?php

declare(strict_types=1);

namespace App\Billing\Seats\Enums;

/**
 * How a purchased Full seat came to be assigned to a member:
 *
 *  - {@see SeatSource::Manual} — an operator explicitly assigned it (console/API). Never
 *    released automatically; only an explicit unassign frees it.
 *  - {@see SeatSource::Auto} — the auto-assign mode gave it on a membership/role event.
 *    Released automatically when the member's role drops out of the auto-assign set.
 */
enum SeatSource: string
{
    case Manual = 'manual';

    case Auto = 'auto';
}
