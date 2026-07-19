/**
 * Auto-paging over the list endpoints.
 *
 * The management API's collection endpoints (`invoices`, `plans`, `payment-methods`)
 * currently return a flat `{ data: [...] }` envelope with **no server-side cursor** — the
 * whole collection comes back in one page. `AutoPager` wraps that envelope in an async
 * iterator so calling code reads the same way it would against a cursored API:
 *
 * ```ts
 * for await (const invoice of client.invoices.list('org_acme')) { ... }
 * ```
 *
 * It is written against a `fetchPage(cursor)` seam, so the day the API grows real cursor
 * pagination the iterator keeps working unchanged — only the resource method's `fetchPage`
 * needs to thread the cursor through. Today `fetchPage` is called once.
 */

export interface PageEnvelope<T> {
  data: T[];
  /** Reserved for a future cursor; absent today. */
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
