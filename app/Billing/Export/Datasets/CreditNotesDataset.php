<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * One row per credit note — the legal record of a refund or adjustment against an invoice
 * (net/tax/gross in minor units, reason, kind). Issued by the engine and never mutated after
 * the fact, but exported as an upsert on the surrogate id for warehouse idempotency.
 */
class CreditNotesDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'credit_notes';
    }

    protected function subjectColumn(): ?string
    {
        return 'organization_id';
    }

    public function label(): string
    {
        return 'Credit notes';
    }

    public function description(): string
    {
        return 'Credit notes (refund/adjustment legal records) with net/tax/gross in minor units.';
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
        return 'credit_notes';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate credit-note id (stable merge key).'),
            ExportColumn::string('number', 'Credit-note number.'),
            ExportColumn::string('invoice_number', 'The invoice number this note credits.'),
            ExportColumn::integer('invoice_id', 'The credited invoice id, if linked.'),
            ExportColumn::string('organization_id', 'The organization id.'),
            ExportColumn::string('seller', 'The issuing seller entity key.'),
            ExportColumn::string('currency', 'ISO-4217 currency of the amounts.'),
            ExportColumn::integer('net_minor', 'Net credited amount in minor units.'),
            ExportColumn::integer('tax_minor', 'Tax credited in minor units.'),
            ExportColumn::integer('gross_minor', 'Gross credited amount in minor units.'),
            ExportColumn::string('reason', 'Reason for the credit note.'),
            ExportColumn::string('kind', 'Credit-note kind (e.g. refund, adjustment).'),
            ExportColumn::timestamp('issued_at', 'When the credit note was issued.'),
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
            'invoice_number' => Coerce::string($record['invoice_number'] ?? null),
            'invoice_id' => Coerce::int($record['invoice_id'] ?? null),
            'organization_id' => Coerce::string($record['organization_id'] ?? null),
            'seller' => Coerce::string($record['seller'] ?? null),
            'currency' => Coerce::string($record['currency'] ?? null),
            'net_minor' => Coerce::int($record['net_minor'] ?? null),
            'tax_minor' => Coerce::int($record['tax_minor'] ?? null),
            'gross_minor' => Coerce::int($record['gross_minor'] ?? null),
            'reason' => Coerce::string($record['reason'] ?? null),
            'kind' => Coerce::string($record['kind'] ?? null),
            'issued_at' => Coerce::timestamp($record['issued_at'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
            'updated_at' => Coerce::timestamp($record['updated_at'] ?? null),
        ];
    }
}
