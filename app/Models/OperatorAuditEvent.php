<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Audit\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One immutable, hash-chained entry in the operator audit trail. Rows are ONLY ever inserted
 * (a DB-level append-only guard refuses UPDATE/DELETE); the model reflects that by disabling
 * the `updated_at` timestamp and exposing no mutators of substance.
 *
 * `sequence` is the monotonic chain position; `hash` = H(prev_hash · canonical(payload)), the
 * link a verifier recomputes to detect tampering. `metadata` carries the typed before/after
 * diff (JSON) and NEVER a secret value.
 *
 * @property int $id
 * @property int $sequence
 * @property Carbon $occurred_at
 * @property string $actor_sub
 * @property string|null $actor_name
 * @property string|null $actor_ip
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string|null $organization_id
 * @property string $summary
 * @property array<string, mixed>|null $metadata
 * @property bool $livemode
 * @property string $prev_hash
 * @property string $hash
 * @property Carbon|null $created_at
 */
class OperatorAuditEvent extends Model
{
    /** Append-only: the row is never updated, so there is no `updated_at`. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'sequence', 'occurred_at', 'actor_sub', 'actor_name', 'actor_ip', 'action',
        'target_type', 'target_id', 'organization_id', 'summary', 'metadata', 'livemode',
        'prev_hash', 'hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
            'livemode' => 'boolean',
        ];
    }

    /** The typed action, or null for a value not in the current catalog (forward-compatible). */
    public function actionEnum(): ?AuditAction
    {
        return AuditAction::tryFrom($this->action);
    }

    /**
     * The recorded before-state, or an empty map when the event carries none.
     *
     * @return array<string, mixed>
     */
    public function before(): array
    {
        return $this->stringKeyed(($this->metadata ?? [])['before'] ?? []);
    }

    /**
     * The recorded after-state, or an empty map when the event carries none.
     *
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return $this->stringKeyed(($this->metadata ?? [])['after'] ?? []);
    }

    /** Whether this event carries a before/after diff to render. */
    public function hasDiff(): bool
    {
        return $this->before() !== [] || $this->after() !== [];
    }

    /**
     * Normalize a decoded JSON value into a string-keyed map (empty when it is not a map).
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
