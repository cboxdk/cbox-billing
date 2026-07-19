<?php

declare(strict_types=1);

namespace App\Billing\Export\ValueObjects;

/**
 * The operator-supplied load-side coordinates a manifest generator needs to phrase a runnable
 * statement: where the staged files live as the warehouse addresses them (an `s3://`/`gs://`
 * base or a Snowflake external-stage name), the target schema, and the credential reference
 * (a Redshift IAM role, a Snowflake storage integration). None of these are invented — a
 * field the operator has not configured is emitted as an explicit, bracketed placeholder the
 * manifest tells them to fill, never a fabricated value.
 */
readonly class WarehouseTarget
{
    public function __construct(
        public string $externalBase,
        public string $schema,
        public ?string $stage = null,
        public ?string $credential = null,
    ) {}

    /** The fully-qualified target table for a dataset (schema-qualified, warehouse-safe name). */
    public function table(string $dataset): string
    {
        return $this->schema.'.'.str_replace('-', '_', $dataset);
    }

    /** The external location of a staged directory, as the warehouse addresses it. */
    public function locationOf(string $directory): string
    {
        return rtrim($this->externalBase, '/').'/'.$directory;
    }
}
