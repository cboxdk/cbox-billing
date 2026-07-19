<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Billing\Licensing\Exceptions\LicensingException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The on-prem license management API — operator-authed, thin HTTP over
 * {@see IssuesLicenses}: mint a license for a customer + licensable plan, renew (reissue
 * with an extended window), and revoke (add to the signed revocation list). Issuing is
 * deny-by-default: a non-licensable plan is a 422. Every response carries the signed
 * `key` artifact (the copy-pasteable `CBOX_ID_LICENSE_KEY`) and the issuer public key.
 */
class LicenseController extends ApiController
{
    /** `POST /api/v1/licenses` — mint a license for an org on a licensable plan. */
    public function store(Request $request, IssuesLicenses $licenses, Config $config): JsonResponse
    {
        if ($denied = $this->denyUnlessOperator($request)) {
            return $denied;
        }

        $request->validate([
            'customer_id' => ['required', 'string'],
            'plan' => ['required', 'string'],
            'deployment_id' => ['sometimes', 'string'],
            'licensed_domain' => ['sometimes', 'string'],
            'expires_at' => ['sometimes', 'date'],
        ]);

        $organization = Organization::query()->find($request->string('customer_id')->toString());

        if (! $organization instanceof Organization) {
            return $this->notFound('Unknown organization.');
        }

        try {
            $license = $licenses->issue(
                customerId: $organization->id,
                planId: $request->string('plan')->toString(),
                deploymentId: $this->optionalString($request, 'deployment_id'),
                licensedDomain: $this->optionalString($request, 'licensed_domain'),
                expiresAt: $this->optionalDate($request, 'expires_at'),
            );
        } catch (LicensingException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The signed `key` is a show-once secret: tell the idempotency middleware to strip it
        // from the replay copy it persists (SEC-2), so it is never stored at rest.
        return (new JsonResponse($this->present($license, $config), Response::HTTP_CREATED))
            ->withHeaders(['X-Idempotency-Redact' => 'key']);
    }

    /** `POST /api/v1/licenses/{id}/renew` — reissue with an extended window. */
    public function renew(Request $request, string $id, IssuesLicenses $licenses, Config $config): JsonResponse
    {
        if ($denied = $this->denyUnlessOperator($request)) {
            return $denied;
        }

        $request->validate(['expires_at' => ['sometimes', 'date']]);

        try {
            $license = $licenses->renew($id, $this->optionalDate($request, 'expires_at'));
        } catch (LicensingException $e) {
            return $this->notFound($e->getMessage());
        }

        return new JsonResponse($this->present($license, $config));
    }

    /** `POST /api/v1/licenses/{id}/revoke` — add the license to the revocation list. */
    public function revoke(Request $request, string $id, IssuesLicenses $licenses): JsonResponse
    {
        if ($denied = $this->denyUnlessOperator($request)) {
            return $denied;
        }

        $request->validate(['reason' => ['sometimes', 'string']]);

        $licenses->revoke($id, $this->optionalString($request, 'reason'));

        return new JsonResponse(['id' => $id, 'revoked' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(IssuedLicense $license, Config $config): array
    {
        $publicKey = $config->get('billing.licensing.public_key');

        return [
            'id' => $license->id,
            'customer_id' => $license->customerId,
            'deployment_id' => $license->deploymentId,
            'plan' => $license->plan,
            'entitlements' => $license->entitlements,
            'limits' => $license->limits->toArray(),
            'licensed_domain' => $license->licensedDomain,
            'issued_at' => $license->issuedAt->format(DATE_ATOM),
            'not_before' => $license->notBefore->format(DATE_ATOM),
            'expires_at' => $license->expiresAt->format(DATE_ATOM),
            'key' => $license->key,
            'public_key' => is_string($publicKey) && $publicKey !== '' ? $publicKey : null,
        ];
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function optionalDate(Request $request, string $key): ?DateTimeImmutable
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
