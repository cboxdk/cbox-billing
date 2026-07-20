<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Mode\BillingContext;
use App\Billing\Payments\WebhookPlaneResolver;
use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\IngestOutcome;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /webhooks/{gateway}` — the single settlement entry point. The raw, untrusted bytes
 * are proved authentic and normalized by the bound {@see WebhookVerifier} (deny-by-default:
 * an unsigned/forged payload is refused), then handed to the exactly-once
 * {@see WebhookIngest}, which applies the paid effect at most once per invoice reference and
 * writes its two durable guards in the SAME transaction as the effect — so a re-delivery or
 * a crash mid-apply can neither double-apply nor drop the settlement.
 *
 * Thin: it marshals the HTTP request into a {@see WebhookPayload}, resolves the reference's owning
 * plane and sets it BEFORE verifying, wraps the ingest in one transaction, and maps the
 * {@see IngestOutcome} to a response. All idempotency logic lives in the engine.
 */
class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $gateway,
        WebhookVerifier $verifier,
        WebhookIngest $ingest,
        WebhookPlaneResolver $planes,
        BillingContext $context,
        ConnectionInterface $db,
    ): JsonResponse {
        $payload = new WebhookPayload(
            body: $request->getContent(),
            headers: $this->headers($request),
        );

        // HP1: the settlement route carries no credential to set the plane, so resolve the owning
        // plane from the (still-unverified) reference and push it BEFORE verifying — the plane-aware
        // verifier then proves the signature against THAT plane's secret, so a sandbox's own DB
        // secret verifies for its reference and the global/production secret can NOT authenticate a
        // payload aimed at a sandbox reference. The peek only picks the secret; authenticity is still
        // the signature check below.
        $plane = $planes->forSettlementPayload($payload);

        return $context->runInEnvironment($plane, function () use ($verifier, $ingest, $db, $gateway, $payload): JsonResponse {
            try {
                $event = $verifier->verify($payload);
            } catch (WebhookVerificationFailed $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
            }

            // The effect and its settle-once/processed guards commit atomically.
            $outcome = $db->transaction(static fn () => $ingest->ingest($event));

            return new JsonResponse([
                'gateway' => $gateway,
                'status' => $outcome->status->value,
                'reference' => $outcome->reference,
                'applied' => $outcome->wasApplied(),
            ]);
        });
    }

    /**
     * Flatten the request headers to the single-value shape {@see WebhookPayload} matches
     * case-insensitively.
     *
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $first = $values[0] ?? null;

            if (is_string($first)) {
                $headers[$name] = $first;
            }
        }

        return $headers;
    }
}
