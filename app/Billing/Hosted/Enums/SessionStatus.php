<?php

declare(strict_types=1);

namespace App\Billing\Hosted\Enums;

/**
 * A hosted session's lifecycle. It opens {@see SessionStatus::Pending}; a checkout flips
 * to {@see SessionStatus::Complete} only on the gateway's settled webhook (never on a
 * client-side confirmation), and any pending session past its TTL is stamped
 * {@see SessionStatus::Expired} — an expired token no longer authorizes its page.
 */
enum SessionStatus: string
{
    case Pending = 'pending';
    case Complete = 'complete';
    case Expired = 'expired';
}
