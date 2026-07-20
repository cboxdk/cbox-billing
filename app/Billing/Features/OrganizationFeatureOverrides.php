<?php

declare(strict_types=1);

namespace App\Billing\Features;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditActor;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Models\Feature;
use App\Models\OrganizationFeatureOverride;

/**
 * Authors an org-level feature override — the per-customer grant/revoke that wins over the plan
 * resolution (see {@see FeatureEntitlements}). Every write is recorded to the tamper-evident
 * operator audit trail ({@see RecordsAudit}) with the feature, the direction, and the value/reason
 * — a per-customer entitlement change is an operator action that must be attributable.
 *
 *  - {@see set()} upserts the override (grant OR revoke) for one `(org, feature)`.
 *  - {@see clear()} removes the override, restoring the plan-resolved answer.
 *
 * The value the resolver reads back is typed by the feature's {@see Feature::$value_type}; the
 * override stores it as a string, so a config grant here speaks the same value vocabulary as a
 * plan grant.
 */
readonly class OrganizationFeatureOverrides
{
    public function __construct(private RecordsAudit $audit) {}

    /**
     * Grant (`$granted = true`) or revoke (`$granted = false`) a feature for one org, upserting
     * the single override row and recording the change. Returns the persisted override.
     */
    public function set(
        string $organizationId,
        Feature $feature,
        bool $granted,
        ?string $value = null,
        ?string $reason = null,
        ?string $actor = null,
    ): OrganizationFeatureOverride {
        $existing = OrganizationFeatureOverride::query()
            ->where('organization_id', $organizationId)
            ->where('feature_id', $feature->id)
            ->first();

        $before = $existing instanceof OrganizationFeatureOverride
            ? ['granted' => $existing->granted, 'value' => $existing->value]
            : null;

        $override = OrganizationFeatureOverride::query()->updateOrCreate(
            ['organization_id' => $organizationId, 'feature_id' => $feature->id],
            [
                'granted' => $granted,
                // Only a config grant carries a value; a revoke or a boolean grant clears it.
                'value' => $granted && $feature->type->carriesValue() && $value !== null && $value !== '' ? $value : null,
                'reason' => $reason,
            ],
        );

        $this->record(
            $organizationId,
            $feature,
            $granted ? 'grant' : 'revoke',
            $granted
                ? sprintf('Granted feature “%s” to the organization.', $feature->key)
                : sprintf('Revoked feature “%s” from the organization.', $feature->key),
            [
                'feature' => $feature->key,
                'granted' => $granted,
                'value' => $override->value,
                'reason' => $reason,
                'before' => $before,
            ],
            $actor,
        );

        return $override;
    }

    /**
     * Remove the override for one `(org, feature)`, restoring the plan-resolved answer. A no-op
     * (no audit event) when there was no override to clear.
     */
    public function clear(string $organizationId, Feature $feature, ?string $actor = null): void
    {
        $existing = OrganizationFeatureOverride::query()
            ->where('organization_id', $organizationId)
            ->where('feature_id', $feature->id)
            ->first();

        if (! $existing instanceof OrganizationFeatureOverride) {
            return;
        }

        $before = ['granted' => $existing->granted, 'value' => $existing->value];
        $existing->delete();

        $this->record(
            $organizationId,
            $feature,
            'clear',
            sprintf('Cleared the “%s” feature override — restored the plan-resolved value.', $feature->key),
            ['feature' => $feature->key, 'before' => $before],
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(string $organizationId, Feature $feature, string $direction, string $summary, array $metadata, ?string $actor): void
    {
        $this->audit->record(
            action: AuditAction::OrganizationFeatureOverridden,
            target: AuditTarget::of('organization', $organizationId, $organizationId),
            summary: $summary,
            metadata: ['direction' => $direction] + $metadata,
            // When the caller names the operator, attribute the event to them; otherwise the
            // recorder resolves the current console session (falling back to the system sentinel).
            actor: $actor !== null && $actor !== '' ? new AuditActor($actor, $actor) : null,
        );
    }
}
