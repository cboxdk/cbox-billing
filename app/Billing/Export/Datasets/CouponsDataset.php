<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * One row per coupon — the discount definition (percentage or fixed amount, duration, redemption
 * caps, applicability). A mutable dimension (redemption counters and archive state change), so
 * it upserts on the surrogate id.
 */
class CouponsDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'coupons';
    }

    public function label(): string
    {
        return 'Coupons';
    }

    public function description(): string
    {
        return 'Coupon definitions with discount, duration, redemption caps and applicability.';
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
        return 'coupons';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate coupon id (stable merge key).'),
            ExportColumn::string('code', 'The redemption code (stored upper-cased).'),
            ExportColumn::string('name', 'Human label.'),
            ExportColumn::string('discount_type', 'percent or fixed_amount.'),
            ExportColumn::integer('percent_off', 'Percentage off (when percent).'),
            ExportColumn::integer('amount_off_minor', 'Fixed amount off in minor units (when fixed_amount).'),
            ExportColumn::string('currency', 'ISO-4217 currency of a fixed amount, if any.'),
            ExportColumn::string('duration', 'once, repeating or forever.'),
            ExportColumn::integer('duration_in_periods', 'Number of periods for a repeating coupon.'),
            ExportColumn::integer('max_redemptions', 'Global redemption cap, if any.'),
            ExportColumn::integer('times_redeemed', 'Redemptions counted so far.'),
            ExportColumn::integer('max_redemptions_per_customer', 'Per-customer redemption cap, if any.'),
            ExportColumn::timestamp('redeem_by', 'Redemption deadline, if any.'),
            ExportColumn::string('applies_to', 'all or plans.'),
            ExportColumn::json('applies_to_plans', 'Plan keys the coupon applies to (when scoped).'),
            ExportColumn::boolean('active', 'Whether the coupon is active.'),
            ExportColumn::timestamp('archived_at', 'Archive instant, if archived.'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
            ExportColumn::timestamp('created_at', 'Row creation instant.'),
            ExportColumn::timestamp('updated_at', 'Row last-change instant.'),
        ];
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::int($record['id'] ?? null),
            'code' => Coerce::string($record['code'] ?? null),
            'name' => Coerce::string($record['name'] ?? null),
            'discount_type' => Coerce::string($record['discount_type'] ?? null),
            'percent_off' => Coerce::int($record['percent_off'] ?? null),
            'amount_off_minor' => Coerce::int($record['amount_off_minor'] ?? null),
            'currency' => Coerce::string($record['currency'] ?? null),
            'duration' => Coerce::string($record['duration'] ?? null),
            'duration_in_periods' => Coerce::int($record['duration_in_periods'] ?? null),
            'max_redemptions' => Coerce::int($record['max_redemptions'] ?? null),
            'times_redeemed' => Coerce::int($record['times_redeemed'] ?? null),
            'max_redemptions_per_customer' => Coerce::int($record['max_redemptions_per_customer'] ?? null),
            'redeem_by' => Coerce::timestamp($record['redeem_by'] ?? null),
            'applies_to' => Coerce::string($record['applies_to'] ?? null),
            'applies_to_plans' => Coerce::json($record['applies_to_plans'] ?? null),
            'active' => Coerce::bool($record['active'] ?? null),
            'archived_at' => Coerce::timestamp($record['archived_at'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
            'updated_at' => Coerce::timestamp($record['updated_at'] ?? null),
        ];
    }
}
