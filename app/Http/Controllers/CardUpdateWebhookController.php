<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Payments\Contracts\UpdatesCards;
use App\Billing\Payments\Contracts\VerifiesCardUpdates;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /webhooks/{gateway}/payment-method` — the card / account-updater ingest, the seam the
 * engine's settlement webhook does not cover. The raw, untrusted bytes are proved authentic and
 * normalized by the bound {@see VerifiesCardUpdates} (deny-by-default), then handed to the
 * {@see UpdatesCards} seam, which points the account's default at the fresh card and re-attempts
 * any in-dunning charge the new card can recover — all in one transaction.
 *
 * Thin: it marshals the request into a {@see WebhookPayload}, verifies, applies, and maps the
 * result to a response. All recovery logic lives in the seam.
 */
class CardUpdateWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $gateway,
        VerifiesCardUpdates $verifier,
        UpdatesCards $updater,
        ConnectionInterface $db,
    ): JsonResponse {
        $payload = new WebhookPayload(
            body: $request->getContent(),
            headers: $this->headers($request),
        );

        try {
            $update = $verifier->verify($payload);
        } catch (WebhookVerificationFailed $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $result = $db->transaction(static fn () => $updater->apply($update));

        return new JsonResponse([
            'gateway' => $gateway,
            'applied' => $result->applied,
            'organization' => $result->organizationId,
            'reattempted' => $result->reattempted,
            'recovered' => $result->recovered,
            'reason' => $result->reason,
        ]);
    }

    /**
     * Flatten request headers to the single-value shape {@see WebhookPayload} matches
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
