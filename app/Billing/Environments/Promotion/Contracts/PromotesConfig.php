<?php

declare(strict_types=1);

namespace App\Billing\Environments\Promotion\Contracts;

use App\Billing\Environments\Promotion\Exceptions\PromotionException;
use App\Billing\Environments\Promotion\PromotionSelection;
use App\Billing\Environments\Promotion\ValueObjects\PromotionPreview;
use App\Billing\Environments\Promotion\ValueObjects\PromotionResult;
use App\Models\Environment;

/**
 * Publishes SELECTED config from one {@see Environment} to another — the "clone prod → sandbox →
 * fiddle → promote the parts you want back to prod" flow. Objects are matched across planes by
 * stable NATURAL KEY (ids differ per plane); each selected object is classified created / updated
 * / unchanged against the target, intra-config relationships are remapped to the TARGET's ids, and
 * the apply is additive-and-upsert — it updates a matched target row IN PLACE and creates a new
 * one when absent, but NEVER deletes a target object the selection did not include.
 *
 * Bound contracts-first so the console screen, the `environment:promote` command and the tests all
 * resolve the one implementation. Preview writes nothing; apply runs in a single transaction, is
 * idempotent (re-promoting an unchanged object is a no-op), and is audit-logged.
 */
interface PromotesConfig
{
    /**
     * Compute the write-free diff of promoting `$selection` from `$source` to `$target`:
     * created / updated / unchanged per object, the field-level diffs, and any blocking
     * dependency conflicts. No rows are touched.
     *
     * @throws PromotionException when source/target are the same or unknown
     */
    public function preview(Environment $source, Environment $target, PromotionSelection $selection): PromotionPreview;

    /**
     * Apply the promotion: upsert the selected objects into `$target`, remapping relationships to
     * the target plane's ids, in one transaction, and record a `config.promoted` audit event.
     *
     * @throws PromotionException when the selection is empty or the preview surfaced blocking conflicts
     */
    public function promote(Environment $source, Environment $target, PromotionSelection $selection): PromotionResult;
}
