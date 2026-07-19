---
title: Hosted checkout & portal
description: The token-authorized hosted checkout and customer-portal pages under /billing — how the opaque session token authorizes them, and their JSON action endpoints.
weight: 54
---

# Hosted checkout & portal

Cbox Billing ships hosted **checkout** and **customer-portal** pages so a merchant
can hand billing off entirely (ADR-0009 Path A). These pages live under `/billing`
and are **not** behind the provider `auth.cbox` gate — the opaque session token in
the URL is the whole authorization, and an invalid or expired token 404s.

## How a session is created

A merchant creates a session through the [management API](management.md):

- `POST /api/v1/checkout-sessions` → `{url}` of a hosted checkout page.
- `POST /api/v1/portal-sessions` → `{url}` of a hosted customer portal.

Each URL carries an opaque, non-guessable token. The token TTL is
`CBOX_BILLING_HOSTED_SESSION_TTL` (30 minutes); a pending token is stamped expired
after that. Sessions are stored in `billing_sessions`.

## The pages and their action endpoints

The pages render on the app's design-system tokens; their JSON action endpoints
create the gateway intent, poll the session status, and drive plan changes /
payment-method updates through the **same lifecycle services the management API
uses** — so the hosted surface and the API can never diverge.

### Checkout (`routes/hosted.php`)

| Method | Path |
| --- | --- |
| `GET` | `/billing/checkout/{token}` |
| `POST` | `/billing/checkout/{token}/intent` |
| `GET` | `/billing/checkout/{token}/status` |

The subscription for a checkout is created **strictly on the gateway's settled
webhook** — a `CheckoutActivation` decorator on the invoice-payment applier activates
it — so a checkout never creates a paying subscription before money settles.

### Portal (`routes/hosted.php`)

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/billing/portal/{token}` | The portal page. |
| `GET` | `/billing/portal/{token}/invoices/{invoice}/pdf` | Download an invoice PDF. |
| `POST` | `/billing/portal/{token}/preview` | Preview a plan change. |
| `POST` | `/billing/portal/{token}/change` | Apply a plan change. |
| `POST` | `/billing/portal/{token}/cancel` | Cancel. |
| `POST` | `/billing/portal/{token}/setup-intent` | Start a payment-method setup. |
| `POST` | `/billing/portal/{token}/payment-method` | Update the payment method. |
| `POST` | `/billing/portal/{token}/payment-method/default` | Make a saved method the default. |
| `POST` | `/billing/portal/{token}/payment-method/remove` | Detach a saved method. |
| `POST` | `/billing/portal/{token}/seats/preview` | Preview a seat buy/release (prorated due-now). |
| `POST` | `/billing/portal/{token}/seats` | Buy/release purchased seats. |
| `POST` | `/billing/portal/{token}/seats/assign` | Assign a purchased seat to a member. |
| `POST` | `/billing/portal/{token}/seats/unassign` | Release a member's seat (→ Light). |
| `POST` | `/billing/portal/{token}/notifications` | Toggle an optional email notification. |

Every portal endpoint is scoped to the session token's `organization_id`: a token for
one org can never read or mutate another org's usage, seats, invoices, or preferences —
an off-org resource resolves to a 404/deny, never a leak.

## Self-service depth

The portal is built so a customer never needs to email support. Beyond plan change and
cancellation, one page covers:

- **Usage & consumption** — per-meter allowance / used / remaining / % consumed (progress
  bars), projected overage, and the reset date for the current period. It reads through the
  **same `EntitlementsView` + `UsageSummaryView`** the console usage screen and the
  enforcement path use (`UsageReport::forOrganization()`), so a customer sees exactly what
  the server enforces. Hidden entirely for a flat / un-metered plan.
- **Self-serve seats** — buy or release purchased Full seats (preview the prorated due-now,
  then confirm; the H6 proration is charged through the engine's `changeQuantity` and the
  same collector the console + API use), and assign / unassign the org's own members between
  Full and Light. Cap-enforced: assigning past the purchased count returns "buy more seats",
  and releasing below the assigned count is refused — mirroring `SeatManager` exactly.
- **Billing history** — a chronological timeline broader than the invoices table: invoices
  (issued / paid / void), the receipt each paid invoice produced, credit notes (refunds /
  adjustments), and coupon redemptions, each with its amount, status, and a PDF link where
  a document exists.
- **Notification preferences** — opt in/out of the **optional** lifecycle emails (renewal
  reminder, trial-ending, receipts). The **mandatory** transactional/legal mails (invoice
  issued, past-due / dunning, subscription changes, license delivery, plan retirement) are
  shown as always-on and cannot be switched off. See
  [Notifications → Preferences](../notifications/preferences.md).

## Embedded intents (Path B)

If a product would rather embed the gateway's element in its own UI than redirect to
a hosted page, it uses the **embedded-intent** management endpoints
(`/setup-intents`, `/payment-intents`, `/payment-methods/*`) and confirms client-side
against the returned client secret. Both paths are first-class; pick per integration.
See [Management API](management.md).

## Upgrade deep-links

An enforcement denial's `checkout_url` (the enforce→upgrade bridge) is exactly one of
these hosted checkout URLs — pre-built for the required plan, reusing an open session
so repeated denials do not spawn rows, and returning to
`CBOX_BILLING_UPGRADE_RETURN_URL` after settlement. See
[Metering & enforcement](../concepts/metering-and-enforcement.md).

## Related documentation

- [Management API](management.md)
- [Configuration → Payment gateways](../configuration/payment-gateways.md)
- [Concepts → Payments & dunning](../concepts/payments-and-dunning.md)
