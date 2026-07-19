<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * One row per coupon redemption — the fact that an organization (optionally a specific
 * subscription) redeemed a coupon at an instant. Append-only.
 */
class CouponRedemptionsDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'coupon_redemptions';
    }

    protected function subjectColumn(): ?string
    {
        return 'organization_id';
    }

    public function label(): string
    {
        return 'Coupon redemptions';
    }

    public function description(): string
    {
        return 'Per-redemption facts linking a coupon to an organization and subscription.';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Append;
    }

    public function dateColumn(): ?string
    {
        return 'redeemed_at';
    }

    protected function table(): string
    {
        return 'coupon_redemptions';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate redemption id.'),
            ExportColumn::integer('coupon_id', 'The redeemed coupon id.'),
            ExportColumn::string('organization_id', 'The redeeming organization id.'),
            ExportColumn::integer('subscription_id', 'The subscription the redemption bound to, if any.'),
            ExportColumn::timestamp('redeemed_at', 'When the coupon was redeemed.'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
            ExportColumn::timestamp('created_at', 'Row creation instant.'),
        ];
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::int($record['id'] ?? null),
            'coupon_id' => Coerce::int($record['coupon_id'] ?? null),
            'organization_id' => Coerce::string($record['organization_id'] ?? null),
            'subscription_id' => Coerce::int($record['subscription_id'] ?? null),
            'redeemed_at' => Coerce::timestamp($record['redeemed_at'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
        ];
    }
}
