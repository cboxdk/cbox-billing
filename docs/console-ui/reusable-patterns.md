---
title: Reusable patterns
description: Copy-paste conventions for console screens — the confirm dialog (data-confirm / window.cboxConfirm), the pagination partial, the breadcrumb component, the accessible data-href row, the flash banner, and the responsive grid utilities.
weight: 10
---

# Reusable console patterns

Every provider-console screen composes these patterns. They live in the two layouts
(`resources/views/layouts/app.blade.php` for the console, `layouts/hosted.blade.php` for the
portal) and a handful of partials/components, and are styled entirely with the existing
`cbx-*` tokens (`public/cbox/cbox-app.css`). Reuse them — do not hand-roll equivalents.

## 1. Confirmation dialog — destructive-action guard

One accessible modal (`partials/confirm-dialog.blade.php`) is included by both layouts. It is
focus-trapped, `aria-modal`, closes on ESC, confirms on Enter, and restores focus to the
trigger on close. Use it for **every** destructive or irreversible action.

**Declarative** (forms, links, buttons) — add `data-confirm` and let the guard intercept:

```blade
<form method="POST" action="{{ route('billing.things.delete', $thing) }}"
      data-confirm="Delete {{ $thing->name }}? This cannot be undone."
      data-confirm-title="Delete thing?" data-confirm-label="Delete" data-confirm-variant="destructive">
    @csrf
    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Delete</button>
</form>
```

A guarded `<form>` submits (native validation + the button loading state intact) only after
the operator confirms; a guarded `<a>` navigates only after confirm. Attributes:
`data-confirm` (body, required), `data-confirm-title`, `data-confirm-label`,
`data-confirm-variant` (`destructive` default, or `primary`).

**Programmatic** (JS-driven flows, e.g. the portal) — `window.cboxConfirm(opts)` returns a
`Promise<boolean>`:

```js
const ok = await window.cboxConfirm({ title: 'Void invoice?', body: '…', confirmLabel: 'Void', variant: 'destructive' });
if (!ok) return;
```

To reflect a live choice (e.g. a mode `<select>`), update the form's `data-confirm*`
attributes on change — the guard reads them at submit time. See the retention form in
`resources/views/billing/subscription-detail.blade.php`.

> The dialog is a UX guard, not a security control. Server endpoints must still enforce
> authorization (the `billing.permission:*` middleware) and validation — they intentionally do
> not require a confirm token, so the action works from the API too.

## 2. Pagination — `partials/pagination.blade.php`

Read models return a Laravel paginator; render it with the shared partial, which preserves the
query string (search term + status tab) across pages and renders nothing for a single page:

```blade
{{ $things->links('partials.pagination') }}
```

On the read-model side, paginate the query and map through it, keeping the query string:

```php
return $query->paginate($perPage)->through(fn (Thing $t): array => $this->row($t))->withQueryString();
```

For a projection whose filter is derived in PHP (not a column), build a `LengthAwarePaginator`
over the filtered collection with `'query' => request()->query()` (see `SubscriptionReport`).

## 3. Search + filtered empty state

The list filter is a real `GET` form that preserves the active status tab and reflects the term:

```blade
<form method="GET" action="{{ route('billing.things') }}" class="filters" role="search">
    @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
    <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter things…" aria-label="Filter things"><kbd class="k">F</kbd></div>
    @if ($search)<a href="{{ route('billing.things') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>@endif
    <span style="margin-left:auto" class="num mut">{{ $things->total() }}{{ $search ? ' matching' : '' }} results</span>
</form>
```

Controllers read the term via a small `?q=` helper (trimmed, blank → null) and pass it to the
read model. Search is **server-side** (`where … like`, or a collection filter with `is_string`
guards — never cast `mixed` to string). The `F` key focuses the filter input.

Give `@forelse … @empty` a **distinct filtered empty state** so "no data" and "no matches"
read differently:

```blade
@empty
    <tr><td colspan="6" style="padding:0">
        @if ($search)
            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18])</div><h3>No matches</h3><p>Nothing matches “{{ $search }}”. Try a different term or clear the filter.</p></div>
        @else
            <div class="cbx-empty"><h3>No things yet.</h3><p>…</p></div>
        @endif
    </td></tr>
@endforelse
```

## 4. Accessible table rows — `data-href`

Never use `onclick="window.location=…"`. Make a row a keyboard-operable link; the shell JS
handles click + Enter/Space and ignores clicks that land on inner controls
(links/buttons/forms/inputs), and `.tbl tbody tr[data-href]:focus-visible` gives a visible ring:

```blade
<tr data-href="{{ route('billing.things.show', $thing['id']) }}" tabindex="0" role="link" aria-label="Open {{ $thing['name'] }}">
```

For a currency/scope chip switcher, use a real `<a class="fchip">`, not a button with `onclick`.

## 5. Breadcrumbs — `<x-breadcrumb>`

Deep pages set the topbar trail with the breadcrumb component (falls back to `@yield('crumb')`
for pages that don't):

```blade
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Customers', 'href' => route('billing.customers')],
        ['label' => $customer['org']],
    ]" />
@endsection
```

Keep the existing back-nav ghost button on detail pages.

## 6. Flash + validation — `partials/flash.blade.php`

Every mutating surface reports success/error/validation the same way. Include once near the top
of the page; it reads the common session keys (`status`, `error`, `catalog_notice`,
`license_notice`, `catalog_error`, `license_error`) and `$errors`:

```blade
@include('partials.flash')
```

Flash `->with('status', …)` / `->with('error', …)` from controllers.

## 7. Button loading state

The shell JS disables a form's submit control and prepends a `.cbx-spin` spinner on submit, so a
slow POST can't be double-submitted — no per-form code needed. The portal disables its own
buttons the same way. Keep submit controls as real `<button type="submit">`.

## 8. Responsive utilities

Use the grid utilities instead of inline `grid-template-columns:1fr 1fr`, so two/three-up
layouts collapse to one column below `720px`:

```blade
<div class="cbx-grid-2"> … </div>   {{-- or cbx-grid-3 --}}
```

Below `900px` the tier-2 subnav collapses and the tier-1 rail stays compact; wide tables scroll
inside their panel; below `560px` the topbar tightens. These live in `public/cbox/cbox-app.css`
under the "WAVE 1 — app-wide UX foundation" section — extend that block rather than adding
one-off media queries per screen.
