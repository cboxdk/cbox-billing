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
 *
 * A payload whose only plane signal is AMBIGUOUS (a settlement reference that exists in more than one
 * plane) is refused outright, in the gateway verifier's own words — see {@see refuse()}.
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

        // FAIL CLOSED. No globally-unique gateway signal matched and the settlement reference alone
        // addresses more than one plane, so there is no honest owner: refuse rather than settle an
        // arbitrary (in practice the ambient/production) plane's identically-numbered invoice.
        if ($plane === null) {
            return $this->refuse($verifier, $payload);
        }

        return $context->runInEnvironment($plane, function () use ($verifier, $ingest, $db, $gateway, $payload): JsonResponse {
            try {
                $event = $verifier->verify($payload);
            } catch (WebhookVerificationFailed $e) {
                return $this->failed($e);
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
     * Refuse a payload whose owning plane is ambiguous, WITHOUT telling the caller that is why.
     *
     * An unauthentic payload — which is every payload a prober can produce, since it holds no
     * signing secret — is answered with the gateway verifier's OWN rejection, byte-identical to what
     * the same request would get for any other reference: the route can therefore not be used to ask
     * "does this reference exist in more than one plane?". A payload that DOES carry a valid
     * signature (only a secret holder can mint one) is refused with the verifier's own
     * missing-signature rejection, produced by re-running it over the same body with the signature
     * stripped, so even that answer is a string the route already emits and never a bespoke
     * "ambiguous" marker.
     *
     * Verifying here decides nothing plane-sensitive — the payload is refused either way; it only
     * shapes the error. The verification that GATES a settlement still runs after the plane is
     * resolved, in that plane.
     */
    private function refuse(WebhookVerifier $verifier, WebhookPayload $payload): JsonResponse
    {
        try {
            $verifier->verify($payload);
        } catch (WebhookVerificationFailed $e) {
            return $this->failed($e);
        }

        try {
            $verifier->verify(new WebhookPayload(body: $payload->body, headers: []));
        } catch (WebhookVerificationFailed $e) {
            return $this->failed($e);
        }

        return $this->failed(WebhookVerificationFailed::unsigned());
    }

    /** The one shape every refusal on this route takes. */
    private function failed(WebhookVerificationFailed $e): JsonResponse
    {
        return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
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
