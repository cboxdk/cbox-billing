<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Enums\SyncMode;
use App\Billing\Export\Support\Coerce;
use App\Billing\Export\ValueObjects\ExportColumn;
use App\Billing\Export\ValueObjects\ExportCursor;

/**
 * One row per customer organization — the billing account dimension (name, contact, locale,
 * currency, tax registration, suspension). The primary key is a string org id, so the
 * incremental cursor is `updated_at`; a mutable dimension, upserted on the org id.
 */
class CustomersDataset extends AbstractDataset
{
    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers (organizations)';
    }

    public function description(): string
    {
        return 'Billing-account organizations with contact, locale, currency and tax registration.';
    }

    public function syncMode(): SyncMode
    {
        return SyncMode::Upsert;
    }

    public function cursor(): ExportCursor
    {
        return ExportCursor::timestamp('updated_at');
    }

    public function dateColumn(): ?string
    {
        return 'created_at';
    }

    protected function table(): string
    {
        return 'organizations';
    }

    protected function subjectColumn(): ?string
    {
        return 'id';
    }

    public function schema(): array
    {
        return [
            ExportColumn::string('id', 'The organization id (stable merge key).'),
            ExportColumn::string('environment_key', 'The environment/tenant key, if set.'),
            ExportColumn::string('name', 'Organization display name.'),
            ExportColumn::string('billing_email', 'Billing contact email.'),
            ExportColumn::string('locale', 'Preferred locale (BCP-47).'),
            ExportColumn::string('billing_currency', 'Chosen billing currency (ISO-4217), if any.'),
            ExportColumn::string('billing_country', 'Billing country (ISO-3166 alpha-2).'),
            ExportColumn::string('billing_subdivision', 'Billing subdivision/state.'),
            ExportColumn::string('tax_id', 'Registered tax id, if provided.'),
            ExportColumn::boolean('tax_id_validated', 'Whether the tax id was validated.'),
            ExportColumn::timestamp('suspended_at', 'When the account was suspended, if any.'),
            ExportColumn::boolean('livemode', 'True for the live plane, false for test/sandbox.'),
            ExportColumn::timestamp('created_at', 'Account creation instant.'),
            ExportColumn::timestamp('updated_at', 'Row last-change instant.'),
        ];
    }

    protected function projectRow(array $record): array
    {
        return [
            'id' => Coerce::string($record['id'] ?? null),
            'environment_key' => Coerce::string($record['environment_key'] ?? null),
            'name' => Coerce::string($record['name'] ?? null),
            'billing_email' => Coerce::string($record['billing_email'] ?? null),
            'locale' => Coerce::string($record['locale'] ?? null),
            'billing_currency' => Coerce::string($record['billing_currency'] ?? null),
            'billing_country' => Coerce::string($record['billing_country'] ?? null),
            'billing_subdivision' => Coerce::string($record['billing_subdivision'] ?? null),
            'tax_id' => Coerce::string($record['tax_id'] ?? null),
            'tax_id_validated' => Coerce::bool($record['tax_id_validated'] ?? null),
            'suspended_at' => Coerce::timestamp($record['suspended_at'] ?? null),
            'livemode' => Coerce::bool($record['livemode'] ?? null),
            'created_at' => Coerce::timestamp($record['created_at'] ?? null),
            'updated_at' => Coerce::timestamp($record['updated_at'] ?? null),
        ];
    }
}
