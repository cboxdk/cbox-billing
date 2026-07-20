<?php

declare(strict_types=1);

namespace App\Billing\Audit;

use App\Billing\Mode\BillingContext;
use App\Models\OperatorAuditEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The console read model for the audit-log area: a filtered, paginated view over the immutable
 * trail. Read-only — it never writes. Filters compose (AND) and are all optional: free-text on
 * actor/summary/target, an exact action, an actor sub, a target type/id, an organization, an
 * inclusive date range. Results are newest-first by sequence.
 *
 * The global trail is append-only and unscoped (one hash chain across every plane), so this view
 * ALWAYS constrains to the CURRENT environment key — the console shows only the active plane's
 * events, and two named sandboxes never co-mingle in the log (a binary `livemode` filter could not
 * tell them apart).
 */
readonly class AuditLogQuery
{
    public function __construct(private BillingContext $context) {}

    /**
     * @param  array{
     *     q?: ?string, action?: ?string, actor?: ?string, target_type?: ?string,
     *     organization_id?: ?string, from?: ?string, to?: ?string, livemode?: ?bool
     * }  $filters
     * @return LengthAwarePaginator<int, OperatorAuditEvent>
     */
    public function paginate(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        return $this->build($filters)
            ->orderByDesc('sequence')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OperatorAuditEvent>
     */
    private function build(array $filters): Builder
    {
        // Deny-by-default cross-plane: only the current environment's events are ever shown.
        $query = OperatorAuditEvent::query()->where('environment', $this->context->environmentKey());

        $q = $this->str($filters['q'] ?? null);
        if ($q !== null) {
            $query->where(function (Builder $sub) use ($q): void {
                $sub->where('summary', 'like', '%'.$q.'%')
                    ->orWhere('actor_name', 'like', '%'.$q.'%')
                    ->orWhere('actor_sub', 'like', '%'.$q.'%')
                    ->orWhere('target_id', 'like', '%'.$q.'%');
            });
        }

        $this->whereEquals($query, 'action', $filters['action'] ?? null);
        $this->whereEquals($query, 'actor_sub', $filters['actor'] ?? null);
        $this->whereEquals($query, 'target_type', $filters['target_type'] ?? null);
        $this->whereEquals($query, 'organization_id', $filters['organization_id'] ?? null);

        $from = $this->str($filters['from'] ?? null);
        if ($from !== null) {
            $query->where('occurred_at', '>=', $from.' 00:00:00');
        }

        $to = $this->str($filters['to'] ?? null);
        if ($to !== null) {
            $query->where('occurred_at', '<=', $to.' 23:59:59');
        }

        if (isset($filters['livemode']) && is_bool($filters['livemode'])) {
            $query->where('livemode', $filters['livemode']);
        }

        return $query;
    }

    /**
     * @param  Builder<OperatorAuditEvent>  $query
     */
    private function whereEquals(Builder $query, string $column, mixed $value): void
    {
        $value = $this->str($value);

        if ($value !== null) {
            $query->where($column, $value);
        }
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * The distinct actions present in the trail, for the filter dropdown.
     *
     * @return list<string>
     */
    public function distinctActions(): array
    {
        /** @var list<string> $actions */
        $actions = OperatorAuditEvent::query()
            ->where('environment', $this->context->environmentKey())
            ->distinct()->orderBy('action')->pluck('action')->all();

        return $actions;
    }
}
