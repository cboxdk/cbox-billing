<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Webhooks\Events;
use App\Billing\Webhooks\Events\CouponRedeemed;
use App\Billing\Webhooks\Events\DunningExhausted;
use App\Billing\Webhooks\Events\LicenseRevoked;
use App\Billing\Webhooks\Events\PaymentFailed;
use App\Billing\Webhooks\Events\SubscriptionCanceled;
use App\Billing\Webhooks\Events\SubscriptionCreated;
use App\Billing\Webhooks\WebhookDispatcher;
use App\Billing\Webhooks\WebhookEventSubscriber;
use Cbox\Billing\Events\CreditNoteIssued;
use Cbox\Billing\Events\InvoiceIssued;
use Cbox\Billing\Events\LicenseIssued;
use Cbox\Billing\Events\PaymentSettled;
use Cbox\Billing\Events\SubscriptionChanged;
use Cbox\Billing\Events\SubscriptionRenewed;
use Cbox\Billing\Retention\Events\SubscriptionCancellationRequested;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the outbound webhook / event bus: binds one {@see WebhookEventSubscriber} to every source
 * domain event (engine + first-party app events), and schedules the retry sweep. Emission is
 * queued and idempotent (see {@see WebhookDispatcher}), so subscribing here never blocks the
 * emitting request.
 */
class WebhookServiceProvider extends ServiceProvider
{
    /**
     * Every source event feeding the catalog. Engine events the app already dispatches, plus the
     * first-party {@see Events} raised at the lifecycle moments the engine does not
     * model as events.
     *
     * @var list<class-string>
     */
    private const SOURCE_EVENTS = [
        InvoiceIssued::class,
        PaymentSettled::class,
        CreditNoteIssued::class,
        SubscriptionChanged::class,
        SubscriptionRenewed::class,
        SubscriptionCancellationRequested::class,
        LicenseIssued::class,
        SubscriptionCreated::class,
        SubscriptionCanceled::class,
        PaymentFailed::class,
        DunningExhausted::class,
        CouponRedeemed::class,
        LicenseRevoked::class,
    ];

    public function boot(): void
    {
        Event::listen(self::SOURCE_EVENTS, [WebhookEventSubscriber::class, 'handle']);

        if (config('billing.webhooks.schedule_retries', true) !== false) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->call(fn () => $this->app->make(WebhookDispatcher::class)->retryPending())
                    ->everyMinute()
                    ->name('cbox-billing:webhooks:retry')
                    ->withoutOverlapping();
            });
        }
    }
}
