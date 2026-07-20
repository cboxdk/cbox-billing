<?php

declare(strict_types=1);

namespace App\Billing\Hosted;

use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Hosted\Enums\SessionStatus;
use App\Billing\Hosted\Enums\SessionType;
use App\Billing\Mode\LivemodeScope;
use App\Models\BillingSession;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mints and resolves hosted sessions. The token is a 48-char URL-safe random string —
 * non-guessable, so possession of the URL is what authorizes the page — and each session
 * carries a TTL (`billing.hosted.session_ttl_minutes`) after which a pending token no
 * longer opens its page.
 *
 * Thin by design: it owns only the row and its token/TTL invariants. The checkout's
 * payment intent (and the activation that flips it complete) live in
 * {@see CheckoutPaymentFlow} and {@see CheckoutActivation}; the portal's management
 * actions are the same lifecycle services the management API drives.
 */
readonly class BillingSessionService implements ManagesBillingSessions
{
    public function __construct(private int $ttlMinutes) {}

    public function openCheckout(Organization $organization, Plan $plan, ?string $currency, string $returnUrl, ?string $couponCode = null): BillingSession
    {
        return $this->open($organization, SessionType::Checkout, $returnUrl, $plan->key, $currency, $couponCode);
    }

    public function openOrReuseCheckout(Organization $organization, Plan $plan, ?string $currency, string $returnUrl): BillingSession
    {
        $existing = BillingSession::query()
            ->where('organization_id', $organization->id)
            ->where('type', SessionType::Checkout->value)
            ->where('plan_key', $plan->key)
            ->where('status', SessionStatus::Pending->value)
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();

        if ($existing instanceof BillingSession) {
            return $existing;
        }

        return $this->openCheckout($organization, $plan, $currency, $returnUrl);
    }

    public function openPortal(Organization $organization, string $returnUrl): BillingSession
    {
        return $this->open($organization, SessionType::Portal, $returnUrl);
    }

    public function locate(string $token, SessionType $type): ?BillingSession
    {
        // The token is globally unique and is the whole authorization, so this bootstrap
        // lookup runs WITHOUT the plane scope — the ambient mode on a public hosted route is
        // still the LIVE default here. The caller reads the resolved session's `livemode` and
        // sets the request's plane from it before any org/plan/gateway query (the session row
        // is the source of truth for the request's plane).
        $session = BillingSession::query()
            ->withoutGlobalScope(LivemodeScope::class)
            ->where('token', $token)
            ->where('type', $type->value)
            ->first();

        if (! $session instanceof BillingSession) {
            return null;
        }

        // Stamp a lapsed-but-still-pending token expired so its status reads honestly.
        if ($session->status === SessionStatus::Pending && $session->isExpired()) {
            $session->forceFill(['status' => SessionStatus::Expired->value])->save();
        }

        return $session;
    }

    public function complete(BillingSession $session): void
    {
        if ($session->status === SessionStatus::Complete) {
            return;
        }

        $session->forceFill([
            'status' => SessionStatus::Complete->value,
            'completed_at' => Carbon::now(),
        ])->save();
    }

    private function open(Organization $organization, SessionType $type, string $returnUrl, ?string $planKey = null, ?string $currency = null, ?string $couponCode = null): BillingSession
    {
        return BillingSession::query()->create([
            'token' => Str::random(48),
            'organization_id' => $organization->id,
            'type' => $type->value,
            'plan_key' => $planKey,
            'currency' => $currency,
            'coupon_code' => $couponCode,
            'return_url' => $returnUrl,
            'status' => SessionStatus::Pending->value,
            'expires_at' => Carbon::now()->addMinutes($this->ttlMinutes),
        ]);
    }
}
