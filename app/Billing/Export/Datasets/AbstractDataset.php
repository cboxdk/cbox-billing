<?php

declare(strict_types=1);

namespace App\Billing\Export\Datasets;

use App\Billing\Export\Contracts\ExportDataset;
use App\Billing\Export\Enums\CursorKind;
use App\Billing\Export\ValueObjects\ExportCursor;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Export\ValueObjects\ExportRow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The shared streaming machinery every table-backed dataset builds on. It applies the three
 * scopings uniformly — the plane partition (`livemode`), the optional inclusive business-date
 * range, and the incremental watermark — then streams the result with a CHUNKED cursor so an
 * export never holds more than one chunk in memory regardless of table size.
 *
 * Rows are read through the query builder (not hydrated models) for a lean, plane-explicit
 * projection: the plane filter is applied HERE, deliberately, rather than trusting an Eloquent
 * global scope — an export must be able to name the plane it emits (test data never leaks into
 * a live export). Each concrete dataset supplies only its table, schema, cursor, date column,
 * plane scoping, and a per-row projector.
 */
abstract class AbstractDataset implements ExportDataset
{
    /** The physical table this dataset streams from. */
    abstract protected function table(): string;

    /**
     * Project one raw database record (as an associative array of driver scalars) into the
     * schema-ordered, typed output row.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, scalar|null>
     */
    abstract protected function projectRow(array $record): array;

    public function dateColumn(): ?string
    {
        return null;
    }

    public function mergeKeys(): array
    {
        return ['id'];
    }

    public function cursor(): ExportCursor
    {
        return ExportCursor::id();
    }

    public function rows(ExportQuery $query): iterable
    {
        $cursor = $this->cursor();
        $builder = $this->baseQuery($query);

        foreach ($this->stream($builder, $cursor) as $record) {
            $row = $this->fields($record);

            yield new ExportRow(
                $this->projectRow($row),
                $cursor->normalize($row[$cursor->column] ?? null),
            );
        }
    }

    /**
     * The record's columns as a string-keyed map — the projector's input.
     *
     * @return array<string, mixed>
     */
    private function fields(object $record): array
    {
        $fields = [];

        foreach (get_object_vars($record) as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }

    /** Build the fully-scoped (plane + range + watermark) query, ready to stream. */
    protected function baseQuery(ExportQuery $query): Builder
    {
        $builder = DB::table($this->table());

        $this->scopePlane($builder, $query->livemode);

        if ($query->hasRange()) {
            $this->applyRange($builder, $query);
        }

        if ($query->afterCursor !== null) {
            $builder->where($this->table().'.'.$this->cursor()->column, '>', $query->afterCursor);
        }

        $this->constrain($builder);

        return $builder;
    }

    /**
     * A dataset-specific standing filter applied to every export (e.g. the payments view
     * restricting the invoices table to settled rows). The default adds nothing.
     */
    protected function constrain(Builder $builder): void {}

    /**
     * Apply the inclusive business-date range against {@see dateColumn()}. Datasets whose date
     * axis is not a plain datetime column (the usage log's millisecond epoch) override this.
     */
    protected function applyRange(Builder $builder, ExportQuery $query): void
    {
        $date = $this->dateColumn();

        if ($date === null) {
            return;
        }

        if ($query->from !== null) {
            $builder->where($this->table().'.'.$date, '>=', $query->from);
        }
        if ($query->to !== null) {
            $builder->where($this->table().'.'.$date, '<=', $query->to);
        }
    }

    /**
     * Constrain the query to a single billing plane. The default is the direct `livemode`
     * column; datasets on child/unpartitioned tables override this to filter by the plane of
     * their parent (a `whereIn` sub-select), so test rows never appear in a live export.
     */
    protected function scopePlane(Builder $builder, bool $livemode): void
    {
        $builder->where($this->table().'.livemode', $livemode);
    }

    /**
     * Stream the query in bounded chunks. An id cursor uses keyset pagination
     * (`lazyById`); a timestamp cursor falls back to ordered offset chunking (`lazy`). Both
     * issue one query per chunk and hold only a chunk in memory.
     *
     * @return LazyCollection<int, \stdClass>
     */
    protected function stream(Builder $builder, ExportCursor $cursor): LazyCollection
    {
        $column = $this->table().'.'.$cursor->column;

        if ($cursor->kind === CursorKind::Id) {
            return $builder->lazyById($this->chunkSize(), $column, $cursor->column);
        }

        return $builder->orderBy($column)->lazy($this->chunkSize());
    }

    protected function chunkSize(): int
    {
        $configured = config('billing.export.chunk_size');

        return is_numeric($configured) ? max(1, (int) $configured) : 500;
    }

    /**
     * Sub-select of the ids in the current plane for a partitioned parent table — the seam
     * child datasets scope through.
     */
    protected function planeIds(string $parentTable, bool $livemode, string $idColumn = 'id'): \Closure
    {
        return static function (Builder $sub) use ($parentTable, $livemode, $idColumn): void {
            $sub->select($idColumn)->from($parentTable)->where('livemode', $livemode);
        };
    }
}
