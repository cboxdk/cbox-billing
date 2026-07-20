<?php

declare(strict_types=1);

namespace App\Billing\Approvals;

use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Approvals\ValueObjects\ActionApprovalPolicy;
use App\Billing\Support\MoneyFormatter;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The policy layer: reads `config('billing.approvals')` and answers "does THIS action need a
 * second person?". It is the one place the threshold/enable rules live, so a controller never
 * decides for itself — it hands the action to the gate, which asks the policy.
 *
 * Defaults are fail-safe-for-continuity: every action type ships DISABLED, so an app that does
 * not configure approvals behaves exactly as before (no held requests, no regression). An
 * operator opts each sensitive action in — with an optional amount threshold — via config.
 */
readonly class ApprovalPolicy
{
    private const DEFAULT_PERMISSION = 'approvals:decide';

    public function __construct(private Config $config) {}

    /** Whether the held action must be captured for approval rather than run now. */
    public function requiresApproval(ApprovableAction $action): bool
    {
        return $this->forType($action->type())
            ->requiresApproval($action->context()->amountMinor);
    }

    /** How many distinct checkers must approve before the held action runs (≥ 1). */
    public function requiredApprovals(ApprovalActionType $type): int
    {
        return $this->forType($type)->requiredApprovals;
    }

    /** Whether the action type is opted in to the approval workflow at all. */
    public function isEnabled(ApprovalActionType $type): bool
    {
        return $this->forType($type)->enabled;
    }

    /** The permission slug a checker needs to decide (approve/reject) a held request. */
    public function decidePermission(): string
    {
        $value = $this->config->get('billing.approvals.permission');

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_PERMISSION;
    }

    /** How many days a pending request stays open before it may be expired, or null = never. */
    public function expireAfterDays(): ?int
    {
        $value = $this->config->get('billing.approvals.expire_after_days');

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    /** The resolved, typed policy for one action type. */
    public function forType(ApprovalActionType $type): ActionApprovalPolicy
    {
        // The action-type key is a dotted slug (e.g. `invoice.refund`), so it is a LITERAL array
        // key — read the whole map and index it, rather than a dotted config path that would
        // wrongly traverse `invoice` → `refund`.
        $actions = $this->config->get('billing.approvals.actions');
        $raw = is_array($actions) && isset($actions[$type->value]) && is_array($actions[$type->value])
            ? $actions[$type->value]
            : [];

        $threshold = $raw['threshold_minor'] ?? null;
        $required = $raw['required'] ?? 1;

        return new ActionApprovalPolicy(
            enabled: (bool) ($raw['enabled'] ?? false),
            thresholdMinor: is_numeric($threshold) ? (int) $threshold : null,
            requiredApprovals: max(1, is_numeric($required) ? (int) $required : 1),
        );
    }

    /** A human summary of the threshold for the console (e.g. "refunds ≥ kr 5.000,00"). */
    public function thresholdSummary(ApprovalActionType $type): string
    {
        $policy = $this->forType($type);

        if (! $policy->enabled) {
            return 'Approval not required (executes directly).';
        }

        if ($policy->thresholdMinor === null) {
            return 'Every '.$type->label().' requires approval.';
        }

        $currency = $this->config->get('billing.default_currency');
        $currency = is_string($currency) && $currency !== '' ? $currency : 'DKK';

        return 'Requires approval at or above '.MoneyFormatter::minor($policy->thresholdMinor, $currency)
            .($policy->requiredApprovals > 1 ? sprintf(' · %d approvals', $policy->requiredApprovals) : '');
    }
}
