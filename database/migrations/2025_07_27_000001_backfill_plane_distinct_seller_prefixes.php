<?php

declare(strict_types=1);

use App\Billing\Environments\EnvironmentCloner;
use App\Billing\Environments\PlaneDocumentPrefix;
use App\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data fix for environments cloned BEFORE the cloner derived a plane-distinct legal-document prefix.
 *
 * Those clones carry their source's `invoice_prefix` verbatim while drawing their own counter from 1,
 * so the sandbox and production both mint `CBOX-DK-2026-00001` — the collision that made an
 * ambiguous, reference-only settlement payload address two planes at once. New clones are fixed at
 * the source ({@see EnvironmentCloner}); this rewrites the ones already on
 * disk.
 *
 * WHAT IT TOUCHES — only sellers whose prefix is genuinely shared across planes, and only OUTSIDE
 * production. A prefix used in exactly one plane is left alone. PRODUCTION IS NEVER REWRITTEN: its
 * series is the legal record of record, and the whole point is that it keeps issuing exactly what it
 * has always issued. A non-production row is rewritten to {@see PlaneDocumentPrefix}'s derivation,
 * which is the same value a fresh clone of that plane would get, so re-cloning is idempotent.
 *
 * HONEST ABOUT THE SEAM: documents ALREADY issued in a sandbox keep their old numbers, so a sandbox's
 * number series visibly changes stem at this migration (…-00007 then CBOX-DK-CI-CLONE-2026-00008).
 * That is a sandbox, holding no legal documents, and its per-seller counter stays monotonic and
 * gapless across the change — nothing is renumbered, reissued, or lost. Production sees no change of
 * any kind.
 *
 * NOT REVERSIBLE by design: `down()` is a no-op. Restoring a colliding prefix would knowingly
 * re-create the cross-plane collision, and the numbers issued under the new prefix would then be
 * unreachable from the old one.
 *
 * DEPLOY NOTE: data-only UPDATEs on `seller_entities` (typically a handful of rows); no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_entities') || ! Schema::hasColumn('seller_entities', 'environment')) {
            return;
        }

        foreach ($this->collidingPrefixes() as $prefix) {
            $rows = DB::table('seller_entities')
                ->where('invoice_prefix', $prefix)
                ->where('environment', '!=', Environment::PRODUCTION)
                ->get(['id', 'environment']);

            foreach ($rows as $row) {
                $plane = is_string($row->environment) ? $row->environment : '';
                $derived = PlaneDocumentPrefix::for($prefix, $plane);

                if ($derived === $prefix) {
                    continue;
                }

                DB::table('seller_entities')->where('id', $row->id)->update(['invoice_prefix' => $derived]);
            }
        }
    }

    public function down(): void
    {
        // Deliberately irreversible — see the class docblock.
    }

    /**
     * Every `invoice_prefix` held by sellers in more than one plane.
     *
     * @return list<string>
     */
    private function collidingPrefixes(): array
    {
        $prefixes = [];

        foreach (DB::table('seller_entities')->get(['invoice_prefix', 'environment']) as $row) {
            if (! is_string($row->invoice_prefix) || ! is_string($row->environment)) {
                continue;
            }

            $prefixes[$row->invoice_prefix][$row->environment] = true;
        }

        $colliding = [];

        foreach ($prefixes as $prefix => $planes) {
            if (count($planes) > 1) {
                // A numeric prefix comes back as an int array key — normalize.
                $colliding[] = (string) $prefix;
            }
        }

        return $colliding;
    }
};
