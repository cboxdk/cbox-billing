<?php

declare(strict_types=1);

namespace App\Billing\Payments\Contracts;

use App\Billing\Payments\Dunning\CardUpdate;
use App\Billing\Payments\Dunning\CardUpdateResult;
use App\Billing\Payments\DunningCardUpdater;
use App\Billing\Payments\NullCardUpdater;

/**
 * The card / account-updater seam: applies a gateway card-update to the account's vaulted
 * method and, when the account has a charge in dunning that the fresh card can resolve,
 * re-attempts it immediately. The counterpart to adaptive dunning's HARD-decline short-circuit
 * — a lost/expired card stops the retries, and this seam is how the recovery re-opens when a
 * new card lands.
 *
 * Kept behind a contract with a deny/no-op default ({@see NullCardUpdater})
 * so the ingest path is inert until a real updater is bound; the deployable app binds
 * {@see DunningCardUpdater}.
 */
interface UpdatesCards
{
    public function apply(CardUpdate $update): CardUpdateResult;
}
