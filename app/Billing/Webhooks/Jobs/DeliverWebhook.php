<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Jobs;

use App\Billing\Webhooks\Delivery\WebhookDeliverer;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued single-delivery job — the seam that keeps delivery off the request thread: emission only
 * writes the delivery row and pushes this job, so the enforcement/checkout hot paths never wait on
 * a receiver. Thin: it loads the delivery and hands it to the {@see WebhookDeliverer}. A delivery
 * already marked delivered is a no-op (the deliverer guards it), so a duplicate dispatch is safe.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $deliveryId)
    {
        $queue = config('billing.webhooks.queue');
        $this->onQueue(is_string($queue) ? $queue : null);
    }

    public function handle(WebhookDeliverer $deliverer): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null) {
            return; // endpoint (and its deliveries) deleted before the job ran
        }

        $deliverer->deliver($delivery);
    }
}
