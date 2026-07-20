<?php

declare(strict_types=1);

namespace App\Billing\Mode;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The deny-by-default plane partition. Applied to every tenant-state model via
 * {@see Concerns\BelongsToEnvironment}, it constrains all reads to the rows of the request's
 * current ENVIRONMENT — `environment = 'production'` by default, or whatever key the credential
 * resolved — read from the ambient {@see BillingContext}. A production credential therefore can
 * never see (or, via find/update, touch) a sandbox row and vice-versa: a cross-environment lookup
 * returns nothing, so the controller 404s. Isolation is enforced here, once, keyed by environment,
 * rather than trusted to every query remembering to filter — and, unlike the old `livemode`
 * boolean, it distinguishes two DIFFERENT sandboxes, which is the whole reason for the key.
 *
 * The column-presence guard makes the scope safe DURING migrations: a data-migration (or a fresh
 * build) may query a partitioned model before its `environment` column has been added — in that
 * window the scope is a no-op. Only the positive result is memoized (once the column exists it
 * never disappears), so steady-state adds no per-query schema check.
 *
 * @implements Scope<Model>
 */
class EnvironmentScope implements Scope
{
    /** @var array<string, true> Tables confirmed to have the `environment` column (positive-only memo). */
    private static array $hasColumn = [];

    public function __construct(private readonly BillingContext $context) {}

    public function apply(Builder $builder, Model $model): void
    {
        $table = $model->getTable();

        if (! isset(self::$hasColumn[$table])) {
            if (! $model->getConnection()->getSchemaBuilder()->hasColumn($table, 'environment')) {
                return; // column not present yet (mid-migration) — do not constrain.
            }

            self::$hasColumn[$table] = true;
        }

        // Constrain via the underlying query builder: a qualified `table.environment` column is
        // not a model attribute, so the base builder's `where` is the correct, type-clean seam.
        $builder->getQuery()->where($table.'.environment', $this->context->environmentKey());
    }
}
