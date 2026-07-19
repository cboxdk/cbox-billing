<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * One row per seat assignment — a purchased Full seat handed to a specific member subject, with
 * its source (manual or auto). A mutable dimension (a seat is released), upserted on the id.
 */
class SeatAssignmentsDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'seat_assignments';
    }

    protected function subjectColumn(): ?string
    {
        return 'organization_id';
    }

    public function label(): string
    {
        return 'Seat assignments';
    }

    public function description(): string
    {
        return 'Purchased seats assigned to member subjects, with assignment source and instant.';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Upsert;
    }

    public function dateColumn(): ?string
    {
        return 'assigned_at';
    }

    protected function table(): string
    {
        return 'seat_assignments';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate assignment id (stable merge key).'),
            ExportColumn::string('organization_id', 'The owning organization id.'),
            ExportColumn::string('subject', 'The member subject holding the seat.'),
            ExportColumn::string('source', 'Assignment source (manual or auto).'),
            ExportColumn::timestamp('assigned_at', 'When the seat was assigned.'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
            ExportColumn::timestamp('created_at', 'Row creation instant.'),
            ExportColumn::timestamp('updated_at', 'Row last-change instant.'),
        ];
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::int($record['id'] ?? null),
            'organization_id' => Coerce::string($record['organization_id'] ?? null),
            'subject' => Coerce::string($record['subject'] ?? null),
            'source' => Coerce::string($record['source'] ?? null),
            'assigned_at' => Coerce::timestamp($record['assigned_at'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
            'updated_at' => Coerce::timestamp($record['updated_at'] ?? null),
        ];
    }
}
