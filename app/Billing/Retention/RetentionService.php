<?php

declare(strict_types=1);

namespace App\Billing\Retention;

use App\Billing\Retention\Contracts\ManagesRetention;
use App\Billing\Retention\Enums\CancellationMode;
use App\Billing\Retention\Exceptions\RetentionException;
use App\Billing\Retention\ValueObjects\CancellationRequest;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The retention service (Part 2). It captures a churn reason and routes the cancellation
 * through the retention fork — immediate cancel, cancel-at-period-end, or the
 * pause-instead-of-cancel save — and drives win-back reactivation. The lifecycle mechanics
 * are delegated to the existing engine-backed services ({@see SubscribesOrganizations},
 * {@see ManagesSubscriptionDepth}); this service owns only the reason capture (persisted as
 * an append-only {@see SubscriptionCancellation} log for analytics) and the reactivation
 * decision.
 */
readonly class RetentionService implements ManagesRetention
{
    public function __construct(
        private ConnectionInterface $db,
        private SubscribesOrganizations $subscriptions,
        private ManagesSubscriptionDepth $depth,
        private Config $config,
    ) {}

    public function cancel(Subscription $subscription, CancellationRequest $request): Subscription
    {
        return $this->db->transaction(function () use ($subscription, $request): Subscription {
            // Capture the reason FIRST — for every mode, including a pause save — so the
            // analytics log records the intent even when the churn was averted.
            $this->record($subscription, $request->mode->value, $request->reason, $request->feedback);

            return match ($request->mode) {
                CancellationMode::Immediate => $this->subscriptions->cancel($subscription, atPeriodEnd: false),
                CancellationMode::PeriodEnd => $this->subscriptions->cancel($subscription, atPeriodEnd: true),
                CancellationMode::Pause => $this->depth->pause($subscription),
            };
        });
    }

    public function reactivate(Subscription $subscription): Subscription
    {
        return $this->db->transaction(function () use ($subscription): Subscription {
            // A paused subscription: lift the pause (the primary win-back path).
            if ($subscription->isPaused()) {
                $this->depth->resume($subscription);
                $this->record($subscription, SubscriptionCancellation::MODE_REACTIVATE, null, null);

                return $subscription->refresh();
            }

            // A scheduled period-end cancel that has not yet fired: undo it.
            if ($subscription->cancel_at_period_end && ! $subscription->isCanceled()) {
                $subscription->forceFill(['cancel_at_period_end' => false])->save();
                $this->record($subscription, SubscriptionCancellation::MODE_REACTIVATE, null, null);

                return $subscription->refresh();
            }

            // A recently-canceled subscription, within the win-back window: re-subscribe it
            // to the same plan (Active from now, grants re-provisioned).
            if ($subscription->isCanceled() && $this->withinWinBackWindow($subscription)) {
                $organization = $subscription->organization;
                $plan = $subscription->plan;

                if ($organization instanceof Organization && $plan instanceof Plan) {
                    $this->subscriptions->subscribe($organization, $plan, max(1, $subscription->seats));
                    $this->record($subscription, SubscriptionCancellation::MODE_REACTIVATE, null, null);

                    return $subscription->refresh();
                }
            }

            throw RetentionException::notReactivatable();
        });
    }

    /** Append the retention event to the analytics log. */
    private function record(Subscription $subscription, string $mode, ?string $reason, ?string $feedback): void
    {
        SubscriptionCancellation::query()->create([
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'plan_id' => $subscription->plan_id,
            'mode' => $mode,
            'reason' => $reason,
            'feedback' => $feedback,
        ]);
    }

    /** Whether the subscription was canceled recently enough to be won back. */
    private function withinWinBackWindow(Subscription $subscription): bool
    {
        $canceledAt = $subscription->canceled_at;

        if ($canceledAt === null) {
            return false;
        }

        $windowDays = $this->config->get('billing.retention.reactivation_window_days', 30);
        $windowDays = is_numeric($windowDays) ? (int) $windowDays : 30;

        return $canceledAt->greaterThanOrEqualTo(Carbon::now()->subDays($windowDays));
    }
}
