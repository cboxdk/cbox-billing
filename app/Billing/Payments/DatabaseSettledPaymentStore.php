<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * The durable, authoritative settle-once claim, keyed on the payment/invoice reference.
 * `settle()` is a UNIQUE insert on the reference — the exactly-once guard: two different
 * gateway events that both mean "invoice X paid" still settle X once, and a re-delivery
 * after the claim persisted is a no-op. The webhook ingest calls this in the same
 * transaction as the invoice paid-effect, so the claim and the effect commit atomically.
 */
readonly class DatabaseSettledPaymentStore implements SettledPaymentStore
{
    private const TABLE = 'settled_payments';

    public function __construct(private ConnectionInterface $db) {}

    public function settle(string $reference): bool
    {
        try {
            $this->db->table(self::TABLE)->insert(['reference' => $reference]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    public function isSettled(string $reference): bool
    {
        return $this->db->table(self::TABLE)->where('reference', $reference)->exists();
    }
}
