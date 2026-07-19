/**
 * The transport layer: a single `request()` that adds bearer auth, generates an
 * idempotency key for writes, retries 429/5xx with exponential backoff honouring
 * `Retry-After`, enforces a timeout, and turns every non-2xx into a typed error.
 */

import {
  ApiErrorBody,
  CboxBillingError,
  ConnectionError,
} from './errors.js';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH';

export interface RetryOptions {
  /** Maximum retry attempts for a retryable failure (429/5xx/network). Default 3. */
  maxRetries: number;
  /** Base backoff in ms; the nth retry waits ~base * 2^n plus jitter. Default 500. */
  baseDelayMs: number;
  /** Ceiling for any single backoff, in ms. Default 30000. */
  maxDelayMs: number;
}

export interface ClientConfig {
  /** The API base URL, including the `/api/v1` prefix. */
  baseUrl: string;
  /** The bearer token (`cbl_...` live / `cbt_...` test). */
  token: string;
  /** Per-request timeout in ms. Default 30000. */
  timeoutMs?: number;
  retry?: Partial<RetryOptions>;
  /** Extra headers sent on every request. */
  defaultHeaders?: Record<string, string>;
  /** Injectable fetch (for tests / non-global-fetch runtimes). Defaults to global `fetch`. */
  fetch?: typeof fetch;
  /** Injectable idempotency-key generator. Defaults to a UUID. */
  idempotencyKeyFactory?: () => string;
}

export interface RequestOptions {
  method: HttpMethod;
  path: string;
  query?: Record<string, string | number | boolean | undefined>;
  body?: unknown;
  /**
   * Idempotency control for writes:
   *  - `true` (default for POST/PUT/DELETE): auto-generate a key.
   *  - a string: use exactly this key (so a caller can make its own retry idempotent).
   *  - `false`: send no key.
   */
  idempotency?: boolean | string;
  /** Per-call timeout override, in ms. */
  timeoutMs?: number;
  signal?: AbortSignal;
}

const RETRYABLE_STATUS = new Set([429, 500, 502, 503, 504]);

export class HttpClient {
  private readonly cfg: Required<Omit<ClientConfig, 'retry' | 'defaultHeaders' | 'timeoutMs'>> & {
    timeoutMs: number;
    retry: RetryOptions;
    defaultHeaders: Record<string, string>;
  };

  constructor(config: ClientConfig) {
    if (!config.baseUrl) throw new Error('Cbox Billing: `baseUrl` is required.');
    if (!config.token) throw new Error('Cbox Billing: `token` is required.');

    const fetchImpl = config.fetch ?? globalThis.fetch;
    if (typeof fetchImpl !== 'function') {
      throw new Error('Cbox Billing: no `fetch` available — pass one via `config.fetch`.');
    }

    this.cfg = {
      baseUrl: config.baseUrl.replace(/\/+$/, ''),
      token: config.token,
      timeoutMs: config.timeoutMs ?? 30_000,
      fetch: fetchImpl,
      idempotencyKeyFactory: config.idempotencyKeyFactory ?? defaultIdempotencyKey,
      defaultHeaders: config.defaultHeaders ?? {},
      retry: {
        maxRetries: config.retry?.maxRetries ?? 3,
        baseDelayMs: config.retry?.baseDelayMs ?? 500,
        maxDelayMs: config.retry?.maxDelayMs ?? 30_000,
      },
    };
  }

  async request<T>(opts: RequestOptions): Promise<T> {
    const url = this.buildUrl(opts.path, opts.query);
    const headers = this.buildHeaders(opts);
    const isWrite = opts.method !== 'GET';

    let lastError: CboxBillingError | undefined;

    for (let attempt = 0; attempt <= this.cfg.retry.maxRetries; attempt++) {
      const controller = new AbortController();
      const timeout = setTimeout(
        () => controller.abort(),
        opts.timeoutMs ?? this.cfg.timeoutMs,
      );
      const signal = mergeSignals(controller.signal, opts.signal);

      let response: Response;
      try {
        const init: RequestInit = { method: opts.method, headers, signal };
        if (isWrite && opts.body !== undefined) {
          init.body = JSON.stringify(opts.body);
        }
        response = await this.cfg.fetch(url, init);
      } catch (err) {
        clearTimeout(timeout);
        const aborted = err instanceof Error && err.name === 'AbortError';
        lastError = new ConnectionError(
          aborted ? 'Request timed out.' : `Network request failed: ${describe(err)}`,
          { status: 0, code: aborted ? 'timeout' : 'network_error' },
        );
        if (attempt < this.cfg.retry.maxRetries) {
          await sleep(this.backoff(attempt));
          continue;
        }
        throw lastError;
      }
      clearTimeout(timeout);

      if (response.ok) {
        return (await parseBody(response)) as T;
      }

      const retryAfter = parseRetryAfter(response);
      const parsed = (await parseBody(response)) as ApiErrorBody | undefined;
      lastError = CboxBillingError.fromResponse(response.status, parsed, {
        ...(retryAfter !== undefined ? { retryAfter } : {}),
        ...(headerOf(response, 'x-request-id') ? { requestId: headerOf(response, 'x-request-id')! } : {}),
      });

      const retryable = RETRYABLE_STATUS.has(response.status);
      if (retryable && attempt < this.cfg.retry.maxRetries) {
        // Honour Retry-After when the server sent one, but never wait longer than the
        // configured ceiling (a hostile or mistaken Retry-After can't hang the caller);
        // the true value is still surfaced on the thrown error's `retryAfter`.
        const wait =
          retryAfter !== undefined
            ? Math.min(retryAfter * 1000, this.cfg.retry.maxDelayMs)
            : this.backoff(attempt);
        await sleep(wait);
        continue;
      }

      throw lastError;
    }

    // Unreachable in practice — the loop either returns or throws.
    throw lastError ?? new CboxBillingError('Request failed.', { status: 0, code: 'api_error' });
  }

  private buildUrl(path: string, query?: RequestOptions['query']): string {
    const url = new URL(this.cfg.baseUrl + path);
    if (query) {
      for (const [key, value] of Object.entries(query)) {
        if (value !== undefined) url.searchParams.set(key, String(value));
      }
    }
    return url.toString();
  }

  private buildHeaders(opts: RequestOptions): Record<string, string> {
    const headers: Record<string, string> = {
      Authorization: `Bearer ${this.cfg.token}`,
      Accept: 'application/json',
      ...this.cfg.defaultHeaders,
    };

    if (opts.method !== 'GET' && opts.body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    const wantsIdempotency = opts.idempotency ?? opts.method !== 'GET';
    if (wantsIdempotency) {
      headers['Idempotency-Key'] =
        typeof opts.idempotency === 'string'
          ? opts.idempotency
          : this.cfg.idempotencyKeyFactory();
    }

    return headers;
  }

  private backoff(attempt: number): number {
    const exp = this.cfg.retry.baseDelayMs * 2 ** attempt;
    const capped = Math.min(exp, this.cfg.retry.maxDelayMs);
    // Full jitter — spread retries so a fleet doesn't stampede on recovery.
    return Math.random() * capped;
  }
}

function defaultIdempotencyKey(): string {
  const c = globalThis.crypto;
  if (c && typeof c.randomUUID === 'function') return c.randomUUID();
  // Fallback for runtimes without WebCrypto.
  return 'idk_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 14);
}

async function parseBody(response: Response): Promise<unknown> {
  if (response.status === 204) return undefined;
  const text = await response.text();
  if (text === '') return undefined;
  const type = response.headers.get('content-type') ?? '';
  if (type.includes('application/json')) {
    try {
      return JSON.parse(text);
    } catch {
      return text;
    }
  }
  return text;
}

function parseRetryAfter(response: Response): number | undefined {
  const raw = response.headers.get('retry-after');
  if (!raw) return undefined;
  const seconds = Number(raw);
  if (Number.isFinite(seconds)) return Math.max(0, seconds);
  const date = Date.parse(raw);
  if (Number.isFinite(date)) return Math.max(0, Math.round((date - Date.now()) / 1000));
  return undefined;
}

function headerOf(response: Response, name: string): string | undefined {
  return response.headers.get(name) ?? undefined;
}

function mergeSignals(a: AbortSignal, b?: AbortSignal): AbortSignal {
  if (!b) return a;
  if (typeof (AbortSignal as unknown as { any?: unknown }).any === 'function') {
    return (AbortSignal as unknown as { any: (s: AbortSignal[]) => AbortSignal }).any([a, b]);
  }
  const controller = new AbortController();
  const onAbort = () => controller.abort();
  a.addEventListener('abort', onAbort);
  b.addEventListener('abort', onAbort);
  return controller.signal;
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function describe(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}
