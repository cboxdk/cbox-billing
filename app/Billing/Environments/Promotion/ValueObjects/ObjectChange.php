<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\ValueObjects;

use App\Billing\Environments\Promotion\Enums\ChangeStatus;
use App\Billing\Environments\Promotion\PromotionGroup;

/**
 * The diff for one selected config object: its group, type and natural key, how it compares to
 * the target ({@see ChangeStatus}), the field-level changes (for an update), and the nested
 * changes to its children (a plan's changed prices, a seller's changed tax rows). A pure preview
 * value — it is computed with NO writes and is what the console renders and the audit log records.
 *
 * @phpstan-type FieldChangeList list<FieldChange>
 * @phpstan-type ChildChangeList list<ObjectChange>
 */
readonly class ObjectChange
{
    /**
     * @param  list<FieldChange>  $fieldChanges
     * @param  list<ObjectChange>  $childChanges  nested created/updated children (unchanged ones omitted)
     */
    public function __construct(
        public PromotionGroup $group,
        public string $type,
        public string $naturalKey,
        public string $label,
        public ChangeStatus $status,
        public array $fieldChanges = [],
        public array $childChanges = [],
    ) {}

    /** A compact `type:key` token for logs and messages. */
    public function token(): string
    {
        return $this->type.':'.$this->naturalKey;
    }

    /** Whether this object (or any child) would write on apply. */
    public function writes(): bool
    {
        return $this->status->writes();
    }

    /**
     * A JSON-safe summary for the audit metadata: the object token, its status, and the changed
     * field names (values are omitted — the trail records WHAT changed, not the payload).
     *
     * @return array{object: string, status: string, fields: list<string>}
     */
    public function toAuditArray(): array
    {
        return [
            'object' => $this->token(),
            'status' => $this->status->value,
            'fields' => array_map(static fn (FieldChange $c): string => $c->field, $this->fieldChanges),
        ];
    }
}
