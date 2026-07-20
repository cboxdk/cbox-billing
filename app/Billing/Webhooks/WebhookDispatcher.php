<?php

declare(strict_types=1);

namespace App\Billing\Webhooks;

use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\EnvironmentScope;
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
    public function __construct(
        private readonly WebhookDeliverer $deliverer,
        private readonly BillingContext $context,
        private readonly EnvironmentRegistry $environments,
    ) {}

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
                DeliverWebhook::dispatch($delivery->id, $delivery->environmentKey());
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
        // The sweep runs at the scheduler's default production plane, so query WITHOUT the plane
        // scope — otherwise a failed sandbox delivery is invisible and never retries.
        $due = WebhookDelivery::query()
            ->withoutGlobalScope(EnvironmentScope::class)
            ->where('status', DeliveryStatus::Failed->value)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->get();

        foreach ($due as $delivery) {
            // Deliver each row in ITS OWN plane, then load its (plane-scoped) endpoint from inside
            // that plane so the relation resolves — a sandbox delivery's endpoint is not dropped.
            $this->context->runInEnvironment($this->environments->resolve($delivery->environmentKey()), function () use ($delivery): void {
                $delivery->loadMissing('endpoint');
                $this->deliverer->deliver($delivery);
            });
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
