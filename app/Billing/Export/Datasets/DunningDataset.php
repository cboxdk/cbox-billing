<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;

/**
 * One row per dunning / smart-retry record — a failed renewal charge being chased (attempt
 * counters, decline classification, save-offer, next-attempt schedule, recovery status). The
 * recovery-analytics source a RevOps team measures involuntary churn and recovery rate against.
 */
class DunningDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'dunning';
    }

    public function label(): string
    {
        return 'Dunning / recovery';
    }

    public function description(): string
    {
        return 'Smart-retry dunning records with decline classification, schedule and recovery status.';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Upsert;
    }

    public function dateColumn(): ?string
    {
        return 'first_failed_at';
    }

    protected function table(): string
    {
        return 'payment_retries';
    }

    protected function subjectColumn(): ?string
    {
        return 'organization_id';
    }

    public function schema(): array
    {
        return [
            ExportColumn::integer('id', 'Surrogate dunning-record id (stable merge key).'),
            ExportColumn::integer('invoice_id', 'The past-due invoice being chased.'),
            ExportColumn::string('organization_id', 'The delinquent organization id.'),
            ExportColumn::integer('subscription_id', 'The affected subscription id, if any.'),
            ExportColumn::integer('attempts', 'Retry attempts made so far.'),
            ExportColumn::integer('max_attempts', 'The retry ceiling for this record.'),
            ExportColumn::string('status', 'retrying, recovered, exhausted or stopped.'),
            ExportColumn::string('decline_code', 'Gateway decline code of the last failure.'),
            ExportColumn::string('decline_category', 'Classified decline category driving the strategy.'),
            ExportColumn::string('save_offer_key', 'Save-offer key presented, if any.'),
            ExportColumn::string('save_offer_label', 'Save-offer label presented, if any.'),
            ExportColumn::timestamp('first_failed_at', 'When the charge first failed.'),
            ExportColumn::timestamp('next_attempt_at', 'When the next retry is scheduled.'),
            ExportColumn::timestamp('last_attempt_at', 'When the last retry ran.'),
            ExportColumn::string('last_reference', 'Gateway reference of the last attempt.'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
            ExportColumn::timestamp('created_at', 'Row creation instant.'),
            ExportColumn::timestamp('updated_at', 'Row last-change instant.'),
        ];
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::int($record['id'] ?? null),
            'invoice_id' => Coerce::int($record['invoice_id'] ?? null),
            'organization_id' => Coerce::string($record['organization_id'] ?? null),
            'subscription_id' => Coerce::int($record['subscription_id'] ?? null),
            'attempts' => Coerce::int($record['attempts'] ?? null),
            'max_attempts' => Coerce::int($record['max_attempts'] ?? null),
            'status' => Coerce::string($record['status'] ?? null),
            'decline_code' => Coerce::string($record['decline_code'] ?? null),
            'decline_category' => Coerce::string($record['decline_category'] ?? null),
            'save_offer_key' => Coerce::string($record['save_offer_key'] ?? null),
            'save_offer_label' => Coerce::string($record['save_offer_label'] ?? null),
            'first_failed_at' => Coerce::timestamp($record['first_failed_at'] ?? null),
            'next_attempt_at' => Coerce::timestamp($record['next_attempt_at'] ?? null),
            'last_attempt_at' => Coerce::timestamp($record['last_attempt_at'] ?? null),
            'last_reference' => Coerce::string($record['last_reference'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
            'updated_at' => Coerce::timestamp($record['updated_at'] ?? null),
        ];
    }
}
