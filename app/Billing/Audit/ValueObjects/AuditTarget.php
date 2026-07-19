<?php

declare(strict_types=1);

namespace App\Billing\Audit\ValueObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * The resource an audited action touched: a stable type slug (`invoice`, `organization`,
 * `license`…), its id, and the organization it belongs to (for org-scoped filtering and the
 * DSAR bundle). All three are optional — a settings write has no single target resource.
 */
readonly class AuditTarget
{
    public function __construct(
        public ?string $type = null,
        public ?string $id = null,
        public ?string $organizationId = null,
    ) {}

    /** No specific resource (e.g. a global settings change). */
    public static function none(?string $organizationId = null): self
    {
        return new self(null, null, $organizationId);
    }

    /**
     * A target derived from an Eloquent model: the model's short class name (lower-cased) as
     * the type, its key as the id, and — when the model exposes one — its organization id.
     */
    public static function model(Model $model, ?string $organizationId = null): self
    {
        $type = strtolower(class_basename($model));
        $key = $model->getKey();
        $org = $organizationId;

        if ($org === null) {
            $candidate = $model->getAttribute('organization_id');
            $org = is_string($candidate) ? $candidate : null;
        }

        return new self($type, is_scalar($key) ? (string) $key : null, $org);
    }

    /** An explicit type + id, e.g. an organization keyed by its own id. */
    public static function of(string $type, ?string $id, ?string $organizationId = null): self
    {
        return new self($type, $id, $organizationId ?? ($type === 'organization' ? $id : null));
    }
}
