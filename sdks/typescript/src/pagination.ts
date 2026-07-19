/**
 * Auto-paging over the cursor-paginated list endpoints.
 *
 * The management API's collection endpoints (`invoices`, `plans`, `payment-methods`) return a
 * `{ data, has_more, next_cursor }` page. `AutoPager` wraps that envelope in an async iterator
 * that follows `next_cursor` transparently, so calling code reads the whole collection without
 * ever handling a cursor by hand:
 *
 * ```ts
 * for await (const invoice of client.invoices.list('org_acme')) { ... }
 * ```
 *
 * It drives a `fetchPage(cursor)` seam: the first call passes `undefined` (first page); each
 * subsequent call threads the previous page's `next_cursor`, stopping when it comes back
 * null/absent.
 */

export interface PageEnvelope<T> {
  data: T[];
  has_more?: boolean;
  /** Opaque cursor for the next page; null/absent on the last page. */
  next_cursor?: string | null;
}

export type FetchPage<T> = (cursor: string | undefined) => Promise<PageEnvelope<T>>;

export class AutoPager<T> implements AsyncIterable<T> {
  constructor(private readonly fetchPage: FetchPage<T>) {}

  async *[Symbol.asyncIterator](): AsyncIterator<T> {
    let cursor: string | undefined = undefined;
    do {
      const page: PageEnvelope<T> = await this.fetchPage(cursor);
      for (const item of page.data) {
        yield item;
      }
      cursor = page.next_cursor ?? undefined;
    } while (cursor);
  }

  /** Collect every item across all pages into a single array. */
  async all(): Promise<T[]> {
    const out: T[] = [];
    for await (const item of this) out.push(item);
    return out;
  }

  /** The first page's items only (no auto-paging). */
  async data(): Promise<T[]> {
    const page = await this.fetchPage(undefined);
    return page.data;
  }
}
