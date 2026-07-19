<?php

declare(strict_types=1);

namespace App\Billing\Export\Enums;

/**
 * The warehouses this app emits copy-paste load manifests for. Every one of them ingests at
 * scale the SAME way — from staged files in object storage, not row-by-row inserts — so the
 * object-store sink is the single real delivery path and each warehouse only differs in the
 * exact `COPY`/`bq load`/DDL an operator (or a scheduled loader) runs against the staged files.
 *
 * {@see None} is the honest default for a sink that only stages files and leaves the load side
 * to the operator: no manifest dialect is assumed.
 */
enum Warehouse: string
{
    case Snowflake = 'snowflake';
    case BigQuery = 'bigquery';
    case Redshift = 'redshift';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Snowflake => 'Snowflake',
            self::BigQuery => 'BigQuery',
            self::Redshift => 'Amazon Redshift',
            self::None => 'Staged files only',
        };
    }

    public static function parse(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::None;
    }
}
