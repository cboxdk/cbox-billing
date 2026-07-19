<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Seats\Exceptions\SeatException;
use App\Billing\Seats\ValueObjects\SeatBreakdown;
use App\Http\Controllers\Api\ApiController;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The seat surface of the management API — thin HTTP over {@see ManagesSeats}. Purchased
 * Full seats (the subscription quantity, the only billing driver) are set through the
 * engine's changeQuantity; assignment hands a purchased seat to an eligible member. Every
 * write is per-org scoped (a token for org A cannot touch org B → 403) exactly as the
 * sibling subscription endpoints; a refused invariant (no free seat / release below the
 * assigned count / not eligible) maps to 409.
 */
class SeatController extends ApiController
{
    /** `GET /api/v1/subscriptions/{org}/seats` — the seat breakdown (purchased, Full, Light). */
    public function show(Request $request, string $org, ManagesSeats $seats): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->servingSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no serving subscription.');
        }

        return new JsonResponse($this->present($seats->breakdown($subscription)));
    }

    /**
     * `POST /api/v1/subscriptions/{org}/seats` {seats} — set the purchased Full-seat count
     * (buy/release) through the engine's prorated changeQuantity. Refuses dropping below the
     * assigned count or below one (409).
     */
    public function setPurchased(Request $request, string $org, ManagesSeats $seats): JsonResponse
    {
        $request->validate(['seats' => ['required', 'integer', 'min:1']]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->servingSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no serving subscription.');
        }

        try {
            $seats->setPurchased($subscription, $request->integer('seats'));
        } catch (SeatException $e) {
            return $this->refused($e);
        }

        return new JsonResponse($this->present($seats->breakdown($subscription->refresh())));
    }

    /**
     * `POST /api/v1/subscriptions/{org}/seats/assign` {subject} — assign a free purchased
     * seat to an eligible member. 409 when no seat is free or the subject is not eligible.
     */
    public function assign(Request $request, string $org, ManagesSeats $seats): JsonResponse
    {
        $request->validate(['subject' => ['required', 'string']]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->servingSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no serving subscription.');
        }

        try {
            $seats->assign($subscription, $request->string('subject')->toString());
        } catch (SeatException $e) {
            return $this->refused($e);
        }

        return new JsonResponse($this->present($seats->breakdown($subscription->refresh())));
    }

    /**
     * `POST /api/v1/subscriptions/{org}/seats/unassign` {subject} — free a member's seat
     * (they become Light). The purchased count is unchanged.
     */
    public function unassign(Request $request, string $org, ManagesSeats $seats): JsonResponse
    {
        $request->validate(['subject' => ['required', 'string']]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->servingSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no serving subscription.');
        }

        $seats->unassign($org, $request->string('subject')->toString());

        return new JsonResponse($this->present($seats->breakdown($subscription->refresh())));
    }

    /** The org's serving subscription — the seat authority. */
    private function servingSubscription(string $org): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('organization_id', $org)
            ->serving()
            ->latest('current_period_start')
            ->first();
    }

    private function refused(SeatException $e): JsonResponse
    {
        return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
    }

    /** @return array<string, mixed> */
    private function present(SeatBreakdown $breakdown): array
    {
        return [
            'purchased' => $breakdown->purchased,
            'assigned' => $breakdown->assigned,
            'free' => $breakdown->free(),
            'full_count' => $breakdown->fullCount(),
            'light_count' => $breakdown->lightCount(),
            'full' => $breakdown->full,
            'light' => $breakdown->light,
            'types' => $this->types(),
        ];
    }

    /**
     * The seat types as configured (labels + billable flag) — so a client can render Full
     * vs Light and know which bills. Config-shaped for a future priced-Light tier.
     *
     * @return array<array-key, mixed>
     */
    private function types(): array
    {
        $types = config('billing.seats.types', []);

        return is_array($types) ? $types : [];
    }
}
