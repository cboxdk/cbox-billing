<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Billing\Hosted\CheckoutPaymentFlow;
use App\Billing\Hosted\Enums\SessionType;
use App\Billing\Support\MoneyFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * The hosted checkout page and its two client calls (ADR-0009 Path A). The page renders on
 * the design-system tokens, authorized solely by the session token; its JS creates the
 * gateway intent, mounts the gateway's own element (card data + any SCA / 3-D Secure
 * challenge stay client-side), and — on a client-side success — polls {@see status()}
 * until the settled webhook has activated the subscription, then returns to the merchant.
 *
 * Thin: it validates the token and maps the {@see CheckoutPaymentFlow} result to a
 * response. Activation is never performed here — only the settled webhook does that.
 */
class CheckoutController extends HostedController
{
    /** The checkout page: the plan, its price, and the mount point for the gateway element. */
    public function show(string $token, CheckoutPaymentFlow $flow): View
    {
        $session = $this->require($token, SessionType::Checkout);
        $plan = $flow->plan($session);
        $currency = $flow->currency($session);

        return view('hosted.checkout', [
            'session' => $session,
            'plan' => $plan,
            'price' => MoneyFormatter::money($plan->priceFor($currency)),
            'currency' => $currency,
        ]);
    }

    /**
     * `POST` — create the PaymentIntent and return everything the frontend needs to mount
     * the gateway element: `{gateway, publishableKey, clientSecret, status}` plus the
     * settlement reference and amount.
     */
    public function intent(string $token, CheckoutPaymentFlow $flow): JsonResponse
    {
        $session = $this->require($token, SessionType::Checkout);

        if (! $session->isUsable()) {
            return new JsonResponse(['error' => 'This checkout is already complete.'], 409);
        }

        $result = $flow->intent($session);

        return new JsonResponse([
            'gateway' => $result->gateway,
            'publishable_key' => $result->publishableKey,
            'client_secret' => $result->clientSecret,
            'status' => $result->status->value,
            'requires_action' => $result->requiresCustomerAction(),
            'reference' => $result->reference,
            'amount' => $result->amount === null ? null : [
                'minor' => $result->amount->minor(),
                'currency' => $result->amount->currency(),
            ],
        ]);
    }

    /**
     * `GET` — the poll the page runs after a client-side success: the session's authoritative
     * status (flipped complete only by the settled webhook) and where to return to.
     */
    public function status(string $token): JsonResponse
    {
        $session = $this->sessions->locate($token, SessionType::Checkout);

        if ($session === null) {
            return new JsonResponse(['error' => 'Unknown session.'], 404);
        }

        return new JsonResponse([
            'status' => $session->status->value,
            'complete' => $session->isComplete(),
            'return_url' => $session->return_url,
        ]);
    }
}
