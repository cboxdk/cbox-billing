/**
 * Typed errors thrown by the Cbox Billing client.
 *
 * Every non-2xx response is turned into a `CboxBillingError` (or a status-specific
 * subclass) carrying the HTTP status, a machine-usable `code`, the human message the API
 * returned, and — for validation failures — the per-field `details`. Catch the base class
 * to handle everything, or a subclass to branch on a specific failure.
 */

/** The canonical error body the API returns for auth/scope/not-found/conflict/business refusals. */
export interface ApiErrorBody {
  error?: string;
  /** Laravel validation failures use `{message, errors}`. */
  message?: string;
  errors?: Record<string, string[]>;
}

export type ErrorCode =
  | 'unauthorized'
  | 'forbidden'
  | 'not_found'
  | 'conflict'
  | 'unprocessable_entity'
  | 'rate_limited'
  | 'server_error'
  | 'network_error'
  | 'timeout'
  | 'api_error';

export class CboxBillingError extends Error {
  /** The HTTP status code (0 for a transport-level failure that never got a response). */
  readonly status: number;
  /** A stable, machine-usable classification of the failure. */
  readonly code: ErrorCode;
  /** Per-field validation messages, when the API returned them. */
  readonly details?: Record<string, string[]>;
  /** The `Retry-After` value (seconds), when the API sent one (429/503). */
  readonly retryAfter?: number;
  /** The request id echoed by the API, when present. */
  readonly requestId?: string;
  /** The raw parsed response body, for anything the typed fields don't cover. */
  readonly body?: unknown;

  constructor(
    message: string,
    opts: {
      status: number;
      code: ErrorCode;
      details?: Record<string, string[]>;
      retryAfter?: number;
      requestId?: string;
      body?: unknown;
    },
  ) {
    super(message);
    this.name = new.target.name;
    this.status = opts.status;
    this.code = opts.code;
    if (opts.details !== undefined) this.details = opts.details;
    if (opts.retryAfter !== undefined) this.retryAfter = opts.retryAfter;
    if (opts.requestId !== undefined) this.requestId = opts.requestId;
    this.body = opts.body;
    // Restore the prototype chain for `instanceof` under transpiled targets.
    Object.setPrototypeOf(this, new.target.prototype);
  }

  /** Build the right error subclass for a response status + parsed body. */
  static fromResponse(
    status: number,
    body: ApiErrorBody | undefined,
    ctx: { retryAfter?: number; requestId?: string } = {},
  ): CboxBillingError {
    const message =
      body?.error ??
      body?.message ??
      `Cbox Billing API request failed with status ${status}.`;

    const base = {
      status,
      body,
      ...(ctx.requestId !== undefined ? { requestId: ctx.requestId } : {}),
      ...(ctx.retryAfter !== undefined ? { retryAfter: ctx.retryAfter } : {}),
    };

    switch (status) {
      case 401:
        return new AuthenticationError(message, { ...base, code: 'unauthorized' });
      case 403:
        return new PermissionError(message, { ...base, code: 'forbidden' });
      case 404:
        return new NotFoundError(message, { ...base, code: 'not_found' });
      case 409:
        return new ConflictError(message, { ...base, code: 'conflict' });
      case 422:
        return new ValidationError(message, {
          ...base,
          code: 'unprocessable_entity',
          ...(body?.errors ? { details: body.errors } : {}),
        });
      case 429:
        return new RateLimitError(message, { ...base, code: 'rate_limited' });
      default:
        return new CboxBillingError(message, {
          ...base,
          code: status >= 500 ? 'server_error' : 'api_error',
        });
    }
  }
}

/** 401 — no or invalid bearer token. */
export class AuthenticationError extends CboxBillingError {}
/** 403 — the token may not act for the requested org (or lacks operator rights). */
export class PermissionError extends CboxBillingError {}
/** 404 — the resource does not exist (or is not visible to this token). */
export class NotFoundError extends CboxBillingError {}
/** 409 — an idempotency-key conflict, or a refused invariant (e.g. seat below assigned). */
export class ConflictError extends CboxBillingError {}
/** 422 — field validation failed (`details`) or a business rule refused the request. */
export class ValidationError extends CboxBillingError {}
/** 429 — the per-token rate limit was exceeded. `retryAfter` holds the back-off seconds. */
export class RateLimitError extends CboxBillingError {}
/** A transport-level failure (network down, DNS, or a client-side timeout). */
export class ConnectionError extends CboxBillingError {}
