<?php

declare(strict_types=1);

namespace App\Billing\Webhooks;

use App\Billing\Webhooks\Delivery\WebhookDeliverer;
use App\Billing\Webhooks\Enums\DeliveryStatus;
use App\Billing\Webhooks\Jobs\DeliverWebhook;
use App\Billing\Webhooks\ValueObjects\ResolvedEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

/**
 * Fans a resolved event out to every active, subscribed endpoint by writing an idempotent delivery
 * row and enqueuing a {@see DeliverWebhook} job per endpoint — never delivering inline, so the
 * emitting request thread is not blocked. A re-emit of the same business event (same `event_id`)
 * collapses onto the existing row via the `(endpoint_id, event_id)` unique key, so it does not
 * double-deliver.
 */
class WebhookDispatcher
{
    public function __construct(private readonly WebhookDeliverer $deliverer) {}

    /** @return int the number of deliveries enqueued */
    public function dispatch(ResolvedEvent $resolved): int
    {
        $endpoints = WebhookEndpoint::query()
            ->where('active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->isSubscribedTo($resolved->type));

        $enqueued = 0;

        foreach ($endpoints as $endpoint) {
            $delivery = $this->recordFor($endpoint, $resolved);

            if ($delivery->wasRecentlyCreated) {
                DeliverWebhook::dispatch($delivery->id);
                $enqueued++;
            }
        }

        return $enqueued;
    }

    /**
     * Sweep failed deliveries whose backoff is due and re-attempt them. Driven by the scheduler
     * (or `webhooks:retry-pending`), so a transient receiver outage recovers without a caller
     * wiring the retry loop by hand.
     *
     * @return int the number of deliveries re-attempted
     */
    public function retryPending(int $limit = 100): int
    {
        $due = WebhookDelivery::query()
            ->with('endpoint')
            ->where('status', DeliveryStatus::Failed->value)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->get();

        foreach ($due as $delivery) {
            $this->deliverer->deliver($delivery);
        }

        return $due->count();
    }

    private function recordFor(WebhookEndpoint $endpoint, ResolvedEvent $resolved): WebhookDelivery
    {
        return WebhookDelivery::query()->firstOrCreate(
            ['endpoint_id' => $endpoint->id, 'event_id' => $resolved->id],
            [
                'event_type' => $resolved->type->value,
                'payload' => $resolved->data,
                'attempt' => 0,
                'status' => DeliveryStatus::Pending,
            ],
        );
    }
}
