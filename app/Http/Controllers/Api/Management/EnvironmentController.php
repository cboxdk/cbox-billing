<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Environments\Contracts\CreatesEnvironments;
use App\Billing\Environments\Contracts\DestroysEnvironments;
use App\Billing\Environments\Contracts\ResetsEnvironments;
use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Environments\Exceptions\EnvironmentCloneException;
use App\Billing\Environments\Exceptions\EnvironmentProtectedException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token-authed environment-management surface (`/api/v1/environments`) for CI and programmatic
 * use. Operator-only (an org-scoped token can never manage planes) and, additionally, a token
 * BOUND to a sandbox plane may only manage its OWN plane — a throwaway-env token can neither reach
 * production nor another sandbox (deny-by-default cross-environment isolation).
 *
 * The CI flow this enables: `POST /environments {clone_from: production}` deep-copies production's
 * config into a fresh sandbox and returns `{environment, token}` — an operator token bound to that
 * plane; the token then drives normal management/enforcement calls that all resolve THAT plane's
 * config + data (isolated from production); `DELETE /environments/{key}` tears the whole plane down.
 * Production is never reset or destroyed (403). Thin over the lifecycle contracts.
 */
class EnvironmentController extends ApiController
{
    public function __construct(private readonly EnvironmentRegistry $registry) {}

    /** `GET /environments` — list every plane (operator-only). */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessOperator($request)) {
            return $denied;
        }

        $environments = array_map(fn (Environment $e): array => $this->present($e), $this->registry->all());

        return new JsonResponse(['environments' => $environments]);
    }

    /** `GET /environments/{key}` — show one plane. */
    public function show(Request $request, string $key): JsonResponse
    {
        if ($denied = $this->denyUnlessOperator($request)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayManage($request, $key)) {
            return $denied;
        }

        $environment = $this->registry->find($key);

        if ($environment === null || ! $environment->exists) {
            return $this->notFound('Unknown environment.');
        }

        return new JsonResponse(['environment' => $this->present($environment)]);
    }

    /**
     * `POST /environments` — create a sandbox (optionally `clone_from` an env's config), returning
     * the environment and — unless `with_token=false` — a one-time API token bound to it.
     */
    public function store(Request $request, CreatesEnvironments $creator): JsonResponse
    {
        if ($denied = $this->denyUnlessOperator($request)) {
            return $denied;
        }

        $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:120'],
            'clone_from' => ['nullable', 'string', 'max:40'],
            'with_token' => ['nullable', 'boolean'],
        ]);

        // Creating (and, above all, CLONING) an environment deep-copies config into a plane the
        // caller will control — a privileged, cross-environment action. Deny-by-default: only a
        // production-bound (or static-operator) token may provision another plane; an env-bound CI
        // token can never mint a new sandbox, nor clone `production` into one. The new key is passed
        // as the "managed" plane so an env-bound token (bound to some existing plane, never the new
        // key nor production) is refused, exactly like show/reset/destroy.
        if ($denied = $this->denyUnlessMayManage($request, $request->string('key')->toString())) {
            return $denied;
        }

        $cloneFrom = null;

        if ($request->filled('clone_from')) {
            $cloneFrom = $this->registry->find($request->string('clone_from')->toString());

            if ($cloneFrom === null || ! $cloneFrom->exists) {
                return new JsonResponse(['error' => 'Unknown clone_from environment.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            $result = $creator->create(
                key: $request->string('key')->toString(),
                name: $request->filled('name') ? $request->string('name')->toString() : null,
                cloneFrom: $cloneFrom,
                withToken: $request->boolean('with_token', true),
            );
        } catch (EnvironmentCloneException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = ['environment' => $this->present($result->environment), 'cloned' => $result->cloned];

        // The plaintext is returned exactly once (only its SHA-256 is stored) so CI can capture it.
        if ($result->tokenPlaintext !== null) {
            $payload['token'] = $result->tokenPlaintext;
        }

        return new JsonResponse($payload, Response::HTTP_CREATED);
    }

    /** `DELETE /environments/{key}` — hard teardown of a sandbox (env + all its data). */
    public function destroy(Request $request, string $key, DestroysEnvironments $destroyer): JsonResponse
    {
        if ($denied = $this->denyUnlessOperator($request)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayManage($request, $key)) {
            return $denied;
        }

        $environment = $this->registry->find($key);

        if ($environment === null || ! $environment->exists) {
            return $this->notFound('Unknown environment.');
        }

        try {
            $result = $destroyer->destroy($environment);
        } catch (EnvironmentProtectedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'environment' => $key,
            'destroyed' => true,
            'deleted_rows' => $result->totalDeleted(),
        ]);
    }

    /** `POST /environments/{key}/reset` — wipe a sandbox's book (keep config), optionally reseeding. */
    public function reset(Request $request, string $key, ResetsEnvironments $resetter): JsonResponse
    {
        if ($denied = $this->denyUnlessOperator($request)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayManage($request, $key)) {
            return $denied;
        }

        $request->validate([
            'reseed_from' => ['nullable', 'string', 'max:40'],
        ]);

        $environment = $this->registry->find($key);

        if ($environment === null || ! $environment->exists) {
            return $this->notFound('Unknown environment.');
        }

        $reseedFrom = null;

        if ($request->filled('reseed_from')) {
            $reseedFromKey = $request->string('reseed_from')->toString();

            // A reseed deep-copies the SOURCE plane's config into this one, so the token must be
            // allowed to manage the source too — otherwise an env-bound token could reset its own
            // sandbox `reseed_from: production` and pull production's config across the boundary
            // (the same cross-environment leak the create/clone guard closes).
            if ($denied = $this->denyUnlessMayManage($request, $reseedFromKey)) {
                return $denied;
            }

            $reseedFrom = $this->registry->find($reseedFromKey);

            if ($reseedFrom === null || ! $reseedFrom->exists) {
                return new JsonResponse(['error' => 'Unknown reseed_from environment.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            $result = $resetter->reset($environment, $reseedFrom);
        } catch (EnvironmentProtectedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'environment' => $key,
            'reset' => true,
            'reseeded' => $reseedFrom !== null,
            'deleted_rows' => $result->totalDeleted(),
        ]);
    }

    /**
     * Cross-environment isolation: a token bound to a NON-production plane may only manage the plane
     * it is bound to — it can never reach production or another sandbox. A production-bound (or the
     * static operator) token may manage any plane. Refuse with 403 otherwise.
     */
    private function denyUnlessMayManage(Request $request, string $key): ?JsonResponse
    {
        $identity = $this->identity($request);
        $bound = $identity->environmentKey;

        if ($bound === Environment::PRODUCTION || $bound === $key) {
            return null;
        }

        return new JsonResponse(
            ['error' => 'This token is bound to a different environment and may not manage the requested one.'],
            Response::HTTP_FORBIDDEN,
        );
    }

    /** @return array<string, mixed> */
    private function present(Environment $environment): array
    {
        return [
            'key' => $environment->key,
            'name' => $environment->name,
            'type' => $environment->type->value,
            'protected' => $environment->protected,
            'gateway_key_mode' => $environment->gateway_key_mode->value,
        ];
    }
}
