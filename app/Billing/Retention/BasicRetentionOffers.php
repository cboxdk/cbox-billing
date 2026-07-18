<?php

declare(strict_types=1);

namespace App\Billing\Retention;

use Cbox\Billing\Retention\Contracts\RetentionOffers;
use Cbox\Billing\Retention\NullRetentionOffers;
use Cbox\Billing\Retention\ValueObjects\SaveOffer;

/**
 * The app's own default {@see RetentionOffers}: a single, built-in save-offer — a
 * pause-instead-of-cancel — surfaced to a cancelling subscriber, mapped to a lever the
 * engine already owns (the pause). It binds over the engine's inert
 * {@see NullRetentionOffers} so the deployable app presents a basic
 * offer; when the private `cbox-billing-retention` plugin is composed in it rebinds this
 * contract to the rich, targeted offer logic (eligibility, caps, discounts) — with zero app
 * edits.
 */
readonly class BasicRetentionOffers implements RetentionOffers
{
    public function offersFor(string $account, string $subscriptionId): array
    {
        return [
            SaveOffer::pause('pause_one_cycle', 'Pause instead — keep your account, pay nothing', pauseCycles: 1),
        ];
    }
}
