<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
use App\Billing\Webhooks\Enums\WebhookEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An integrator-registered outbound subscriber. The signing `secret` is stored encrypted at rest
 * (Laravel `encrypted` cast, keyed by APP_KEY) and opened only to compute the HMAC signature at
 * delivery time. `event_types` is the JSON set of {@see WebhookEvent} wire types this endpoint is
 * subscribed to; an event is delivered here only when the endpoint is `active` and subscribed.
 *
 * @property string $id
 * @property string $url
 * @property string $secret
 * @property string|null $description
 * @property bool $active
 * @property array<int, string> $event_types
 * @property string|null $created_by_sub
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
class WebhookEndpoint extends Model
{
    use BelongsToMode;
    use HasUlids;

    protected $table = 'webhook_endpoints';

    protected $fillable = ['url', 'secret', 'description', 'active', 'event_types', 'created_by_sub'];

    protected $hidden = ['secret'];

    /** Mint a fresh 256-bit signing secret as a hex string (the shared HMAC key). */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function isSubscribedTo(WebhookEvent|string $event): bool
    {
        $type = $event instanceof WebhookEvent ? $event->value : $event;

        return in_array($type, $this->event_types, true);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'event_types' => 'array',
            'active' => 'boolean',
        ];
    }
}
