<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Actions;

use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Approvals\ValueObjects\ApprovalContext;
use App\Billing\Approvals\ValueObjects\ApprovalDescription;
use App\Billing\Approvals\ValueObjects\ApprovalOutcome;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Models\Organization;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Illuminate\Support\Carbon;

/**
 * Held action for suspending a customer organization. {@see execute()} is the SAME path the
 * direct console suspension took: it flips the app's `suspended_at` mirror AND the engine
 * {@see AccountStanding}, and records the `customer.suspended` audit event — so an approved
 * suspension is identical to a direct one.
 * Access is only held once this runs; billing is untouched either way.
 */
readonly class SuspendCustomerAction implements ApprovableAction
{
    public function __construct(
        private AccountStanding $standing,
        private RecordsAudit $audit,
        private Organization $organization,
    ) {}

    public function type(): ApprovalActionType
    {
        return ApprovalActionType::CustomerSuspend;
    }

    public function context(): ApprovalContext
    {
        return new ApprovalContext(
            organizationId: $this->organization->id,
            amountMinor: null,
            currency: null,
            targetType: 'organization',
            targetId: $this->organization->id,
        );
    }

    public function payload(): array
    {
        return ['organization_id' => $this->organization->id];
    }

    public function validate(): void
    {
        // Re-suspending an already-suspended org is harmless; nothing to refuse here.
    }

    public function describe(): ApprovalDescription
    {
        return new ApprovalDescription(
            sprintf('Suspend organization %s (%s) — access held, billing untouched.', $this->organization->name, $this->organization->id),
            before: ['suspended' => $this->organization->suspended_at !== null],
            after: ['suspended' => true, 'standing' => AccountStandingState::Suspended->value],
        );
    }

    public function execute(): ApprovalOutcome
    {
        $wasSuspended = $this->organization->suspended_at !== null;

        $this->organization->forceFill(['suspended_at' => Carbon::now()])->save();
        $this->standing->flag($this->organization->id, AccountStandingState::Suspended, 'Suspended by operator from the console.');

        $this->audit->record(
            AuditAction::CustomerSuspended,
            AuditTarget::of('organization', $this->organization->id, $this->organization->id),
            sprintf('Suspended organization %s — access held, billing untouched.', $this->organization->id),
            [
                'before' => ['suspended' => $wasSuspended, 'standing' => AccountStandingState::Good->value],
                'after' => ['suspended' => true, 'standing' => AccountStandingState::Suspended->value],
            ],
        );

        return new ApprovalOutcome(
            sprintf('%s suspended — access is held; billing is untouched.', $this->organization->name),
            ['organization_id' => $this->organization->id],
        );
    }
}
