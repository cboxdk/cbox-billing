---
title: Notification preferences
description: The per-organization opt-out for the optional lifecycle emails — how the mandatory/legal mails stay always-on, and the seam the notifier consults before an optional send.
weight: 56
---

# Notification preferences

Not every lifecycle email is the customer's to switch off. Cbox Billing splits the
transactional mails into two classes and lets a customer manage exactly the ones that are
courtesies — from the [customer portal](../api/hosted-checkout-and-portal.md), no support
ticket required.

## Optional vs mandatory

The split is a single source of truth on `MailEventType::isOptional()`, so the notifier's
gate and the portal's toggle list can never drift:

| Class | Events | Customer can disable? |
| --- | --- | --- |
| **Optional** | Renewal reminder · Trial ending · Payment receipt | Yes |
| **Mandatory** | Invoice issued · Past-due / dunning · Payment retry · Subscription changed · License delivered · Plan retiring | No — always sent |

Mandatory mails are transactional or legal (a customer must always receive an invoice, a
dunning notice, or a cancellation confirmation), so they ignore preferences entirely.

## Where preferences live

Preferences are stored per organization in `notification_preferences` — one row per
`(organization_id, event_type)`. **Absence of a row means opted-in**: a customer who never
touches the toggles still receives every courtesy mail, and a row is written only when they
change a default. Mandatory events are never written to this table.

## The notifier seam

`BillingNotifier` consults `ManagesNotificationPreferences::allows()` in its private `send()`
seam **before an optional mail leaves**. An optional mail to an opted-out org is suppressed
and logged (never delivered); a mandatory mail passes no gate and always sends. In test mode
the same optional gate applies before a mail is captured, so a suppressed optional mail is
neither delivered nor captured.

```
optional mail  → allows(org, event)? → yes → deliver / capture
                                     → no  → suppress + log
mandatory mail → (no gate)          →         deliver / capture
```

## The portal surface

The portal renders the optional events as toggles (with their current opt-in state) and
lists the mandatory events as "Always sent". Flipping a toggle posts to
`POST /billing/portal/{token}/notifications` with `{event, opted_in}`, scoped to the session
org; a request to toggle a mandatory event is refused (422).

## Related documentation

- [Transactional email](transactional-email.md)
- [Hosted checkout & portal](../api/hosted-checkout-and-portal.md)
