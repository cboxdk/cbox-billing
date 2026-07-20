<?php

declare(strict_types=1);

namespace App\Billing\Environments;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Models\Environment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * The read seam that resolves a billing {@see Environment} by its stable key. Bound as a
 * singleton and request-memoised, it is what the console middleware, API authenticator and the
 * public token-bootstrap sites use to turn a resolved key (or a legacy test/live mode) into the
 * environment they push onto the ambient {@see BillingContext}.
 *
 * DEFENSIVE by design: if the `environments` table is absent (mid-migration) or a key is not yet
 * seeded, it returns an in-memory {@see Environment::placeholder()} for the well-known keys rather
 * than throwing — so scoping and gateway routing keep working before the seed lands and in unit
 * tests that never touch the table. The production/sandbox placeholders mirror the seed exactly.
 */
class EnvironmentRegistry
{
    /** @var array<string, Environment|null> Request-lifetime memo of resolved rows (null = looked up, absent). */
    private array $cache = [];

    /** Resolve an environment by key — the persisted row, a well-known placeholder, or null when unknown. */
    public function find(string $key): ?Environment
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key] ?? $this->placeholderFor($key);
        }

        $environment = $this->lookup($key);

        $this->cache[$key] = $environment;

        return $environment ?? $this->placeholderFor($key);
    }

    /** The active production plane — the persisted row or its DB-free placeholder. */
    public function production(): Environment
    {
        return $this->find(Environment::PRODUCTION) ?? Environment::defaultProduction();
    }

    /** The default sandbox plane — the persisted row or its DB-free placeholder. */
    public function sandbox(): Environment
    {
        return $this->find(Environment::SANDBOX) ?? Environment::defaultSandbox();
    }

    /** The default plane every request starts in (production/live). */
    public function default(): Environment
    {
        return $this->production();
    }

    /**
     * Resolve a plane by the key stamped on a resolved row (a hosted session, quote, license, …) —
     * the SOURCE OF TRUTH for that request's plane. Returns the persisted environment, a well-known
     * placeholder (production/sandbox), or — for an unknown/unseeded key — a SANDBOX-typed
     * placeholder carrying that key, so an unrecognised plane is isolated and routed at the fake
     * gateway (deny-by-default) rather than reaching the real one.
     */
    public function resolve(string $key): Environment
    {
        if ($key === '') {
            return $this->default();
        }

        return $this->find($key) ?? Environment::placeholder($key, EnvironmentType::Sandbox, GatewayKeyMode::Test);
    }

    /** Bridge a legacy test/live {@see BillingMode} to its environment (live → production, test → sandbox). */
    public function forBillingMode(BillingMode $mode): Environment
    {
        return $mode->isTest() ? $this->sandbox() : $this->production();
    }

    /**
     * All persisted environments (production first), for the console switcher. Empty when unseeded.
     *
     * @return array<int, Environment>
     */
    public function all(): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        return Environment::query()->orderByDesc('protected')->orderBy('key')->get()->all();
    }

    private function lookup(string $key): ?Environment
    {
        if (! $this->tableExists()) {
            return null;
        }

        try {
            return Environment::query()->where('key', $key)->first();
        } catch (QueryException) {
            return null;
        }
    }

    private function placeholderFor(string $key): ?Environment
    {
        return match ($key) {
            Environment::PRODUCTION => Environment::defaultProduction(),
            Environment::SANDBOX => Environment::defaultSandbox(),
            default => null,
        };
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('environments');
        } catch (QueryException) {
            return false;
        }
    }
}
