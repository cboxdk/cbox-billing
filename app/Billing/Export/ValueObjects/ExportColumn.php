<?php

declare(strict_types=1);

namespace App\Billing\Export\ValueObjects;

use App\Billing\Export\Enums\ColumnType;

/**
 * One typed column in a dataset's stable, documented schema. The ordered list of these is the
 * contract a CSV header, an NDJSON object's keys, and a warehouse table's DDL are all derived
 * from — so the schema lives in exactly one place and never drifts between the three.
 */
readonly class ExportColumn
{
    public function __construct(
        public string $name,
        public ColumnType $type,
        public string $description,
    ) {}

    public static function string(string $name, string $description): self
    {
        return new self($name, ColumnType::String, $description);
    }

    public static function integer(string $name, string $description): self
    {
        return new self($name, ColumnType::Integer, $description);
    }

    public static function boolean(string $name, string $description): self
    {
        return new self($name, ColumnType::Boolean, $description);
    }

    public static function timestamp(string $name, string $description): self
    {
        return new self($name, ColumnType::Timestamp, $description);
    }

    public static function json(string $name, string $description): self
    {
        return new self($name, ColumnType::Json, $description);
    }
}
