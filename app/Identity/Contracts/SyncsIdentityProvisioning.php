<?php

declare(strict_types=1);

namespace App\Identity\Contracts;

use Cbox\Id\Client\Webhooks\WebhookEvent;

/**
 * Applies a verified Cbox ID provisioning event to the billing side: it maintains the
 * local access mirror (subject/role → org), keeps seat counts in step with membership,
 * and reflects org suspend/reactivate. Idempotent per the envelope's `delivery_id` — a
 * replayed delivery is a safe no-op.
 */
interface SyncsIdentityProvisioning
{
    public function handle(WebhookEvent $event): void;
}
