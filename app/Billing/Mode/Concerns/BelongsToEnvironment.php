<?php

declare(strict_types=1);

namespace App\Billing\Mode\Concerns;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\EnvironmentScope;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks an Eloquent model as belonging to a billing ENVIRONMENT (plane). It (1) registers the
 * {@see EnvironmentScope} so every query is confined to the request's current environment, and
 * (2) stamps `environment` on create from the ambient {@see BillingContext} when the caller did
 * not set it explicitly. The default environment is production, so on an app that never enters a
 * sandbox every row is `environment = 'production'` and behaviour is unchanged.
 *
 * The legacy `livemode` boolean is RETAINED as a synced mirror: it is stamped alongside
 * `environment` (production → true, sandbox → false) so external data contracts (warehouse
 * exports, DSAR manifests) and the audit-chain hash stay stable during the transition. Both
 * `environment` and `livemode` are deliberately NOT mass-assignable: they are set from the
 * resolved plane, never from request input, so a request can never forge the plane it writes into.
 *
 * The `livemode` mirror is stamped ONLY on tables that carry the column. The tenant/operational
 * tables (Wave 1) do; the CONFIG tables that adopt the plane later (catalog, branding, templates,
 * storefront, …) never carried `livemode`, so stamping it there would insert a phantom column.
 * A per-table, positive-and-negative memo of the column's presence keeps steady-state free of a
 * per-insert schema check.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToEnvironment
{
    /** @var array<string, bool> Per-table memo of whether the table carries the `livemode` mirror column. */
    private static array $hasLivemodeColumn = [];

    public static function bootBelongsToEnvironment(): void
    {
        $context = app(BillingContext::class);

        static::addGlobalScope(new EnvironmentScope($context));

        static::creating(static function (Model $model) use ($context): void {
            if ($model->getAttribute('environment') === null) {
                $model->setAttribute('environment', $context->environmentKey());
            }

            // Keep the legacy livemode mirror in step for tables that still carry it.
            if (self::tableHasLivemodeColumn($model) && $model->getAttribute('livemode') === null) {
                $model->setAttribute('livemode', $context->livemode());
            }
        });
    }

    /** Whether this model's table carries the `livemode` mirror column (memoised per table). */
    private static function tableHasLivemodeColumn(Model $model): bool
    {
        $table = $model->getTable();

        return self::$hasLivemodeColumn[$table] ??= $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($table, 'livemode');
    }

    public function initializeBelongsToEnvironment(): void
    {
        $this->mergeCasts(['livemode' => 'boolean']);
    }

    /** Whether this row lives in the production (live) plane. */
    public function isLive(): bool
    {
        return $this->getAttribute('environment') === Environment::PRODUCTION;
    }

    /** The environment key this row belongs to. */
    public function environmentKey(): string
    {
        $key = $this->getAttribute('environment');

        return is_string($key) ? $key : Environment::PRODUCTION;
    }
}
