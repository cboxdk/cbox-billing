---
title: Delivery, retries & idempotency
description: How outbound webhooks are queued, retried with exponential backoff, dead-lettered, swept, and how to dedupe deliveries on your side.
weight: 30
---

# Delivery, retries & idempotency

## Queued, never on the hot path

Emitting an event never blocks the request that caused it. When a billing event fires, the app writes a delivery row per subscribed endpoint and enqueues a job; the enforcement and checkout hot paths do not wait on a receiver. Deliveries run on a dedicated queue (`CBOX_WEBHOOKS_QUEUE`, default `webhooks`) so you can isolate webhook I/O from the billing workers.

## Retries & dead-lettering

Each attempt POSTs with short connect/read timeouts and **no redirect following**. A non-`2xx`, a timeout, or a connection error schedules a retry with exponential backoff — `2^attempt` minutes, capped at `CBOX_WEBHOOKS_RETRY_CEILING_MINUTES` (default 360). After `CBOX_WEBHOOKS_MAX_ATTEMPTS` (default 8) the delivery is **dead-lettered** (`status = dead`) so a gone endpoint stops consuming retry cycles.

Due retries are swept by the scheduler every minute (`webhooks:retry-pending`), so a transient receiver outage recovers on its own. You can also sweep by hand:

```
php artisan webhooks:retry-pending --limit=100
```

## Manual redelivery

From an endpoint's **Delivery log** you can redeliver any `failed` or `dead` delivery. This re-queues the same delivery (its `delivery_id` is unchanged) and re-attempts immediately.

## Idempotency — dedupe on your side

Two ids let you make your handler safe against duplicates:

- **`id`** (the event id) is stable per business event. A re-emitted domain event collapses onto the same delivery row and is never delivered twice, but you should still dedupe your side-effects on `id` to be safe against at-least-once delivery.
- **`delivery_id`** is stable across retries of one delivery.

Return a `2xx` **only after** you have durably recorded the event. If your handler is slow, ack fast and process asynchronously — the app treats a slow response as a failure and will retry.

## Respond quickly

The per-attempt read timeout is short (`CBOX_WEBHOOKS_TIMEOUT`, default 10s). A receiver that holds the connection open is treated as a failed attempt.
