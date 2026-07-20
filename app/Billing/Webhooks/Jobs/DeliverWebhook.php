<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Jobs;

use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Mode\BillingContext;
use App\Billing\Webhooks\Delivery\WebhookDeliverer;
use App\Models\Environment;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued single-delivery job — the seam that keeps delivery off the request thread: emission only
 * writes the delivery row and pushes this job, so the enforcement/checkout hot paths never wait on
 * a receiver. Thin: it loads the delivery and hands it to the {@see WebhookDeliverer}. A delivery
 * already marked delivered is a no-op (the deliverer guards it), so a duplicate dispatch is safe.
 *
 * The delivery row is plane-partitioned ({@see WebhookDelivery} carries `environment`), but a queue
 * worker runs at the ambient default production plane — so the job carries its own environment key
 * and sets the {@see BillingContext} from it BEFORE loading the row, or a sandbox delivery would be
 * invisible (find returns null under the production scope) and silently no-op. The plane is captured
 * at dispatch from the delivery's own environment.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $deliveryId, public string $environment = Environment::PRODUCTION)
    {
        $queue = config('billing.webhooks.queue');
        $this->onQueue(is_string($queue) ? $queue : null);
    }

    public function handle(WebhookDeliverer $deliverer, BillingContext $context, EnvironmentRegistry $environments): void
    {
        // Set the plane from the job's captured environment before the (scoped) load, so a sandbox
        // delivery resolves against its own plane instead of vanishing under the worker's default.
        $context->setEnvironment($environments->resolve($this->environment));

        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null) {
            return; // endpoint (and its deliveries) deleted before the job ran
        }

        $deliverer->deliver($delivery);
    }
}
