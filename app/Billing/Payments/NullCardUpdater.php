<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Payments\Contracts\UpdatesCards;
use App\Billing\Payments\Dunning\CardUpdate;
use App\Billing\Payments\Dunning\CardUpdateResult;

/**
 * The inert default {@see UpdatesCards}: it accepts a card-update and does nothing. The safe
 * zero-config binding so the card-updater ingest path never errors when no real updater is
 * composed. The deployable app binds {@see DunningCardUpdater} over this; a host that wants
 * card-updates ignored can rebind to this.
 */
readonly class NullCardUpdater implements UpdatesCards
{
    public function apply(CardUpdate $update): CardUpdateResult
    {
        return CardUpdateResult::ignored('no-op card updater');
    }
}
