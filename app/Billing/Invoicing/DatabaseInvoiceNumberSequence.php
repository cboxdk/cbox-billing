<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

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
 */
readonly class DatabaseInvoiceNumberSequence implements InvoiceNumberSequence
{
    private const TABLE = 'invoice_sequences';

    public function __construct(private ConnectionInterface $db) {}

    public function next(SellerEntity $entity): string
    {
        return $this->db->transaction(function () use ($entity): string {
            $row = $this->db->table(self::TABLE)
                ->where('seller', $entity->id)
                ->lockForUpdate()
                ->first();

            $next = $row !== null && is_numeric($row->next_value) ? (int) $row->next_value : 1;

            $this->db->table(self::TABLE)->updateOrInsert(
                ['seller' => $entity->id],
                ['next_value' => $next + 1, 'updated_at' => $this->db->raw('CURRENT_TIMESTAMP')],
            );

            return sprintf('%s-%s-%05d', $entity->invoicePrefix, date('Y'), $next);
        });
    }
}
