<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Contracts\ExportDataset;
use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\ValueObjects\ExportColumn;
use App\Billing\Export\ValueObjects\ExportCursor;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Export\ValueObjects\ExportRow;
use App\Billing\Mode\EnvironmentScope;
use App\Billing\Support\SubscriptionRevenue;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * A COMPUTED, point-in-time revenue snapshot — one row per subscription with its monthly and
 * annual recurring amount as the engine prices it right now (seat-aware, net of any recurring
 * coupon), in the account's billing currency. This is not a table read: each row is derived by
 * the SAME {@see SubscriptionRevenue} calculator the dashboard and invoices use, so an exported
 * snapshot ties out to the console figures.
 *
 * A snapshot is a full recompute each run (no meaningful watermark), so its sync mode is
 * {@see SyncMode::Snapshot} and a load truncates-and-replaces. The plane is filtered explicitly
 * (bypassing the ambient scope) so a live snapshot never includes test subscriptions.
 */
class RevenueSnapshotDataset implements ExportDataset
{
    public function key(): string
    {
        return 'revenue_snapshot';
    }

    public function label(): string
    {
        return 'Revenue snapshot';
    }

    public function description(): string
    {
        return 'Per-subscription monthly/annual recurring revenue as priced right now (engine-computed).';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Snapshot;
    }

    public function mergeKeys(): array
    {
        return ['subscription_id'];
    }

    public function cursor(): ExportCursor
    {
        return ExportCursor::id('id');
    }

    public function dateColumn(): ?string
    {
        return null;
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('subscription_id', 'The subscription the snapshot row is for.'),
            ExportColumn::string('organization_id', 'The owning organization id.'),
            ExportColumn::integer('plan_id', 'The current plan id.'),
            ExportColumn::string('status', 'Engine lifecycle status at snapshot time.'),
            ExportColumn::integer('seats', 'Purchased seat quantity priced into the amount.'),
            ExportColumn::string('currency', 'ISO-4217 billing currency of the amounts.'),
            ExportColumn::integer('mrr_minor', 'Monthly recurring revenue in minor units.'),
            ExportColumn::integer('arr_minor', 'Annual recurring revenue (MRR × 12) in minor units.'),
            ExportColumn::timestamp('snapshot_at', 'When this snapshot row was computed.'),
        ];
    }

    public function rows(ExportQuery $query): iterable
    {
        $snapshotAt = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z');

        $subscriptions = Subscription::query()
            ->withoutGlobalScope(EnvironmentScope::class)
            ->where('environment', $query->environment)
            ->with(['plan.prices', 'coupon'])
            ->orderBy('id')
            ->lazyById($this->chunkSize());

        foreach ($subscriptions as $subscription) {
            $monthly = SubscriptionRevenue::monthly($subscription);

            yield new ExportRow([
                'subscription_id' => (int) $subscription->id,
                'organization_id' => $subscription->organization_id,
                'plan_id' => (int) $subscription->plan_id,
                'status' => $subscription->status->value,
                'seats' => (int) $subscription->seats,
                'currency' => $monthly->currency(),
                'mrr_minor' => $monthly->minor(),
                'arr_minor' => $monthly->minor() * 12,
                'snapshot_at' => $snapshotAt,
            ], (string) $subscription->id);
        }
    }

    private function chunkSize(): int
    {
        $configured = config('billing.export.chunk_size');

        return is_numeric($configured) ? max(1, (int) $configured) : 500;
    }
}
