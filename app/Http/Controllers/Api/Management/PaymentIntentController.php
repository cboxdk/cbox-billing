<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Payments\Contracts\ResolvesGatewayCustomer;
use App\Http\Controllers\Api\ApiController;
use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/payment-intents` {org, invoice? | amount+currency?} — create a gateway
 * PaymentIntent a product's frontend confirms ON-SESSION against the gateway's element
 * (ADR-0009 Path B). Either charge a named {@see Invoice} (amount + currency taken from
 * the invoice, reference = its document number so the settled webhook marks it paid) or
 * an ad-hoc `amount` in minor units of `currency`.
 *
 * Card data and any SCA challenge stay on the gateway's element; reaching succeeded
 * client-side never settles anything — the engine marks paid strictly on the settled
 * webhook. Per-org scoped (403). Thin: validate, authorize, resolve the gateway customer,
 * delegate to the bound {@see PaymentGateway}, map the result.
 */
class PaymentIntentController extends ApiController
{
    public function __invoke(Request $request, PaymentGateway $gateway, ResolvesGatewayCustomer $customers): JsonResponse
    {
        $request->validate([
            'org' => ['required', 'string'],
            'invoice' => ['required_without:amount', 'string'],
            'amount' => ['required_without:invoice', 'integer', 'min:1'],
            'currency' => ['required_with:amount', 'string', 'size:3'],
        ]);

        $org = $request->string('org')->toString();

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $organization = Organization::query()->find($org);

        if (! $organization instanceof Organization) {
            return new JsonResponse(['error' => 'Unknown organization.'], Response::HTTP_NOT_FOUND);
        }

        [$amount, $reference] = $request->filled('invoice')
            ? $this->fromInvoice($request->string('invoice')->toString(), $org)
            : $this->adHoc($request);

        if ($amount === null) {
            return new JsonResponse(['error' => 'Unknown invoice for this organization.'], Response::HTTP_NOT_FOUND);
        }

        $result = $gateway->createPaymentIntent(new PaymentIntentRequest(
            account: $customers->resolve($organization),
            reference: $reference,
            amount: $amount,
            idempotencyKey: $reference,
        ));

        return new JsonResponse([
            'gateway' => $result->gateway,
            'publishable_key' => $result->publishableKey,
            'client_secret' => $result->clientSecret,
            'status' => $result->status->value,
            'reference' => $result->reference,
        ], Response::HTTP_CREATED);
    }

    /**
     * The invoice's own total drives the charge, and its document number is both the
     * intent reference and the idempotency key — stable, so a retried creation collapses
     * to one gateway intent and the settled webhook joins back to mark THIS invoice paid.
     *
     * @return array{0: ?Money, 1: string}
     */
    private function fromInvoice(string $number, string $org): array
    {
        $invoice = Invoice::query()
            ->where('organization_id', $org)
            ->where('number', $number)
            ->first();

        if (! $invoice instanceof Invoice) {
            return [null, $number];
        }

        return [Money::ofMinor($invoice->total_minor, $invoice->currency), $invoice->number];
    }

    /**
     * An ad-hoc charge: the amount in minor units of the given currency, with a freshly
     * minted stable reference that doubles as the idempotency key.
     *
     * @return array{0: Money, 1: string}
     */
    private function adHoc(Request $request): array
    {
        $amount = Money::ofMinor(
            $request->integer('amount'),
            strtoupper($request->string('currency')->toString()),
        );

        return [$amount, 'pi_'.Str::random(24)];
    }
}
