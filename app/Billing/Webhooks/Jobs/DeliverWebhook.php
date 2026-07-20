<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Jobs;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Webhooks\Delivery\WebhookDeliverer;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued single-delivery job — the seam that keeps delivery off the request thread: emission only
 * writes the delivery row and pushes this job, so the enforcement/checkout hot paths never wait on
 * a receiver. Thin: it loads the delivery and hands it to the {@see WebhookDeliverer}. A delivery
 * already marked delivered is a no-op (the deliverer guards it), so a duplicate dispatch is safe.
 *
 * The delivery row is plane-partitioned ({@see WebhookDelivery} carries `livemode`), but a queue
 * worker runs at the ambient default LIVE plane — so the job must carry its own `livemode` and set
 * the {@see BillingContext} from it BEFORE loading the row, or a TEST delivery would be invisible
 * (find returns null under the live scope) and silently no-op. The plane is captured at dispatch
 * from the delivery's own flag.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $deliveryId, public bool $livemode = true)
    {
        $queue = config('billing.webhooks.queue');
        $this->onQueue(is_string($queue) ? $queue : null);
    }

    public function handle(WebhookDeliverer $deliverer, BillingContext $context): void
    {
        // Set the plane from the job's captured flag before the (plane-scoped) load, so a test
        // delivery resolves against the test plane instead of vanishing under the worker's live default.
        $context->setMode(BillingMode::fromLivemode($this->livemode));

        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null) {
            return; // endpoint (and its deliveries) deleted before the job ran
        }

        $deliverer->deliver($delivery);
    }
}
