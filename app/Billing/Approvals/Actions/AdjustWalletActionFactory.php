<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\BuildsApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Wallet\Contracts\AdjustsWallet;

/**
 * Builds an {@see AdjustWalletAction} from a validated payload — the one construction path for
 * both the direct console adjustment and the held-then-approved one.
 */
readonly class AdjustWalletActionFactory implements BuildsApprovableAction
{
    public function __construct(private AdjustsWallet $wallet) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::WalletAdjust;
    }

    public function build(array $payload): AdjustWalletAction
    {
        $expires = $payload['expires_in_days'] ?? null;
        $actor = $payload['actor'] ?? null;

        return new AdjustWalletAction(
            $this->wallet,
            $this->str($payload, 'organization_id'),
            $this->str($payload, 'direction'),
            $this->str($payload, 'pool'),
            $this->str($payload, 'denomination'),
            is_numeric($payload['amount'] ?? null) ? (int) $payload['amount'] : 0,
            $this->str($payload, 'reason'),
            is_string($actor) ? $actor : null,
            is_numeric($expires) ? (int) $expires : null,
        );
    }

    /** @param array<string, mixed> $payload */
    private function str(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
