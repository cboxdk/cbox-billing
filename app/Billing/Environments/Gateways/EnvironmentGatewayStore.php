<?php

declare(strict_types=1);

namespace App\Billing\Environments\Gateways;

use App\Billing\Environments\GatewayKeyMode;
use App\Models\Environment;
use App\Models\EnvironmentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * The read/write seam for per-environment gateway credentials. Reads resolve the ACTIVE row for a
 * plane (request-memoised, defensive against a missing table mid-migration); writes go through the
 * {@see StripeKeyValidator} so a plane can never persist a key of the wrong type
 * ({@see GatewayCredentialException}). Only Stripe credentials are supported — the manual gateway
 * carries no keys.
 *
 * Bound as a singleton so the gateway resolver and the console/API share one memoised view within
 * a request; {@see forget()} clears the memo after a write so the next resolve sees the change.
 */
class EnvironmentGatewayStore
{
    /** The one gateway whose per-environment credentials are supported. */
    public const GATEWAY = 'stripe';

    /** @var array<string, EnvironmentGateway|false> Request memo (false = looked up, none active). */
    private array $memo = [];

    public function __construct(private readonly StripeKeyValidator $validator) {}

    /** The active gateway credentials for a plane, or null when it has none (falls back to env-var/manual). */
    public function activeFor(string $environmentKey): ?EnvironmentGateway
    {
        if (array_key_exists($environmentKey, $this->memo)) {
            return $this->memo[$environmentKey] ?: null;
        }

        $row = $this->lookup($environmentKey);

        $this->memo[$environmentKey] = $row ?? false;

        return $row;
    }

    /** The stored credentials for a plane regardless of the active flag (for the console editor). */
    public function forEnvironment(string $environmentKey): ?EnvironmentGateway
    {
        if (! $this->tableExists()) {
            return null;
        }

        return EnvironmentGateway::query()
            ->where('environment', $environmentKey)
            ->where('gateway', self::GATEWAY)
            ->first();
    }

    /**
     * Persist (create or update) a plane's Stripe credentials, validating the key TYPE against the
     * plane's {@see GatewayKeyMode} first — a sandbox may only hold test
     * keys, production only live keys.
     *
     * @throws GatewayCredentialException when a key's type does not match the plane's mode
     */
    public function put(Environment $environment, string $secret, ?string $publishable, ?string $webhookSecret, bool $active = true): EnvironmentGateway
    {
        $mode = $environment->gatewayKeyMode();

        $this->validator->validateSecret($secret, $mode);
        $this->validator->validatePublishable($publishable, $mode);

        $publishable = $this->normalize($publishable);
        $webhookSecret = $this->normalize($webhookSecret);

        $row = EnvironmentGateway::query()->updateOrCreate(
            ['environment' => $environment->key, 'gateway' => self::GATEWAY],
            [
                'secret' => trim($secret),
                'publishable' => $publishable,
                'webhook_secret' => $webhookSecret,
                'active' => $active,
            ],
        );

        $this->forget($environment->key);

        return $row;
    }

    /** Remove a plane's stored credentials (it reverts to the env-var/manual fallback). */
    public function delete(string $environmentKey): void
    {
        if ($this->tableExists()) {
            EnvironmentGateway::query()
                ->where('environment', $environmentKey)
                ->where('gateway', self::GATEWAY)
                ->delete();
        }

        $this->forget($environmentKey);
    }

    /** Drop the request memo for a plane so the next resolve re-reads the row. */
    public function forget(string $environmentKey): void
    {
        unset($this->memo[$environmentKey]);
    }

    private function lookup(string $environmentKey): ?EnvironmentGateway
    {
        if (! $this->tableExists()) {
            return null;
        }

        try {
            return EnvironmentGateway::query()
                ->where('environment', $environmentKey)
                ->where('gateway', self::GATEWAY)
                ->where('active', true)
                ->first();
        } catch (QueryException) {
            return null;
        }
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('environment_gateways');
        } catch (QueryException) {
            return false;
        }
    }
}
