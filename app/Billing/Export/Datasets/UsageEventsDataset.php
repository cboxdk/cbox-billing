<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;
use App\Billing\Export\ValueObjects\ExportQuery;
use Illuminate\Database\Query\Builder;

/**
 * The raw, immutable usage-event stream — the metering source of truth invoices are computed
 * FROM, and the single highest-value export for a data/RevOps team. One NDJSON object per
 * appended event, in the append-only order of the log. This is the dataset a warehouse ingests
 * continuously and re-aggregates independently of the billing engine.
 *
 * The event log carries no `livemode` column of its own; the plane is derived from the event's
 * organization (test orgs and live orgs are disjoint id sets), so a live export can never
 * include a test org's events. The `occurred_at` axis is a millisecond epoch, so it is emitted
 * both as an ISO-8601 string and as its raw integer, and a date range is matched in milliseconds.
 */
class UsageEventsDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'usage_events';
    }

    public function label(): string
    {
        return 'Usage events (raw)';
    }

    public function description(): string
    {
        return 'The immutable per-event metering log — the source of truth invoices are computed from.';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Append;
    }

    protected function table(): string
    {
        return 'billing_usage_events';
    }

    public function schema(): array
    {
        return [
            ExportColumn::string('event_id', 'The stable, deduplicated event id.'),
            ExportColumn::string('org', 'The organization the event was metered for.'),
            ExportColumn::string('meter', 'The meter key the event counts toward.'),
            ExportColumn::string('service', 'The emitting service/source.'),
            ExportColumn::integer('value', 'The metered value (integer units).'),
            ExportColumn::string('unique_key', 'Distinct-count key (UniqueCount aggregation), if any.'),
            ExportColumn::integer('weight', 'Per-event multiplier (WeightedSum aggregation).'),
            ExportColumn::timestamp('occurred_at', 'Event instant, ISO-8601 UTC (from the ms epoch).'),
            ExportColumn::integer('occurred_at_ms', 'Event instant as the raw millisecond epoch.'),
        ];
    }

    protected function scopePlane(Builder $builder, bool $livemode): void
    {
        $builder->whereIn('billing_usage_events.org', $this->planeIds('organizations', $livemode));
    }

    protected function applyRange(Builder $builder, ExportQuery $query): void
    {
        // occurred_at is a millisecond epoch — match the range in milliseconds.
        if ($query->from !== null) {
            $builder->where('billing_usage_events.occurred_at', '>=', $query->from->getTimestampMs());
        }
        if ($query->to !== null) {
            $builder->where('billing_usage_events.occurred_at', '<=', $query->to->getTimestampMs());
        }
    }

    protected function projectRow(array $record): array
    {
        return [
            'event_id' => Coerce::string($record['event_id'] ?? null),
            'org' => Coerce::string($record['org'] ?? null),
            'meter' => Coerce::string($record['meter'] ?? null),
            'service' => Coerce::string($record['service'] ?? null),
            'value' => Coerce::int($record['value'] ?? null),
            'unique_key' => Coerce::string($record['unique_key'] ?? null),
            'weight' => Coerce::int($record['weight'] ?? null),
            'occurred_at' => Coerce::fromMillis($record['occurred_at'] ?? null),
            'occurred_at_ms' => Coerce::int($record['occurred_at'] ?? null),
        ];
    }
}
