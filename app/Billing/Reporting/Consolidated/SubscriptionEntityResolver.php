<?php

declare(strict_types=1);

namespace App\Billing\Reporting\Consolidated;

use App\Billing\Seller\SellerCatalog;
use App\Models\Invoice;
use RuntimeException;

/**
 * Attributes each subscription to the selling entity of record that bills it — the missing link
 * for per-entity consolidation. A subscription has no `seller` column of its own; the authority
 * is the `seller` its invoices were issued under (the entity that issued an invoice drives the
 * whole tax + legal identity). A subscription that has not yet been invoiced falls back to the
 * configured default selling entity, so every serving subscription maps to exactly one entity.
 *
 * The map is built in one query (latest invoice per subscription wins), so consolidating the
 * whole book is not an N+1.
 */
readonly class SubscriptionEntityResolver
{
    public function __construct(private SellerCatalog $sellers) {}

    /**
     * subscription_id → selling-entity id, from the latest invoice each subscription was issued
     * under. Subscriptions with no invoice are absent (they resolve to {@see defaultEntity()}).
     *
     * @return array<int, string>
     */
    public function map(): array
    {
        /** @var array<int, string> $map */
        $map = [];

        // Ascending id so a later (newer) invoice's seller overwrites an earlier one — the
        // subscription's current entity of record.
        Invoice::query()
            ->whereNotNull('subscription_id')
            ->orderBy('id')
            ->get(['subscription_id', 'seller'])
            ->each(function (Invoice $invoice) use (&$map): void {
                if ($invoice->subscription_id !== null) {
                    $map[(int) $invoice->subscription_id] = $invoice->seller;
                }
            });

        return $map;
    }

    /** The configured default selling entity id — the fallback for an un-invoiced subscription. */
    public function defaultEntity(): string
    {
        return $this->sellers->default()->id;
    }

    /**
     * The selling entity's legal name for display, or the id itself when the entity can no longer
     * be resolved (deleted from config and DB) — honest over a crash.
     */
    public function entityName(string $id): string
    {
        try {
            return $this->sellers->entity($id)->legalName;
        } catch (RuntimeException) {
            return $id;
        }
    }
}
