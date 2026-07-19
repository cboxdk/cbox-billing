<?php

declare(strict_types=1);

namespace App\Billing\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cursor (keyset) pagination for the management-API list endpoints. The client passes an
 * opaque `?cursor=` (a black box it echoes back verbatim) and an optional `?limit=`; the
 * response carries `has_more` + the `next_cursor` to fetch the following page. Stable by
 * construction: pages are keyed off a strictly-monotonic column (the primary key by default),
 * so concurrent inserts never shift or duplicate rows across a page boundary the way an
 * `OFFSET` would.
 *
 * Two seams:
 *  - {@see self::fromQuery()} keysets a live Eloquent query (invoices, plans) — the SQL only
 *    ever loads one page + a lookahead row.
 *  - {@see self::fromList()} pages an already-materialised list (the gateway-owned payment
 *    methods, which arrive as value objects, not a query) with an opaque offset cursor.
 */
class CursorPaginator
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    /**
     * Keyset-paginate an Eloquent query by a strictly-monotonic column (the primary key by
     * default). That column MUST be the query's sole ordering key for the cursor to stay
     * stable, so any prior ordering is cleared and replaced.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  'asc'|'desc'  $direction
     * @return CursorPage<TModel>
     */
    public static function fromQuery(Builder $query, Request $request, string $column = 'id', string $direction = 'desc'): CursorPage
    {
        $limit = self::limit($request);
        $after = self::keysetCursor($request);

        $query->reorder()->orderBy($column, $direction);

        if ($after !== null) {
            $query->where($column, $direction === 'asc' ? '>' : '<', $after);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        /** @var Collection<int, TModel> $items */
        $items = $rows->take($limit)->values();
        $last = $items->last();

        $nextCursor = null;

        if ($hasMore && $last instanceof Model) {
            $key = $last->getAttribute($column);

            if (is_int($key) || is_string($key)) {
                $nextCursor = self::encode(['k' => $key]);
            }
        }

        return new CursorPage($items, $nextCursor, $hasMore);
    }

    /**
     * Page an already-materialised list with an opaque offset cursor. Used where the rows are
     * not a query — the gateway returns saved cards as value objects — so a keyset column has
     * no meaning and the cursor carries the slice offset instead.
     *
     * @template TItem
     *
     * @param  list<TItem>  $items
     * @return CursorPage<TItem>
     */
    public static function fromList(array $items, Request $request): CursorPage
    {
        $limit = self::limit($request);
        $offset = self::offsetCursor($request);

        $slice = array_slice($items, $offset, $limit);
        $hasMore = ($offset + $limit) < count($items);
        $nextCursor = $hasMore ? self::encode(['o' => $offset + $limit]) : null;

        /** @var Collection<int, TItem> $collection */
        $collection = new Collection($slice);

        return new CursorPage($collection, $nextCursor, $hasMore);
    }

    /** The requested page size, clamped to `[1, MAX_LIMIT]`; the default when unspecified. */
    public static function limit(Request $request): int
    {
        $raw = $request->query('limit');

        if (! is_numeric($raw)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, (int) $raw));
    }

    /** The keyset value carried by `?cursor=`; null when absent. A 400 when it is malformed. */
    private static function keysetCursor(Request $request): int|string|null
    {
        $payload = self::decodeCursor($request, 'k');

        if ($payload === null) {
            return null;
        }

        $value = $payload['k'] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }

    /** The slice offset carried by `?cursor=`; 0 when absent. A 400 when it is malformed. */
    private static function offsetCursor(Request $request): int
    {
        $payload = self::decodeCursor($request, 'o');

        if ($payload === null) {
            return 0;
        }

        $offset = $payload['o'] ?? null;

        return is_int($offset) && $offset >= 0 ? $offset : 0;
    }

    /**
     * Decode `?cursor=` and require it to carry `$expectedKey`. Returns null when no cursor was
     * sent; aborts 400 when a cursor was sent but is unreadable or shaped for a different
     * endpoint (a keyset cursor replayed against an offset endpoint, or vice-versa).
     *
     * @return array<string, mixed>|null
     */
    private static function decodeCursor(Request $request, string $expectedKey): ?array
    {
        $raw = $request->query('cursor');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $json = base64_decode(strtr($raw, '-_', '+/'), true);

        if ($json !== false) {
            $decoded = json_decode($json, true);

            if (is_array($decoded) && array_key_exists($expectedKey, $decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        abort(Response::HTTP_BAD_REQUEST, 'Invalid pagination cursor.');
    }

    /**
     * Encode a cursor payload as an opaque, URL-safe token (base64url of its JSON).
     *
     * @param  array<string, int|string>  $payload
     */
    private static function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }
}
