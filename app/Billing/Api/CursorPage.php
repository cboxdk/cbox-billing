<?php

declare(strict_types=1);

namespace App\Billing\Api;

use Illuminate\Support\Collection;

/**
 * One page of a cursor-paginated list: the items on this page plus the opaque cursor that
 * fetches the next page (`null` when this is the last page). Built by {@see CursorPaginator}
 * and rendered into the `{data, has_more, next_cursor}` envelope the OpenAPI `Page` schema
 * and the TypeScript SDK's `AutoPager` consume.
 *
 * @template T
 */
class CursorPage
{
    /** @param Collection<int, T> $items */
    public function __construct(
        public Collection $items,
        public ?string $nextCursor,
        public bool $hasMore,
    ) {}

    /**
     * Render the standard list envelope. Pass a presenter that projects one item into its
     * public wire shape; extra top-level keys (e.g. the catalog's resolved `currency`) can be
     * merged by the caller with `+`.
     *
     * @param  callable(T): array<string, mixed>  $present
     * @return array{data: list<array<string, mixed>>, has_more: bool, next_cursor: string|null}
     */
    public function envelope(callable $present): array
    {
        $data = [];

        foreach ($this->items as $item) {
            $data[] = $present($item);
        }

        return [
            'data' => $data,
            'has_more' => $this->hasMore,
            'next_cursor' => $this->nextCursor,
        ];
    }
}
