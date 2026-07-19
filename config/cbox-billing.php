<?php

declare(strict_types=1);

return [
    /*
     * Outbound webhook / event-bus delivery. The app fans its billing domain events out to
     * integrator-registered endpoints as signed HTTP POSTs.
     */
    'webhooks' => [
        /*
         * SSRF enforcement for outbound endpoint URLs. Keep TRUE in any multi-tenant / hosted
         * deployment — it refuses endpoints that resolve to private/reserved/loopback/link-local
         * or cloud-metadata addresses at registration AND pins the resolved IP immediately before
         * each delivery (TOCTOU-closed, no redirects). A single-tenant on-prem operator who must
         * deliver to an internal host can set CBOX_WEBHOOKS_VERIFY_URL=false (also disabled in the
         * delivery unit tests so they can target a fake local URL).
         */
        'verify_url' => env('CBOX_WEBHOOKS_VERIFY_URL', true),

        /*
         * Retry budget. A failed delivery backs off exponentially (2^attempt minutes, capped at
         * the ceiling) up to `max_attempts`, then dead-letters so a gone endpoint stops consuming
         * retry cycles forever.
         */
        'max_attempts' => (int) env('CBOX_WEBHOOKS_MAX_ATTEMPTS', 8),
        'retry_ceiling_minutes' => (int) env('CBOX_WEBHOOKS_RETRY_CEILING_MINUTES', 360),

        /*
         * Per-attempt HTTP timeouts (seconds). Short by design: an outbound delivery must never
         * hold a worker for long, and a slow receiver is treated as a failure to retry.
         */
        'connect_timeout' => (int) env('CBOX_WEBHOOKS_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('CBOX_WEBHOOKS_TIMEOUT', 10),

        /*
         * Replay-tolerance window (seconds) a receiver should accept when verifying the
         * `X-Cbox-Timestamp`. Documented for integrators; the signer stamps `time()`.
         */
        'tolerance_seconds' => (int) env('CBOX_WEBHOOKS_TOLERANCE_SECONDS', 300),

        /*
         * Drive the retry sweep from the scheduler. Opt out to call `webhooks:retry-pending`
         * yourself.
         */
        'schedule_retries' => env('CBOX_WEBHOOKS_SCHEDULE_RETRIES', true),

        /*
         * The queue the delivery jobs are pushed onto, so an operator can isolate webhook I/O
         * from the billing lifecycle workers.
         */
        'queue' => env('CBOX_WEBHOOKS_QUEUE', 'webhooks'),
    ],
];
