<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Approvals\ValueObjects\ApprovalContext;
use App\Billing\Approvals\ValueObjects\ApprovalDescription;
use App\Billing\Approvals\ValueObjects\ApprovalOutcome;
use App\Billing\Wallet\Contracts\AdjustsWallet;
use App\Billing\Wallet\Exceptions\WalletActionDenied;

/**
 * Held action for an operator wallet adjustment (a promotional/goodwill grant or a correcting
 * debit). {@see execute()} calls the SAME {@see AdjustsWallet} service the direct console path
 * uses — which writes the offsetting engine lot AND the immutable `wallet.adjusted` audit row
 * in one transaction — so an approved adjustment moves credit identically to a direct one. The
 * money change does NOT happen until the request is approved and this runs.
 *
 * The maker's identity is captured in the payload (`actor`) at hold time, so the wallet
 * adjustment records who ORIGINATED it even though a different operator approved it.
 */
readonly class AdjustWalletAction implements ApprovableAction
{
    public function __construct(
        private AdjustsWallet $wallet,
        private string $organizationId,
        private string $direction,
        private string $pool,
        private string $denomination,
        private int $amount,
        private string $reason,
        private ?string $actor,
        private ?int $expiresInDays,
    ) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::WalletAdjust;
    }

    public function context(): ApprovalContext
    {
        return new ApprovalContext(
            organizationId: $this->organizationId,
            amountMinor: $this->amount,
            currency: null,
            targetType: 'organization',
            targetId: $this->organizationId,
        );
    }

    public function payload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'direction' => $this->direction,
            'pool' => $this->pool,
            'denomination' => $this->denomination,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'actor' => $this->actor,
            'expires_in_days' => $this->expiresInDays,
        ];
    }

    public function validate(): void
    {
        if ($this->amount <= 0) {
            throw WalletActionDenied::nonPositive();
        }
    }

    public function describe(): ApprovalDescription
    {
        return new ApprovalDescription(
            sprintf('%s %d %s in the %s pool for organization %s — %s',
                ucfirst($this->direction), $this->amount, $this->denomination, $this->pool, $this->organizationId, $this->reason),
            before: [],
            after: ['direction' => $this->direction, 'pool' => $this->pool, 'amount' => $this->amount, 'denomination' => $this->denomination],
        );
    }

    public function execute(): ApprovalOutcome
    {
        $adjustment = $this->direction === 'debit'
            ? $this->wallet->debit($this->organizationId, $this->pool, $this->denomination, $this->amount, $this->reason, $this->actor)
            : $this->wallet->grant($this->organizationId, $this->pool, $this->denomination, $this->amount, $this->reason, $this->actor, $this->expiresInDays);

        return new ApprovalOutcome(
            sprintf('%s of %d %s applied to the %s pool.', ucfirst($this->direction), $this->amount, $this->denomination, $this->pool),
            ['adjustment_id' => $adjustment->id, 'amount' => $adjustment->amount, 'direction' => $this->direction, 'pool' => $this->pool],
        );
    }
}
