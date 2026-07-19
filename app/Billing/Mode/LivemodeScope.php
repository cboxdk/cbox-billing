<?php

declare(strict_types=1);

namespace App\Billing\Mode;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The deny-by-default plane partition. Applied to every tenant-state model via
 * {@see Concerns\BelongsToMode}, it constrains all reads to the rows of the request's current
 * plane — `livemode = true` for a live credential, `= false` for a test one — resolved from
 * the ambient {@see BillingContext}. A live token therefore can never see (or, via
 * find/update, touch) a test row and vice-versa: a cross-mode lookup simply returns nothing,
 * so the controller 404s. Isolation is enforced here, once, rather than trusted to every
 * query remembering to filter.
 *
 * The column-presence guard makes the scope safe DURING migrations: a legacy data-migration
 * (or a fresh build) may query a partitioned model before its `livemode` column has been
 * added — in that window the scope is a no-op. Only the positive result is memoized (once the
 * column exists it never disappears), so steady-state adds no per-query schema check.
 *
 * @implements Scope<Model>
 */
class LivemodeScope implements Scope
{
    /** @var array<string, true> Tables confirmed to have the `livemode` column (positive-only memo). */
    private static array $hasColumn = [];

    public function __construct(private readonly BillingContext $context) {}

    public function apply(Builder $builder, Model $model): void
    {
        $table = $model->getTable();

        if (! isset(self::$hasColumn[$table])) {
            if (! $model->getConnection()->getSchemaBuilder()->hasColumn($table, 'livemode')) {
                return; // column not present yet (mid-migration) — do not constrain.
            }

            self::$hasColumn[$table] = true;
        }

        // Constrain via the underlying query builder: a qualified `table.livemode` column is
        // not a model attribute, so the base builder's `where` is the correct, type-clean seam.
        $builder->getQuery()->where($table.'.livemode', $this->context->livemode());
    }
}
