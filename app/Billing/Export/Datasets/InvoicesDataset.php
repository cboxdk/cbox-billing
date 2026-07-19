<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * One row per issued invoice — the head record (money totals, period, lifecycle status,
 * settlement). Amounts are exact integer minor units paired with the ISO-4217 currency. The
 * accompanying {@see InvoiceLinesDataset} carries the per-line breakdown.
 */
class InvoicesDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Issued invoice headers with money totals, billing period and settlement status.';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Upsert;
    }

    public function dateColumn(): ?string
    {
        return 'issued_at';
    }

    protected function table(): string
    {
        return 'invoices';
    }

    protected function subjectColumn(): ?string
    {
        return 'organization_id';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate invoice id (stable merge key).'),
            ExportColumn::string('number', 'Human-facing invoice number (unique per seller).'),
            ExportColumn::string('organization_id', 'The billed organization id.'),
            ExportColumn::integer('subscription_id', 'The subscription this invoice billed, if any.'),
            ExportColumn::string('seller', 'The issuing seller entity key.'),
            ExportColumn::string('currency', 'ISO-4217 currency of every amount on this invoice.'),
            ExportColumn::integer('subtotal_minor', 'Net subtotal in minor units.'),
            ExportColumn::integer('tax_minor', 'Tax in minor units.'),
            ExportColumn::integer('total_minor', 'Gross total in minor units.'),
            ExportColumn::string('status', 'Lifecycle status (draft, open, paid, void, uncollectible).'),
            ExportColumn::timestamp('period_start', 'Start of the billed service period.'),
            ExportColumn::timestamp('period_end', 'End of the billed service period.'),
            ExportColumn::timestamp('issued_at', 'When the invoice was finalized/issued.'),
            ExportColumn::timestamp('due_at', 'Payment due instant.'),
            ExportColumn::timestamp('paid_at', 'Settlement instant, if paid.'),
            ExportColumn::string('gateway_reference', 'Payment gateway reference for the settlement.'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
            ExportColumn::timestamp('created_at', 'Row creation instant.'),
            ExportColumn::timestamp('updated_at', 'Row last-change instant.'),
        ];
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::int($record['id'] ?? null),
            'number' => Coerce::string($record['number'] ?? null),
            'organization_id' => Coerce::string($record['organization_id'] ?? null),
            'subscription_id' => Coerce::int($record['subscription_id'] ?? null),
            'seller' => Coerce::string($record['seller'] ?? null),
            'currency' => Coerce::string($record['currency'] ?? null),
            'subtotal_minor' => Coerce::int($record['subtotal_minor'] ?? null),
            'tax_minor' => Coerce::int($record['tax_minor'] ?? null),
            'total_minor' => Coerce::int($record['total_minor'] ?? null),
            'status' => Coerce::string($record['status'] ?? null),
            'period_start' => Coerce::timestamp($record['period_start'] ?? null),
            'period_end' => Coerce::timestamp($record['period_end'] ?? null),
            'issued_at' => Coerce::timestamp($record['issued_at'] ?? null),
            'due_at' => Coerce::timestamp($record['due_at'] ?? null),
            'paid_at' => Coerce::timestamp($record['paid_at'] ?? null),
            'gateway_reference' => Coerce::string($record['gateway_reference'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
            'updated_at' => Coerce::timestamp($record['updated_at'] ?? null),
        ];
    }
}
