<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Billing\Licensing\LicenseActivationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/v1/license/activate` — the optional online activation heartbeat a self-hosted
 * deployment may call to refresh its artifacts. Given its `deployment_id`, it returns the
 * latest signed license bound to that deployment (in case it was reissued on renewal), the
 * current signed revocation list, and the issuer public key.
 *
 * The deployment id is the credential: an unknown deployment gets a generic 404, never a
 * fabricated bundle. This is a REFRESH path only — offline installs never call it and must
 * not depend on it; the route is rate-limited to keep it from being probed. No operator
 * token is required (a self-hosted deployment holds none), and the endpoint never accepts
 * or exposes the private key.
 */
class LicenseActivationController extends Controller
{
    public function __invoke(Request $request, LicenseActivationService $activation): JsonResponse
    {
        $deploymentId = $request->query('deployment_id');

        if (! is_string($deploymentId) || $deploymentId === '') {
            return new JsonResponse(['error' => 'A deployment_id is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $bundle = $activation->refresh($deploymentId);

        if ($bundle === null) {
            return new JsonResponse(['error' => 'No license found for this deployment.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'deployment_id' => $bundle->deploymentId,
            'license_id' => $bundle->licenseId,
            'license_key' => $bundle->licenseKey,
            'expires_at' => $bundle->expiresAt->format(DATE_ATOM),
            'revocation_list' => $bundle->revocationList,
            'public_key' => $bundle->publicKey !== '' ? $bundle->publicKey : null,
        ]);
    }
}
