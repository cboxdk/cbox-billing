<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;
use App\Billing\Export\ValueObjects\ExportCursor;

/**
 * One row per issued on-prem license — the minted artifact's metadata (customer, deployment,
 * plan, entitlements, limits, validity window). The signed license `key` blob itself is
 * deliberately NOT exported: it is the offline-verifiable secret artifact, not analytics data.
 * Append-only (a renewal mints a fresh id under the same deployment); the string primary key
 * means the incremental cursor is the mint instant `issued_at`.
 */
class LicensesDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'licenses';
    }

    public function label(): string
    {
        return 'Licenses';
    }

    public function description(): string
    {
        return 'Issued on-prem license metadata (plan, entitlements, limits, validity window).';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Append;
    }

    public function cursor(): ExportCursor
    {
        return ExportCursor::timestamp('issued_at');
    }

    public function dateColumn(): ?string
    {
        return 'issued_at';
    }

    protected function table(): string
    {
        return 'issued_licenses';
    }

    protected function subjectColumn(): ?string
    {
        return 'customer_id';
    }

    public function schema(): array
    {
        return [
            ExportColumn::string('id', 'The license id (the artifact lid claim).'),
            ExportColumn::string('customer_id', 'The licensed customer id.'),
            ExportColumn::string('deployment_id', 'The deployment the license is bound to.'),
            ExportColumn::string('plan', 'The licensed plan/profile key.'),
            ExportColumn::json('entitlements', 'The granted capability entitlements.'),
            ExportColumn::json('limits', 'The enforced numeric limits.'),
            ExportColumn::string('licensed_domain', 'The domain binding, if any.'),
            ExportColumn::timestamp('issued_at', 'When the license was minted.'),
            ExportColumn::timestamp('not_before', 'Validity start instant.'),
            ExportColumn::timestamp('expires_at', 'Validity end instant.'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
        ];
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::string($record['id'] ?? null),
            'customer_id' => Coerce::string($record['customer_id'] ?? null),
            'deployment_id' => Coerce::string($record['deployment_id'] ?? null),
            'plan' => Coerce::string($record['plan'] ?? null),
            'entitlements' => Coerce::json($record['entitlements'] ?? null),
            'limits' => Coerce::json($record['limits'] ?? null),
            'licensed_domain' => Coerce::string($record['licensed_domain'] ?? null),
            'issued_at' => Coerce::timestamp($record['issued_at'] ?? null),
            'not_before' => Coerce::timestamp($record['not_before'] ?? null),
            'expires_at' => Coerce::timestamp($record['expires_at'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
        ];
    }
}
