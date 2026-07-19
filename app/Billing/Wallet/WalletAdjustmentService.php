<?php

declare(strict_types=1);

namespace App\Billing\Wallet;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Wallet\Contracts\AdjustsWallet;
use App\Billing\Wallet\Exceptions\WalletActionDenied;
use App\Models\WalletAdjustment;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Operator wallet adjustments (Wave 3). A grant deposits a positive lot into the chosen
 * pool through the engine {@see Wallet}; a debit deposits an OFFSETTING negative lot (the
 * same reverse-a-grant pattern the refunder uses — never a silent balance edit). Both
 * write an immutable {@see WalletAdjustment} audit row in the same transaction, so the
 * money movement and its audit commit together.
 *
 * Guardrails: the amount must be positive, and a debit is refused when it would drive a
 * pool that may not go negative below zero (only the PAYG `purchased` sink may hold debt).
 */
readonly class WalletAdjustmentService implements AdjustsWallet
{
    /** Default life of a promotional grant when the operator sets no explicit expiry. */
    private const PROMO_DEFAULT_DAYS = 365;

    public function __construct(
        private ConnectionInterface $db,
        private Wallet $wallet,
        private RecordsAudit $audit,
    ) {}

    public function grant(string $org, string $pool, string $denomination, int $amount, string $reason, ?string $actor, ?int $expiresInDays = null): WalletAdjustment
    {
        if ($amount <= 0) {
            throw WalletActionDenied::nonPositive();
        }

        $poolVo = $this->pool($pool);
        $denominationVo = Denomination::unit($denomination);
        $expiresAt = $this->expiryFor($poolVo, $expiresInDays);
        $grantId = 'op-grant:'.$org.':'.Str::random(16);

        return $this->write($org, $poolVo, $denominationVo, $amount, WalletAdjustment::DIRECTION_GRANT, $reason, $actor, $grantId, $expiresAt);
    }

    public function debit(string $org, string $pool, string $denomination, int $amount, string $reason, ?string $actor): WalletAdjustment
    {
        if ($amount <= 0) {
            throw WalletActionDenied::nonPositive();
        }

        $poolVo = $this->pool($pool);
        $denominationVo = Denomination::unit($denomination);

        // Policy guardrail: only a mayGoNegative pool (the PAYG sink) may be driven into debt.
        if (! $poolVo->mayGoNegative) {
            $balance = $this->wallet->balance($org, $poolVo, $denominationVo, $this->nowMillis());

            if ($balance < $amount) {
                throw WalletActionDenied::insufficientBalance($balance, $amount);
            }
        }

        $grantId = 'op-debit:'.$org.':'.Str::random(16);

        return $this->write($org, $poolVo, $denominationVo, -$amount, WalletAdjustment::DIRECTION_DEBIT, $reason, $actor, $grantId, null);
    }

    /** Deposit the (signed) lot through the engine wallet and record the audit row atomically. */
    private function write(string $org, Pool $pool, Denomination $denomination, int $signedAmount, string $direction, string $reason, ?string $actor, string $grantId, ?int $expiresAt): WalletAdjustment
    {
        return $this->db->transaction(function () use ($org, $pool, $denomination, $signedAmount, $direction, $reason, $actor, $grantId, $expiresAt): WalletAdjustment {
            $balanceBefore = $this->wallet->balance($org, $pool, $denomination, $this->nowMillis());

            $this->wallet->grant(new CreditGrant(
                id: $grantId,
                org: $org,
                pool: $pool,
                denomination: $denomination,
                remaining: $signedAmount,
                expiresAt: $expiresAt,
                grantedAt: $this->nowMillis(),
                kind: GrantKind::Base,
                cadence: GrantCadence::Once,
            ));

            $adjustment = WalletAdjustment::query()->create([
                'organization_id' => $org,
                'pool_key' => $pool->key,
                'denomination_code' => $denomination->code,
                'denomination_is_money' => $denomination->isMoney,
                'amount' => $signedAmount,
                'direction' => $direction,
                'reason' => $reason,
                'actor' => $actor,
                'grant_id' => $grantId,
            ]);

            // The money movement and its audit event commit together (the same in-transaction
            // pattern the WalletAdjustment row uses): a durable, tamper-evident record of who
            // moved credit, in which pool, and the resulting balance.
            $balanceAfter = $this->wallet->balance($org, $pool, $denomination, $this->nowMillis());

            $this->audit->record(
                AuditAction::WalletAdjusted,
                AuditTarget::of('organization', $org, $org),
                sprintf('%s %d %s in the %s pool for organization %s.', ucfirst($direction), abs($signedAmount), $denomination->code, $pool->key, $org),
                [
                    'before' => ['balance' => $balanceBefore],
                    'after' => ['balance' => $balanceAfter, 'adjustment_id' => $adjustment->id],
                    'pool' => $pool->key,
                    'denomination' => $denomination->code,
                    'direction' => $direction,
                    'amount' => $signedAmount,
                    'reason' => $reason,
                ],
            );

            return $adjustment;
        });
    }

    /** Resolve a pool key to its behaviour-carrying {@see Pool}; unknown keys fall to promotional. */
    private function pool(string $key): Pool
    {
        return match ($key) {
            Pools::INCLUDED => Pools::included(),
            Pools::PURCHASED => Pools::purchased(),
            Pools::REGULATED => Pools::regulated(),
            default => Pools::promotional(),
        };
    }

    /** A pool that requires an expiry gets one (operator-set or the default); others may be perpetual. */
    private function expiryFor(Pool $pool, ?int $expiresInDays): ?int
    {
        if ($expiresInDays !== null && $expiresInDays > 0) {
            return $this->nowMillis() + $expiresInDays * 86_400_000;
        }

        if ($pool->requiresExpiry) {
            return $this->nowMillis() + self::PROMO_DEFAULT_DAYS * 86_400_000;
        }

        return null;
    }

    private function nowMillis(): int
    {
        return (int) (Carbon::now()->getTimestamp() * 1000);
    }
}
