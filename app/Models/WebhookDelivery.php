<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
use App\Billing\Webhooks\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One delivery of one business event to one endpoint. The row `id` is the `delivery_id` carried
 * in the envelope and stamped stably across every retry, so a receiver can dedupe on it;
 * `event_id` is the source event's idempotency key (unique per endpoint), so a re-emitted domain
 * event collapses onto this same row instead of double-delivering.
 *
 * @property string $id
 * @property string $endpoint_id
 * @property string $event_type
 * @property string $event_id
 * @property array<string, mixed> $payload
 * @property int $attempt
 * @property DeliveryStatus $status
 * @property int|null $response_code
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read WebhookEndpoint $endpoint
 */
class WebhookDelivery extends Model
{
    use BelongsToMode;
    use HasUlids;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'endpoint_id', 'event_type', 'event_id', 'payload',
        'attempt', 'status', 'response_code', 'next_retry_at', 'delivered_at',
    ];

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * The console record this delivery's event is about, for the delivery-log cross-link: the
     * most specific subject carried in the payload (invoice → subscription → customer). Null
     * when the payload holds no linkable subject id (e.g. an invoice event that only stamped a
     * number, or a license event) — the log then renders the subject as plain text.
     *
     * @return array{label: string, route: string, param: int|string}|null
     */
    public function subjectLink(): ?array
    {
        $payload = $this->payload;

        $invoiceId = $payload['invoice_id'] ?? null;
        if (is_int($invoiceId)) {
            return ['label' => 'Invoice #'.$invoiceId, 'route' => 'billing.invoices.show', 'param' => $invoiceId];
        }

        // Subscription events carry the subscription id as `id`; other events reference it as
        // `subscription_id`. Either identifies the subscription the event is about.
        $subscriptionId = $payload['subscription_id'] ?? null;
        if (! is_int($subscriptionId) && str_starts_with($this->event_type, 'subscription.')) {
            $subscriptionId = $payload['id'] ?? null;
        }
        if (is_int($subscriptionId)) {
            return ['label' => 'Subscription #'.$subscriptionId, 'route' => 'billing.subscriptions.show', 'param' => $subscriptionId];
        }

        $organizationId = $payload['organization_id'] ?? null;
        if (is_string($organizationId) && $organizationId !== '') {
            return ['label' => $organizationId, 'route' => 'billing.customers.show', 'param' => $organizationId];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt' => 'integer',
            'status' => DeliveryStatus::class,
            'response_code' => 'integer',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
