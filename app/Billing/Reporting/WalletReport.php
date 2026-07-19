<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\WalletAdjustment;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Read model for the wallet/credits panel (Wave 3). Projects the engine's durable wallet
 * lots (`billing_wallet_lots`) for an organization into per-pool balances and a grant/debit
 * ledger with expiry, and surfaces the operator-adjustment audit trail. Balances are
 * DERIVED by summing active lots — never stored loose — exactly as the engine wallet does.
 */
readonly class WalletReport
{
    private const LOTS = 'billing_wallet_lots';

    public function __construct(private ConnectionInterface $db) {}

    /**
     * The wallet picture for `$org`: per-pool balances (active lots only), the full lot
     * ledger (including spent/expired), and the operator-adjustment audit trail.
     *
     * @return array{pools: list<array<string, mixed>>, lots: list<array<string, mixed>>, adjustments: list<array<string, mixed>>, has_activity: bool}
     */
    public function forOrganization(string $org): array
    {
        $now = (int) (Carbon::now()->getTimestamp() * 1000);

        $lots = $this->db->table(self::LOTS)->where('org', $org)->orderByDesc('granted_at')->get();

        $pools = [];
        $ledger = [];

        foreach ($lots as $lot) {
            $poolKey = $this->str($lot->pool_key);
            $denomination = $this->str($lot->denomination_code);
            $remaining = $this->int($lot->remaining);
            $expiresAt = $lot->expires_at !== null ? $this->int($lot->expires_at) : null;
            $active = $expiresAt === null || $expiresAt > $now;
            $groupKey = $poolKey.'|'.$denomination;

            if (! isset($pools[$groupKey])) {
                $pools[$groupKey] = [
                    'pool' => $poolKey,
                    'denomination' => $denomination,
                    'money' => (bool) $lot->denomination_is_money,
                    'balance' => 0,
                    'spendable' => (bool) $lot->pool_spendable,
                    'forfeits' => (bool) $lot->pool_forfeits_on_cancel,
                    'may_go_negative' => (bool) $lot->pool_may_go_negative,
                ];
            }

            if ($active) {
                $pools[$groupKey]['balance'] += $remaining;
            }

            $ledger[] = [
                'grant_id' => $this->str($lot->grant_id),
                'pool' => $poolKey,
                'denomination' => $denomination,
                'remaining' => $remaining,
                'kind' => $this->str($lot->kind),
                'cadence' => $this->str($lot->cadence),
                'granted_at' => $this->date($this->int($lot->granted_at)),
                'expires_at' => $expiresAt !== null ? $this->date($expiresAt) : 'never',
                'active' => $active,
            ];
        }

        $adjustments = WalletAdjustment::query()
            ->where('organization_id', $org)
            ->orderByDesc('id')
            ->get()
            ->map(static fn (WalletAdjustment $adjustment): array => [
                'direction' => $adjustment->direction,
                'pool' => $adjustment->pool_key,
                'denomination' => $adjustment->denomination_code,
                'amount' => $adjustment->amount,
                'reason' => $adjustment->reason,
                'actor' => $adjustment->actor,
                'at' => $adjustment->created_at?->format('Y-m-d H:i') ?? '—',
            ])
            ->all();

        return [
            'pools' => array_values($pools),
            'lots' => $ledger,
            'adjustments' => array_values($adjustments),
            'has_activity' => $lots->isNotEmpty() || $adjustments !== [],
        ];
    }

    /** Render an ms-epoch instant as a date, or an em dash for the zero sentinel. */
    private function date(int $millis): string
    {
        if ($millis <= 0) {
            return '—';
        }

        return Carbon::createFromTimestampMs($millis)->format('Y-m-d');
    }

    /** Narrow a query-row scalar to a string. */
    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** Narrow a query-row scalar to an int. */
    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
