<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;
use Illuminate\Database\Query\Builder;

/**
 * One row per settled payment / receipt — the money-received view, projected from the invoices
 * that have a settlement instant (`paid_at`). The gross amount is the invoice total in minor
 * units with its gateway reference, so a finance team reconciles cash against the gateway
 * without joining back to the full invoice head. Restricted to paid rows via a standing filter.
 */
class PaymentsDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'payments';
    }

    public function label(): string
    {
        return 'Payments / receipts';
    }

    public function description(): string
    {
        return 'Settled invoices as payment receipts (amount received, gateway reference, settled instant).';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Upsert;
    }

    public function dateColumn(): ?string
    {
        return 'paid_at';
    }

    protected function table(): string
    {
        return 'invoices';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'The settled invoice id (stable merge key).'),
            ExportColumn::string('number', 'The invoice number the payment settled.'),
            ExportColumn::string('organization_id', 'The paying organization id.'),
            ExportColumn::string('seller', 'The receiving seller entity key.'),
            ExportColumn::string('currency', 'ISO-4217 currency of the payment.'),
            ExportColumn::integer('amount_minor', 'Gross amount received, in minor units.'),
            ExportColumn::string('gateway_reference', 'Payment gateway reference.'),
            ExportColumn::timestamp('paid_at', 'Settlement instant.'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
        ];
    }

    protected function constrain(Builder $builder): void
    {
        $builder->whereNotNull('invoices.paid_at');
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::int($record['id'] ?? null),
            'number' => Coerce::string($record['number'] ?? null),
            'organization_id' => Coerce::string($record['organization_id'] ?? null),
            'seller' => Coerce::string($record['seller'] ?? null),
            'currency' => Coerce::string($record['currency'] ?? null),
            'amount_minor' => Coerce::int($record['total_minor'] ?? null),
            'gateway_reference' => Coerce::string($record['gateway_reference'] ?? null),
            'paid_at' => Coerce::timestamp($record['paid_at'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
        ];
    }
}
