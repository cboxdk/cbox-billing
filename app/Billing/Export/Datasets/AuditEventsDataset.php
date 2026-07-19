<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * One row per operator audit event — the tamper-evident trail, exported for an external SIEM /
 * evidence archive. The hash-chain columns (`sequence`, `prev_hash`, `hash`) travel with each
 * row so the export can be re-verified independently of the live table. Append-only by nature,
 * so it syncs as an append load.
 *
 * `metadata` carries the before/after diff as JSON; it never contains a secret (the recorder
 * logs references, not values), so the export is safe to ship off-box.
 */
class AuditEventsDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'audit_events';
    }

    public function label(): string
    {
        return 'Operator audit events';
    }

    public function description(): string
    {
        return 'The tamper-evident operator action trail, with hash-chain columns for independent verification.';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Append;
    }

    public function dateColumn(): ?string
    {
        return 'occurred_at';
    }

    protected function table(): string
    {
        return 'operator_audit_events';
    }

    protected function subjectColumn(): ?string
    {
        return 'organization_id';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate row id.'),
            ExportColumn::integer('sequence', 'Monotonic chain position (genesis = 1).'),
            ExportColumn::timestamp('occurred_at', 'When the action occurred.'),
            ExportColumn::string('actor_sub', 'The operator subject, or "system" for an unattended action.'),
            ExportColumn::string('actor_name', 'The operator display name at the time.'),
            ExportColumn::string('actor_ip', 'The request IP, when the action was interactive.'),
            ExportColumn::string('action', 'The typed action slug (e.g. invoice.refunded).'),
            ExportColumn::string('target_type', 'The affected resource type.'),
            ExportColumn::string('target_id', 'The affected resource id.'),
            ExportColumn::string('organization_id', 'The organization the target belongs to, if any.'),
            ExportColumn::string('summary', 'The human summary of the action.'),
            ExportColumn::json('metadata', 'The before/after diff and context (no secrets).'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
            ExportColumn::string('prev_hash', 'The previous row hash this row chains from.'),
            ExportColumn::string('hash', 'This row hash = H(prev_hash · canonical(payload)).'),
            ExportColumn::timestamp('created_at', 'Row insertion instant.'),
        ];
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::int($record['id'] ?? null),
            'sequence' => Coerce::int($record['sequence'] ?? null),
            'occurred_at' => Coerce::timestamp($record['occurred_at'] ?? null),
            'actor_sub' => Coerce::string($record['actor_sub'] ?? null),
            'actor_name' => Coerce::string($record['actor_name'] ?? null),
            'actor_ip' => Coerce::string($record['actor_ip'] ?? null),
            'action' => Coerce::string($record['action'] ?? null),
            'target_type' => Coerce::string($record['target_type'] ?? null),
            'target_id' => Coerce::string($record['target_id'] ?? null),
            'organization_id' => Coerce::string($record['organization_id'] ?? null),
            'summary' => Coerce::string($record['summary'] ?? null),
            'metadata' => Coerce::json($record['metadata'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
            'prev_hash' => Coerce::string($record['prev_hash'] ?? null),
            'hash' => Coerce::string($record['hash'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
        ];
    }
}
