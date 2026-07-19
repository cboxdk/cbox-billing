/**
 * Cbox Billing — typed TypeScript client for the management + enforcement API.
 *
 * @packageDocumentation
 */

export { CboxBilling, type CboxBillingConfig } from './client.js';
export { AutoPager, type PageEnvelope, type FetchPage } from './pagination.js';
export type { ClientConfig, RequestOptions, RetryOptions, HttpMethod } from './http.js';
export type { WriteOptions } from './resources.js';

export {
  CboxBillingError,
  AuthenticationError,
  PermissionError,
  NotFoundError,
  ConflictError,
  ValidationError,
  RateLimitError,
  ConnectionError,
  type ApiErrorBody,
  type ErrorCode,
} from './errors.js';

export type * from './types.js';
