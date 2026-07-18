<?php

declare(strict_types=1);

namespace App\Identity\Contracts;

use Cbox\Id\Client\Webhooks\WebhookEvent;

/**
 * Applies a verified Cbox ID provisioning event to the billing side: it maintains the
 * local access mirror (subject/role → org) — the seat ELIGIBILITY — and reflects org
 * suspend/reactivate. Under the purchased + explicitly-assigned seat model membership never
 * changes the billed quantity; it only updates eligibility and, when auto-assign is enabled,
 * hands out or releases FREE purchased seats within the cap. Idempotent per the envelope's
 * `delivery_id` — a replayed delivery is a safe no-op.
 */
interface SyncsIdentityProvisioning
{
    public function handle(WebhookEvent $event): void;
}
