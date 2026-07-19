<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\Contracts;

use App\Models\Invoice;
use App\Models\Subscription;
use Cbox\Billing\Money\Money;

/**
 * Collects an amount due now for a mid-cycle subscription change — a plan upgrade, a seat
 * increase, or an add-on attach (ADR-0012). The immediate change methods provision the new
 * entitlements and expand MRR; this is the seam that turns the previewed "due now" into an
 * actual receivable and charge, so preview == charge holds and an upgrade is never
 * provisioned for free (H6).
 *
 * A non-positive amount (a downgrade credit, a flat-plan seat change that nets zero) is a
 * no-op — nothing is issued or charged.
 */
interface CollectsProration
{
    /**
     * Issue a prorated invoice for `$dueNow` against `$subscription` (taxed through the same
     * quote path the period invoice uses) and collect it through the established charge path.
     * Returns the issued invoice, or null when nothing was due.
     */
    public function collect(Subscription $subscription, Money $dueNow, string $description): ?Invoice;
}
