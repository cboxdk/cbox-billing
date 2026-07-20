<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Mode\BillingContext;
use Cbox\Billing\Invoice\Contracts\InvoiceNumberSequence;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Illuminate\Database\ConnectionInterface;

/**
 * Durable, per-entity legal invoice numbering. Each selling entity draws from its own
 * counter row under a row lock, so numbering is monotonic and gapless and never shared
 * across entities. The draw runs inside the same transaction the invoicer finalizes the
 * invoice in, so a concurrent finalize can never reuse a number.
 *
 * Numbers read `<PREFIX>-<YEAR>-<0000N>`, e.g. `CBOX-DK-2026-00001`.
 *
 * PLANE GUARD. The counter is keyed by `(seller, environment)`, never by the seller alone: a
 * sandbox that resolves the SAME seller id as production (the `billing.seller` config fallback does
 * exactly that in a plane with no authored register row) must not draw production's counter, which
 * would consume a number production never issues and GAP its legal series. Each plane keeps its own
 * gapless series for a given seller id.
 */
readonly class DatabaseInvoiceNumberSequence implements InvoiceNumberSequence
{
    private const TABLE = 'invoice_sequences';

    public function __construct(private ConnectionInterface $db, private BillingContext $context) {}

    public function next(SellerEntity $entity): string
    {
        return $this->db->transaction(function () use ($entity): string {
            $key = ['seller' => $entity->id, 'environment' => $this->context->environmentKey()];

            $row = $this->db->table(self::TABLE)
                ->where($key)
                ->lockForUpdate()
                ->first();

            $next = $row !== null && is_numeric($row->next_value) ? (int) $row->next_value : 1;

            $this->db->table(self::TABLE)->updateOrInsert(
                $key,
                ['next_value' => $next + 1, 'updated_at' => $this->db->raw('CURRENT_TIMESTAMP')],
            );

            return sprintf('%s-%s-%05d', $entity->invoicePrefix, date('Y'), $next);
        });
    }
}
