<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * One row per subscription — the recurring commitment (plan, seats, lifecycle status, current
 * period, trial/cancel/pause markers). A mutable dimension, so it upserts on the surrogate id.
 */
class SubscriptionsDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'subscriptions';
    }

    public function label(): string
    {
        return 'Subscriptions';
    }

    public function description(): string
    {
        return 'Subscriptions with plan, seat quantity, lifecycle status and period markers.';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Upsert;
    }

    public function dateColumn(): ?string
    {
        return 'created_at';
    }

    protected function table(): string
    {
        return 'subscriptions';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate subscription id (stable merge key).'),
            ExportColumn::string('organization_id', 'The owning organization id.'),
            ExportColumn::integer('plan_id', 'The current plan id.'),
            ExportColumn::string('status', 'Engine lifecycle status.'),
            ExportColumn::string('display_standing', 'Materialized display standing (e.g. past_due).'),
            ExportColumn::integer('seats', 'Purchased seat quantity (the billed quantity).'),
            ExportColumn::boolean('cancel_at_period_end', 'Whether it is set to not renew.'),
            ExportColumn::timestamp('current_period_start', 'Start of the current billing period.'),
            ExportColumn::timestamp('current_period_end', 'End of the current billing period.'),
            ExportColumn::timestamp('trial_ends_at', 'Trial end instant, if trialing.'),
            ExportColumn::timestamp('canceled_at', 'Cancellation instant, if canceled.'),
            ExportColumn::timestamp('paused_at', 'Pause instant, if paused.'),
            ExportColumn::integer('pending_plan_id', 'A scheduled plan change target, if any.'),
            ExportColumn::timestamp('pending_effective_at', 'When a scheduled change takes effect.'),
            ExportColumn::integer('test_clock_id', 'Bound test clock id (test plane only).'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
            ExportColumn::timestamp('created_at', 'Subscription creation instant.'),
            ExportColumn::timestamp('updated_at', 'Row last-change instant.'),
        ];
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::int($record['id'] ?? null),
            'organization_id' => Coerce::string($record['organization_id'] ?? null),
            'plan_id' => Coerce::int($record['plan_id'] ?? null),
            'status' => Coerce::string($record['status'] ?? null),
            'display_standing' => Coerce::string($record['display_standing'] ?? null),
            'seats' => Coerce::int($record['seats'] ?? null),
            'cancel_at_period_end' => Coerce::bool($record['cancel_at_period_end'] ?? null),
            'current_period_start' => Coerce::timestamp($record['current_period_start'] ?? null),
            'current_period_end' => Coerce::timestamp($record['current_period_end'] ?? null),
            'trial_ends_at' => Coerce::timestamp($record['trial_ends_at'] ?? null),
            'canceled_at' => Coerce::timestamp($record['canceled_at'] ?? null),
            'paused_at' => Coerce::timestamp($record['paused_at'] ?? null),
            'pending_plan_id' => Coerce::int($record['pending_plan_id'] ?? null),
            'pending_effective_at' => Coerce::timestamp($record['pending_effective_at'] ?? null),
            'test_clock_id' => Coerce::int($record['test_clock_id'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
            'updated_at' => Coerce::timestamp($record['updated_at'] ?? null),
        ];
    }
}
