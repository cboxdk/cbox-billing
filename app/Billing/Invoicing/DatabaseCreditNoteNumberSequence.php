<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Mode\BillingContext;
use Cbox\Billing\Invoice\Contracts\CreditNoteNumberSequence;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Illuminate\Database\ConnectionInterface;

/**
 * Durable, per-entity legal credit-note numbering — the credit-note sibling of
 * {@see DatabaseInvoiceNumberSequence}. A credit note is its own legal document, so it
 * draws from a SEPARATE per-seller counter (never an invoice number, never a reused one)
 * under a row lock inside the refund transaction, keeping numbering monotonic and
 * gapless per entity.
 *
 * Numbers read `<PREFIX>-CN-<YEAR>-<0000N>`, e.g. `CBOX-DK-CN-2026-00001` — the same
 * prefix as the entity's invoices, marked `CN` so the two documents stay legible apart.
 *
 * PLANE GUARD. Like {@see DatabaseInvoiceNumberSequence}, the counter is keyed by
 * `(seller, environment)` so a sandbox drawing the same seller id can never advance — or gap —
 * production's legal credit-note series.
 */
readonly class DatabaseCreditNoteNumberSequence implements CreditNoteNumberSequence
{
    private const TABLE = 'credit_note_sequences';

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

            return sprintf('%s-CN-%s-%05d', $entity->invoicePrefix, date('Y'), $next);
        });
    }
}
