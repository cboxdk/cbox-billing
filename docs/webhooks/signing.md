---
title: Signing & verification
description: How outbound deliveries are signed — the HMAC-SHA256 envelope over timestamp.body, the X-Cbox-Signature and X-Cbox-Timestamp headers, and a verification snippet.
weight: 10
---

# Signing & verification

Every delivery is signed with the endpoint's **signing secret** (shown once when you register the endpoint or rotate its secret — store it in your integration's config). The scheme is deliberately **symmetric with the app's own inbound verifier**: the same primitive (`hash_hmac('sha256', …)`) and the same constant-time comparison (`hash_equals`).

## The envelope

The request body is a JSON object:

```json
{
  "id": "invoice:CBX-2026-0001",
  "type": "invoice.issued",
  "data": { "number": "CBX-2026-0001", "account": "org_acme", "currency": "DKK", "...": "..." },
  "delivery_id": "01JABC…",
  "created_at": "2026-07-19T10:00:00+00:00"
}
```

- `id` — the source event's stable idempotency key (dedupe your side-effects on this).
- `type` — the event type from the [catalog](event-catalog.md).
- `data` — the event payload.
- `delivery_id` — the per-delivery id, stable across retries of the *same* delivery.

## The headers

```
Content-Type: application/json
X-Cbox-Timestamp: 1752921600
X-Cbox-Signature: t=1752921600,v1=<hex hmac>
X-Cbox-Event-Type: invoice.issued
X-Cbox-Delivery-Id: 01JABC…
```

The signature is computed over the string `"{timestamp}.{body}"` — binding the signature to a moment so you can reject a replayed delivery outside your tolerance window:

```
v1 = hex( HMAC_SHA256( key = secret, message = timestamp + "." + raw_request_body ) )
```

## Verifying a delivery

Recompute the MAC from the raw body and the `t=` value in the header, then compare in constant time. Reject if the timestamp is outside your tolerance (300 seconds is a reasonable default).

```php
function verify(string $rawBody, string $sigHeader, string $secret, int $tolerance = 300): bool
{
    // Parse "t=…,v1=…"
    $t = null; $v1 = null;
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $val] = array_pad(explode('=', trim($part), 2), 2, null);
        if ($k === 't' && ctype_digit((string) $val)) { $t = (int) $val; }
        if ($k === 'v1') { $v1 = $val; }
    }
    if ($t === null || $v1 === null) { return false; }
    if (abs(time() - $t) > $tolerance) { return false; }        // replay window

    $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);

    return hash_equals($expected, $v1);
}
```

Always verify against the **raw** request bytes, before any JSON re-encoding — re-encoding can change whitespace/key order and break the MAC.

## Rotating the secret

Rotating (Settings → Webhooks → **Rotate secret**) mints a new secret and invalidates the old one **immediately**. Update your integration in the same window to avoid a verification gap. The plaintext is shown once, rendered directly into the response — it is never stored in plaintext or flashed through a session.
