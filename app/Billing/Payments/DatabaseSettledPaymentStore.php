<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Mode\BillingContext;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * The durable, authoritative settle-once claim, keyed on the payment/invoice reference.
 * `settle()` is a UNIQUE insert on the reference — the exactly-once guard: two different
 * gateway events that both mean "invoice X paid" still settle X once, and a re-delivery
 * after the claim persisted is a no-op. The webhook ingest calls this in the same
 * transaction as the invoice paid-effect, so the claim and the effect commit atomically.
 *
 * Plane-aware: the claim carries the request's `environment` (with `livemode` as its mirror) and
 * every read is confined to the current plane, so a SANDBOX settlement can never be seen to settle
 * (or block) a PRODUCTION reference.
 */
readonly class DatabaseSettledPaymentStore implements SettledPaymentStore
{
    private const TABLE = 'settled_payments';

    public function __construct(
        private ConnectionInterface $db,
        private BillingContext $context,
    ) {}

    public function settle(string $reference): bool
    {
        try {
            $this->db->table(self::TABLE)->insert([
                'reference' => $reference,
                'environment' => $this->context->environmentKey(),
                'livemode' => $this->context->livemode(),
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    public function isSettled(string $reference): bool
    {
        return $this->db->table(self::TABLE)
            ->where('reference', $reference)
            ->where('environment', $this->context->environmentKey())
            ->exists();
    }
}
