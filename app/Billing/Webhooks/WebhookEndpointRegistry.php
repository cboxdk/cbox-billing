<?php

declare(strict_types=1);

namespace App\Billing\Webhooks;

use App\Billing\Webhooks\Enums\DeliveryStatus;
use App\Billing\Webhooks\Enums\WebhookEvent;
use App\Billing\Webhooks\Exceptions\UnsafeWebhookUrl;
use App\Billing\Webhooks\Jobs\DeliverWebhook;
use App\Billing\Webhooks\Support\SafeWebhookUrl;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Authoring surface for outbound webhook endpoints — the single place that mints/rotates the
 * signing secret and SSRF-guards the URL at registration. Thin services over the model so the
 * console controller stays an HTTP adapter. The plaintext secret is RETURNED from
 * {@see register()} / {@see rotateSecret()} for a show-once render and is never read back from the
 * store afterwards by the console.
 */
class WebhookEndpointRegistry
{
    /**
     * @param  array<int, mixed>  $eventTypes
     * @return array{endpoint: WebhookEndpoint, secret: string}
     *
     * @throws UnsafeWebhookUrl
     */
    public function register(string $url, array $eventTypes, ?string $description, ?string $createdBySub): array
    {
        SafeWebhookUrl::assert($url);

        $secret = WebhookEndpoint::newSecret();

        $endpoint = new WebhookEndpoint;
        $endpoint->fill([
            'url' => $url,
            'secret' => $secret,
            'description' => $description,
            'active' => true,
            'event_types' => WebhookEvent::sanitize($eventTypes),
            'created_by_sub' => $createdBySub,
        ]);
        $endpoint->save();

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    /**
     * @param  array<int, mixed>  $eventTypes
     *
     * @throws UnsafeWebhookUrl
     */
    public function update(WebhookEndpoint $endpoint, string $url, array $eventTypes, ?string $description): WebhookEndpoint
    {
        SafeWebhookUrl::assert($url);

        $endpoint->fill([
            'url' => $url,
            'description' => $description,
            'event_types' => WebhookEvent::sanitize($eventTypes),
        ])->save();

        return $endpoint;
    }

    /** Roll the signing secret; the old secret stops verifying immediately. Returns the new plaintext. */
    public function rotateSecret(WebhookEndpoint $endpoint): string
    {
        $secret = WebhookEndpoint::newSecret();
        $endpoint->forceFill(['secret' => $secret])->save();

        return $secret;
    }

    public function setActive(WebhookEndpoint $endpoint, bool $active): void
    {
        $endpoint->forceFill(['active' => $active])->save();
    }

    /**
     * Queue a signed `ping` to the endpoint so an integrator can verify wiring end-to-end. The
     * ping carries a fresh `event_id` each time (it is not a catalog event and is not deduped).
     */
    public function sendTest(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create([
            'endpoint_id' => $endpoint->id,
            'event_type' => 'ping',
            'event_id' => 'ping:'.Str::ulid(),
            'payload' => ['message' => 'This is a test event from Cbox Billing.', 'endpoint_id' => $endpoint->id],
            'attempt' => 0,
            'status' => DeliveryStatus::Pending,
        ]);

        DeliverWebhook::dispatch($delivery->id, $delivery->livemode);

        return $delivery;
    }

    /** Re-attempt a failed/dead delivery. Resets it to pending and re-queues; the id is stable. */
    public function redeliver(WebhookDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::Pending,
            'next_retry_at' => null,
        ])->save();

        DeliverWebhook::dispatch($delivery->id, $delivery->livemode);
    }
}
