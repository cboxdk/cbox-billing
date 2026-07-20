<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Environments\EnvironmentType;
use App\Billing\Environments\GatewayKeyMode;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Platform\EnvironmentContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A first-class, named billing PLANE. This generalises the old binary test/live `livemode`
 * flag into an addressable environment: production (the real, protected, live-gateway plane)
 * and one or more sandboxes (isolated, disposable, fake-gateway datasets). Every plane-scoped
 * row now carries the environment's stable {@see $key} (with the legacy `livemode` boolean kept
 * as a synced mirror during the transition), and the ambient {@see BillingContext}
 * holds the active {@see Environment} rather than a bool.
 *
 * Environments are BILLING-INTERNAL: billing owns their CRUD so CI can later clone/destroy
 * sandboxes freely. `cbox_id_environment` is an OPTIONAL mapping to a Cbox ID environment (the
 * {@see EnvironmentContext} plane a login carries) — nullable, never required, so a
 * single-environment deploy needs no Cbox ID wiring.
 *
 * `protected` guards production: a protected environment can never be deleted and (in a later
 * wave) requires live gateway keys. The two invariants — one production plane, always live keys —
 * are why production is seeded protected.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property EnvironmentType $type
 * @property bool $protected
 * @property GatewayKeyMode $gateway_key_mode
 * @property string|null $cbox_id_environment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Environment extends Model
{
    /** The stable key of the one seeded production plane (the legacy live plane). */
    public const PRODUCTION = 'production';

    /** The stable key of the default seeded sandbox plane (the legacy test plane). */
    public const SANDBOX = 'sandbox';

    protected $fillable = [
        'key', 'name', 'type', 'protected', 'gateway_key_mode', 'cbox_id_environment',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => EnvironmentType::class,
            'protected' => 'boolean',
            'gateway_key_mode' => GatewayKeyMode::class,
        ];
    }

    /** Whether this is the real, money-moving plane (the legacy `livemode = true`). */
    public function isProduction(): bool
    {
        return $this->type === EnvironmentType::Production;
    }

    /** The `livemode` mirror rows in this environment carry (production → true, sandbox → false). */
    public function livemode(): bool
    {
        return $this->type->livemode();
    }

    /** The gateway-key mode this environment charges through (drives the mode-aware gateway). */
    public function gatewayKeyMode(): GatewayKeyMode
    {
        return $this->gateway_key_mode;
    }

    /** The legacy plane enum this environment bridges to, for the transitional test/live surfaces. */
    public function billingMode(): BillingMode
    {
        return $this->gateway_key_mode->billingMode();
    }

    /**
     * An UNSAVED, in-memory environment standing in for a plane without a DB round-trip. This is
     * the DB-free default the {@see BillingContext} starts from (production) and
     * the fallback when the `environments` table is absent (mid-migration) or a key is unseeded —
     * so scoping and gateway routing never depend on a query at boot.
     */
    public static function placeholder(string $key, EnvironmentType $type, ?GatewayKeyMode $gatewayKeyMode = null): self
    {
        $environment = new self([
            'key' => $key,
            'name' => ucfirst($key),
            'type' => $type,
            'protected' => $type->isProduction(),
            'gateway_key_mode' => $gatewayKeyMode ?? $type->defaultGatewayKeyMode(),
        ]);

        $environment->exists = false;

        return $environment;
    }

    /** The DB-free default plane — production/live — every request starts in until a credential names another. */
    public static function defaultProduction(): self
    {
        return self::placeholder(self::PRODUCTION, EnvironmentType::Production, GatewayKeyMode::Live);
    }

    /** The DB-free default sandbox plane, for the transitional test-mode bridge. */
    public static function defaultSandbox(): self
    {
        return self::placeholder(self::SANDBOX, EnvironmentType::Sandbox, GatewayKeyMode::Test);
    }

    /** The in-memory placeholder a legacy {@see BillingMode} maps onto (live → production, test → sandbox). */
    public static function forBillingMode(BillingMode $mode): self
    {
        return $mode->isTest() ? self::defaultSandbox() : self::defaultProduction();
    }
}
