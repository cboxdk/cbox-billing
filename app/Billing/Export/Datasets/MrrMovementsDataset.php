<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;
use Illuminate\Database\Query\Builder;

/**
 * One row per recorded MRR movement — the recurring-revenue bridge a finance team reconciles
 * (new · expansion · contraction · churn · reactivation), each with the previous and new
 * monthly-recurring figure in minor units. Append-only: a movement is a historical fact and is
 * never rewritten. The plane comes from the movement's organization (no own `livemode` column).
 */
class MrrMovementsDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'mrr_movements';
    }

    public function label(): string
    {
        return 'MRR movements';
    }

    public function description(): string
    {
        return 'Recurring-revenue movements (new/expansion/contraction/churn/reactivation) in minor units.';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Append;
    }

    public function dateColumn(): ?string
    {
        return 'occurred_at';
    }

    protected function table(): string
    {
        return 'subscription_mrr_movements';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate movement id.'),
            ExportColumn::integer('subscription_id', 'The subscription the movement is attributed to.'),
            ExportColumn::string('organization_id', 'The owning organization id.'),
            ExportColumn::string('currency', 'ISO-4217 currency of the MRR figures.'),
            ExportColumn::string('kind', 'Movement kind (new, expansion, contraction, churn, reactivation).'),
            ExportColumn::integer('previous_mrr_minor', 'MRR before the movement, in minor units.'),
            ExportColumn::integer('new_mrr_minor', 'MRR after the movement, in minor units.'),
            ExportColumn::integer('delta_mrr_minor', 'Signed change (new − previous), in minor units.'),
            ExportColumn::timestamp('occurred_at', 'When the movement occurred.'),
            ExportColumn::timestamp('created_at', 'Row creation instant.'),
        ];
    }

    protected function scopePlane(Builder $builder, bool $livemode): void
    {
        $builder->whereIn('subscription_mrr_movements.organization_id', $this->planeIds('organizations', $livemode));
    }

    protected function projectRow(array $record): array
    {
        $previous = Coerce::int($record['previous_mrr_minor'] ?? null) ?? 0;
        $new = Coerce::int($record['new_mrr_minor'] ?? null) ?? 0;

        return [
            'id' => Coerce::int($record['id'] ?? null),
            'subscription_id' => Coerce::int($record['subscription_id'] ?? null),
            'organization_id' => Coerce::string($record['organization_id'] ?? null),
            'currency' => Coerce::string($record['currency'] ?? null),
            'kind' => Coerce::string($record['kind'] ?? null),
            'previous_mrr_minor' => $previous,
            'new_mrr_minor' => $new,
            'delta_mrr_minor' => $new - $previous,
            'occurred_at' => Coerce::timestamp($record['occurred_at'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
        ];
    }
}
