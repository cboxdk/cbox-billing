<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;
use Illuminate\Database\Query\Builder;

/**
 * One row per invoice line — the itemised breakdown (description, quantity, unit and amount in
 * minor units) joined to its parent invoice by `invoice_id`. Lines carry no plane column of
 * their own, so the plane is taken from the parent invoice; they have no independent date axis,
 * so a date range is applied at the invoice level, not here.
 */
class InvoiceLinesDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'invoice_lines';
    }

    public function label(): string
    {
        return 'Invoice lines';
    }

    public function description(): string
    {
        return 'Per-line invoice detail (description, quantity, unit and amount in minor units).';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Upsert;
    }

    protected function table(): string
    {
        return 'invoice_lines';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate line id (stable merge key).'),
            ExportColumn::integer('invoice_id', 'Parent invoice id.'),
            ExportColumn::string('description', 'Line description.'),
            ExportColumn::integer('quantity', 'Billed quantity.'),
            ExportColumn::integer('unit_minor', 'Unit price in minor units.'),
            ExportColumn::integer('net_minor', 'Net line amount in minor units.'),
            ExportColumn::integer('amount_minor', 'Gross line amount in minor units.'),
            ExportColumn::timestamp('created_at', 'Row creation instant.'),
            ExportColumn::timestamp('updated_at', 'Row last-change instant.'),
        ];
    }

    protected function scopePlane(Builder $builder, bool $livemode): void
    {
        $builder->whereIn('invoice_lines.invoice_id', $this->planeIds('invoices', $livemode));
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::int($record['id'] ?? null),
            'invoice_id' => Coerce::int($record['invoice_id'] ?? null),
            'description' => Coerce::string($record['description'] ?? null),
            'quantity' => Coerce::int($record['quantity'] ?? null),
            'unit_minor' => Coerce::int($record['unit_minor'] ?? null),
            'net_minor' => Coerce::int($record['net_minor'] ?? null),
            'amount_minor' => Coerce::int($record['amount_minor'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
            'updated_at' => Coerce::timestamp($record['updated_at'] ?? null),
        ];
    }
}
