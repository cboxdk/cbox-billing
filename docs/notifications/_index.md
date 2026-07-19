---
title: Notifications
description: The brandable, localized transactional-email system — editable per-event templates with a safe rendering sandbox, per-seller branding, EN/DA localization, and a console editor with live preview and test-send.
weight: 55
---

# Notifications

Every lifecycle email Cbox Billing sends — invoice issued, payment receipt, dunning,
payment retry, trial ending, renewal reminder, subscription changed, license delivered,
plan retiring — is rendered by one brandable, localized template system rather than a
hard-coded Blade view.

- **Editable templates** per event type, locale, and selling entity, overriding a shipped
  default in code. Resolution never dead-ends.
- **A safe rendering sandbox**: stored templates are rendered by a restricted mustache
  renderer, never evaluated as Blade or PHP, with every interpolated value HTML-escaped.
- **Per-seller branding**: the selling entity of record carries the brand (logo, accent
  colour, from-identity, footer) that wraps every email.
- **Localization** (EN + DA shipped), resolved customer → seller default → app fallback.
- **A console surface** to list, edit (with a live server-rendered preview of the actual
  branded email), reset, and test-send.

See [Transactional email](./transactional-email.md) for the full model.
