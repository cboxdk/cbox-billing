---
title: Test mode
description: The livemode plane partition — what is isolated, how cross-mode access is denied, the fake test gateway, captured (never delivered) notifications, and how to get a test API token.
weight: 10
---

# Test mode

Test mode is an **isolated data plane**. Every tenant-state row carries a `livemode` boolean;
a test credential reads and writes only `livemode=false` rows, a live credential only
`livemode=true` rows. Isolation is enforced deny-by-default by a global Eloquent scope, so a
live token can never see (or, via find/update, touch) a test row — a cross-mode lookup simply
returns nothing, and the request 404s.

## What is isolated

The plane partition covers the tenant-state objects an integrator creates:

- organizations, subscriptions, invoices, credit notes
- coupons and redemptions, seat assignments, wallet adjustments
- payment retries, issued licenses, webhook endpoints and deliveries

The **catalog is shared, not partitioned** — products, plans, prices and meters are the
seller's catalog and exist once, referenced from both planes. A test subscription is created
against a real plan. This is a deliberate boundary: you experiment with subscriptions,
invoices and dunning against your real catalog, not a copy of it.

> **Organization ids.** An organization's primary key is its Cbox ID handle, so a test org and
> a live org cannot share the same id (the key is unique across both planes). Provision your
> sandbox orgs under distinct handles (e.g. `org_test_acme`).

## How cross-mode access is denied

A single ambient **billing context** holds the request's plane, resolved from the credential:

- an API request resolves its plane from the token's `mode` (`live` or `test`);
- the console resolves it from the operator's test-mode toggle (a session flag).

The context defaults to **live**, so anything that never enters test mode — the scheduled
billing passes, every existing request — is unchanged. A global `LivemodeScope` on each
partitioned model reads the context and constrains every query to the current plane. Rows are
stamped with their plane on create from the same context; `livemode` is never mass-assignable,
so a request cannot forge the plane it writes into.

## The fake test gateway

In test mode, payments route through a **fake gateway** and can never reach a real
Stripe/live account — the routing decision is made once, centrally, from the billing context.
A test charge settles or declines **deterministically**, controlled by the bound test clock's
`charge_outcome` (`succeed` by default, `decline` to exercise dunning). No card is vaulted and
no network call is made.

## No real emails

Test-mode notifications are **captured, never delivered**. Every billing lifecycle email
(invoice issued, receipt, payment-failed, trial-ending, …) is logged and recorded in test mode
instead of being queued to the mailer, so an integrator can drive a year of renewals and
dunning without a single message reaching a real inbox.

## No real outbound webhooks

Outbound webhook endpoints are plane-partitioned too: a test-mode event only matches a
test-mode endpoint, so a sandbox run never posts to a production receiver.

## Getting a test API token

Mint one from the console (**Settings → API tokens → New token → Mode: Test**) or extend the
token model's `issue()` with `BillingMode::Test`. A test token's plaintext carries a `cbt_`
prefix (live tokens carry `cbl_`) so the plane is obvious at a glance and the two can never be
confused. Send it as the `Authorization: Bearer` credential exactly like a live token — the
plane is resolved from the token, not the endpoint.
