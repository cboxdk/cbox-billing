<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Approvals\Actions\AdjustWalletActionFactory;
use App\Billing\Approvals\Actions\ArchivePlanActionFactory;
use App\Billing\Approvals\Actions\RefundInvoiceActionFactory;
use App\Billing\Approvals\Actions\SuspendCustomerActionFactory;
use App\Billing\Approvals\ApprovableActionRegistry;
use App\Billing\Approvals\Contracts\BuildsApprovableAction;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the general approval-workflow engine (maker-checker / two-person rule). The
 * {@see ApprovableActionRegistry} is a singleton so the `action_type` → factory catalog is
 * populated once and shared by the gate (capture) and the executor (run-on-approve).
 *
 * The policy, gate and decision service are plain readonly classes the container auto-resolves
 * from their typed dependencies — no explicit bindings needed. To add a new approvable action,
 * register its {@see BuildsApprovableAction} factory here.
 */
class ApprovalServiceProvider extends ServiceProvider
{
    /**
     * The held-action factories the engine knows how to build and run. Deny-by-default: an
     * action type not registered here cannot be held OR executed.
     *
     * @var list<class-string<BuildsApprovableAction>>
     */
    private const FACTORIES = [
        RefundInvoiceActionFactory::class,
        AdjustWalletActionFactory::class,
        SuspendCustomerActionFactory::class,
        ArchivePlanActionFactory::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ApprovableActionRegistry::class, function (): ApprovableActionRegistry {
            $registry = new ApprovableActionRegistry;

            foreach (self::FACTORIES as $factory) {
                $registry->register($this->app->make($factory));
            }

            return $registry;
        });
    }
}
