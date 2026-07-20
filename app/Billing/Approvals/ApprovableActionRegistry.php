<?php

declare(strict_types=1);

namespace App\Billing\Approvals;

use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Billing\Approvals\Contracts\BuildsApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Approvals\Exceptions\ApprovalDenied;
use App\Providers\ApprovalServiceProvider;

/**
 * The `action_type` → factory catalog. Every approvable action registers exactly one
 * {@see BuildsApprovableAction} here (in {@see ApprovalServiceProvider}); the
 * gate and the executor both come through {@see build()} to construct an action, so there is a
 * single, shared construction path for direct and approved runs alike.
 *
 * DENY-BY-DEFAULT: an unknown type cannot be built. This is the fail-closed backstop for the
 * whole engine — a policy entry or a stored request that names a type with no registered
 * factory refuses rather than silently letting a sensitive mutation run unapproved.
 */
class ApprovableActionRegistry
{
    /** @var array<string, BuildsApprovableAction> */
    private array $factories = [];

    public function register(BuildsApprovableAction $factory): void
    {
        $key = $factory->type()->value;

        if (isset($this->factories[$key])) {
            throw ApprovalDenied::duplicateAction($factory->type());
        }

        $this->factories[$key] = $factory;
    }

    /** Whether a factory is registered for the type (the policy uses this to fail closed). */
    public function supports(ApprovalActionType $type): bool
    {
        return isset($this->factories[$type->value]);
    }

    /**
     * Reconstruct an action of the given type from its payload; refuses an unregistered type.
     *
     * @param  array<string, mixed>  $payload
     */
    public function build(ApprovalActionType $type, array $payload): ApprovableAction
    {
        $factory = $this->factories[$type->value]
            ?? throw ApprovalDenied::unknownAction($type->value);

        return $factory->build($payload);
    }
}
