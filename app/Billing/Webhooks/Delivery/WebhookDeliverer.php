<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Delivery;

use App\Billing\Webhooks\Enums\DeliveryStatus;
use App\Billing\Webhooks\Exceptions\UnsafeWebhookUrl;
use App\Billing\Webhooks\Jobs\DeliverWebhook;
use App\Billing\Webhooks\Support\SafeWebhookUrl;
use App\Billing\Webhooks\Support\WebhookSignature;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Performs one HTTP delivery attempt for a {@see WebhookDelivery}: build the signing envelope,
 * SSRF-pin the URL immediately before connecting (TOCTOU-closed), sign `timestamp.body` and POST
 * with short timeouts and no redirects, then record the outcome and schedule an exponential-backoff
 * retry on failure (dead-lettering once the budget is spent). Used by both the queued
 * {@see DeliverWebhook} job and the scheduler retry sweep.
 */
class WebhookDeliverer
{
    /** @throws JsonException never — payload is array-encodable, declared for the analyzer. */
    public function deliver(WebhookDelivery $delivery): void
    {
        if ($delivery->status === DeliveryStatus::Delivered) {
            return; // already succeeded — idempotent no-op on a re-run
        }

        $endpoint = $delivery->endpoint;

        $body = json_encode($this->envelope($delivery), JSON_THROW_ON_ERROR);

        $delivery->attempt++;

        // Validate + pin the resolved IPs immediately before sending, so a DNS rebind between
        // the registration check and this connect cannot redirect the delivery to an internal
        // address. A now-unsafe URL is a retryable failure, not a crash.
        try {
            $pinned = SafeWebhookUrl::pinnedOptions($endpoint->url);
        } catch (UnsafeWebhookUrl) {
            $delivery->response_code = null;
            $this->scheduleRetry($delivery);
            $delivery->save();

            return;
        }

        $timestamp = time();
        $headers = WebhookSignature::headers($body, $endpoint->secret, $timestamp);
        $headers['X-Cbox-Event-Type'] = $delivery->event_type;
        $headers['X-Cbox-Delivery-Id'] = $delivery->id;

        try {
            $response = Http::withHeaders($headers)
                ->withOptions($pinned)
                ->withoutRedirecting()
                ->connectTimeout($this->intConfig('connect_timeout', 5))
                ->timeout($this->intConfig('timeout', 10))
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $delivery->response_code = $response->status();

            if ($response->successful()) {
                $delivery->status = DeliveryStatus::Delivered;
                $delivery->delivered_at = now();
                $delivery->next_retry_at = null;
            } else {
                $this->scheduleRetry($delivery);
            }
        } catch (Throwable) {
            $delivery->response_code = null;
            $this->scheduleRetry($delivery);
        }

        $delivery->save();
    }

    /**
     * The signed envelope. `id` is the source event's idempotency key, `delivery_id` the per-attempt
     * row id a receiver dedupes on, `created_at` the emit time.
     *
     * @return array{id: string, type: string, data: array<string, mixed>, delivery_id: string, created_at: string}
     */
    private function envelope(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->event_id,
            'type' => $delivery->event_type,
            'data' => $delivery->payload,
            'delivery_id' => $delivery->id,
            'created_at' => $delivery->created_at->toIso8601String(),
        ];
    }

    private function scheduleRetry(WebhookDelivery $delivery): void
    {
        $maxAttempts = $this->intConfig('max_attempts', 8);

        if ($delivery->attempt >= $maxAttempts) {
            $delivery->status = DeliveryStatus::Dead;
            $delivery->next_retry_at = null;

            return;
        }

        $ceiling = $this->intConfig('retry_ceiling_minutes', 360);
        $delivery->status = DeliveryStatus::Failed;
        $delivery->next_retry_at = now()->addMinutes(min($ceiling, 2 ** $delivery->attempt));
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config('billing.webhooks.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
