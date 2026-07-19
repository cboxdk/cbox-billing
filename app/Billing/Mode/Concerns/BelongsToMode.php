<?php

declare(strict_types=1);

namespace App\Billing\Mode\Concerns;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\LivemodeScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks an Eloquent model as belonging to a billing PLANE (live or test). It (1) registers
 * the {@see LivemodeScope} so every query is confined to the request's current plane, and
 * (2) stamps `livemode` on create from the ambient {@see BillingContext} when the caller did
 * not set it explicitly. The default plane is live, so on an app that never enters test mode
 * every row is `livemode = true` and behaviour is unchanged.
 *
 * `livemode` is deliberately NOT mass-assignable: it is set from the resolved mode, never
 * from request input, so a request can never forge the plane it writes into.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToMode
{
    public static function bootBelongsToMode(): void
    {
        $context = app(BillingContext::class);

        static::addGlobalScope(new LivemodeScope($context));

        static::creating(static function (Model $model) use ($context): void {
            if ($model->getAttribute('livemode') === null) {
                $model->setAttribute('livemode', $context->livemode());
            }
        });
    }

    public function initializeBelongsToMode(): void
    {
        $this->mergeCasts(['livemode' => 'boolean']);
    }

    /** Whether this row lives in the live plane. */
    public function isLive(): bool
    {
        return (bool) $this->getAttribute('livemode');
    }
}
