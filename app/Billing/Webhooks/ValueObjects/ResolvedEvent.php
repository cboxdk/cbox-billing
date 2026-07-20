<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\ValueObjects;

use App\Billing\Webhooks\Enums\WebhookEvent;
use App\Billing\Webhooks\EventPayloadMapper;

/**
 * A source domain event resolved to its outbound shape: the catalog `type`, a stable `id` (the
 * idempotency key derived from the event's natural business key), and the JSON-safe `data`
 * payload. Produced by {@see EventPayloadMapper}.
 */
readonly class ResolvedEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public WebhookEvent $type,
        public string $id,
        public array $data,
    ) {}
}
