---
title: Transactional email
description: How the transactional-email system resolves, brands, localizes, and safely renders every lifecycle email — the template model, the resolution chain, the sandbox, per-seller branding, i18n, and the console editor.
weight: 10
---

# Transactional email

The transactional-email system turns the nine hard-coded lifecycle mailables into one
editable, branded, localized pipeline. Lifecycle services still depend on the
`NotifiesCustomers` contract and fire the same triggers; what changed is that each email is
now resolved from a template, wrapped in the sending entity's brand, and localized.

## Event types

The catalog lives in `App\Billing\Notifications\MailEventType` — one case per email, each
carrying its label, description, available variables (the editor's reference), and a sample
variable bag (for the live preview):

| Event | Trigger |
| --- | --- |
| `invoice_issued` | An invoice is finalized (also reused on manual re-send). |
| `payment_receipt` | A settled-payment webhook marks an invoice paid. |
| `payment_failed` | A dunning step for a past-due account. |
| `payment_retry` | A failed renewal charge on the smart-retry schedule. |
| `trial_ending` | Ahead of a trial converting to paid. |
| `renewal_reminder` | Ahead of a term renewal. |
| `subscription_changed` | A plan change / scheduled cancellation / cancellation. |
| `license_delivered` | An on-prem license is issued or reissued. |
| `plan_retiring` | A subscriber's plan is retiring (ADR-0016). |

## The template model & resolution chain

A shipped default for every event exists in code for each locale
(`resources/mail-templates/{locale}.php`). An operator override is a `mail_templates` row keyed
by `(event_type, locale, seller_entity_id)`; `seller_entity_id` is nullable — a null-seller row
is the account-wide override.

`MailTemplateResolver` (contract `ResolvesMailTemplates`) resolves the effective template in
strictly descending specificity, so a render **never dead-ends**:

1. DB override — `(seller, requested locale)`
2. DB override — `(seller, fallback locale)`
3. DB override — `(account-wide, requested locale)`
4. DB override — `(account-wide, fallback locale)`
5. Shipped default — requested locale
6. Shipped default — fallback locale

The layer that served is reported on the resolved template, so the console can show
"default" vs "overridden".

## The rendering sandbox

Stored templates are **data, not code**. They are rendered by
`SafeTemplateRenderer` (contract `RendersTemplates`) — a restricted mustache/handlebars-style
renderer that is the whole grammar:

```
{{ path }}                    escaped interpolation (dotted paths + `this` in a loop)
{{#if path}}…{{else}}…{{/if}} render when truthy / the alternate branch
{{#unless path}}…{{/unless}}  render when falsy
{{#each path}}…{{/each}}      iterate a list; body scope is the item
```

It never evaluates Blade or PHP, calls no user-named function, and HTML-escapes every
interpolated value by default (there is deliberately no raw-output form). A hostile template
body, or a hostile **value** inside a variable (a customer-controlled org name of `<script>…`,
say), therefore cannot inject markup or execute code. Using `Blade::render()` over a stored
template would be a stored-RCE; this renderer is the safe path.

The subject line and the auto-derived plain-text alternative use the same renderer in a
non-escaping mode — still pure substitution, never execution.

## Per-seller branding

Branding rides on the selling entity of record (`seller_entities`, additive columns): accent
colour, logo URL, from-name / from-email / reply-to, footer legal address, support links, and
the entity's default email locale. `BrandingResolver` fills any unset field from the
app-level defaults (`config('billing.mail.branding')`), so an entity that never authored
branding still renders correctly.

`TransactionalMailComposer` (contract `ComposesTransactionalMail`) wraps the rendered body in
`resources/views/emails/layout.blade.php` — a table-based, inline-styled, dark-safe,
600px-max email layout with the seller's header, accent, and legal footer. The layout is
trusted application code; the body it embeds is already the sandboxed renderer's escaped
output.

## Localization

Locale resolves customer (org `locale`) → selling entity default locale → app fallback
(`LocaleResolver`, against `config('billing.mail.locales')`). EN and DA ship for every event
default and for the shared layout chrome (`lang/{en,da}/emails.php`). Amounts are formatted
per locale via `MoneyFormatter::forLocale()` and dates via Carbon's localized formatting.
Adding a locale is a drop-in: a new `resources/mail-templates/{locale}.php` and
`lang/{locale}/emails.php`.

## Console

Settings → **Emails** (`settings:read` to view, `settings:manage` to write):

- **Index** — every event × locale × seller with its resolved source (default vs overridden).
- **Editor** — subject + body with the event's available-variable reference and a **live
  server-rendered preview** of the actual branded email (rendered through the real pipeline,
  shown in a sandboxed iframe), plus **reset to default**.
- **Test send** — routes through the real notifier, honouring test-mode capture (captured,
  not delivered, while the console is in test mode).

## Extending

- **A new event type**: add a `MailEventType` case with its variables + samples, a default
  entry in each `resources/mail-templates/{locale}.php`, and a `TransactionalMailable`
  subclass (or reuse one) mapping the trigger payload to the variable bag.
- **A new locale**: drop in `resources/mail-templates/{locale}.php` and
  `lang/{locale}/emails.php`, and add it to `config('billing.mail.locales')`.
