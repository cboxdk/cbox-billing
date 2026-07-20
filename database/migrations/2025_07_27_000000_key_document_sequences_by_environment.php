<?php

declare(strict_types=1);

use App\Models\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Key the LEGAL document sequences by plane as well as by seller.
 *
 * `invoice_sequences` / `credit_note_sequences` were keyed by `seller` ALONE. That is safe only
 * while no two planes can ever name the same seller: today a cloned seller is stored under a
 * plane-namespaced primary key, so in practice each plane draws its own counter. But the invariant
 * lived entirely outside the sequence — nothing in the schema said so — and one live path already
 * broke it: a plane that has authored no `seller_entities` row falls back to the `billing.seller`
 * CONFIG, whose id is the SAME string in every plane. A sandbox issuing under that fallback drew
 * PRODUCTION's counter: it consumed a number production would never issue, so production's legal
 * numbering silently GAPPED.
 *
 * The counter is therefore keyed by `(seller, environment)`: each plane owns its own gapless series
 * for a given seller id, and no sandbox draw can advance — or gap — production's.
 *
 * BACKFILL. Every existing row is stamped with the plane of its seller (`seller_entities.environment`),
 * defaulting to production for a seller resolved from config or since deleted — which is exactly
 * where those counters have been drawing from, so no live series moves and none restarts.
 *
 * DEPLOY NOTE — TABLE REBUILD (not a plain column add): both sequence tables are rebuilt to widen
 * the PRIMARY key from `(seller)` to `(seller, environment)`. They are small (one row per seller per
 * plane) and the rebuild is a rename → create → insert-select → drop inside the migration's
 * transaction. Take the usual write pause for invoice finalization while it runs; the row lock these
 * counters are drawn under is per-row, so a concurrent finalize would otherwise block on the rebuild.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['invoice_sequences', 'credit_note_sequences'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'environment')) {
                continue;
            }

            $legacy = $table.'_legacy';

            Schema::dropIfExists($legacy);
            Schema::rename($table, $legacy);

            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->string('seller');
                $blueprint->string('environment')->default(Environment::PRODUCTION);
                $blueprint->unsignedBigInteger('next_value')->default(1);
                $blueprint->timestamps();

                $blueprint->primary(['seller', 'environment']);
            });

            DB::table($legacy)->orderBy('seller')->chunk(500, function ($rows) use ($table): void {
                $insert = [];

                foreach ($rows as $row) {
                    $seller = is_string($row->seller) ? $row->seller : '';

                    $insert[] = [
                        'seller' => $seller,
                        'environment' => $this->planeOf($seller),
                        'next_value' => is_numeric($row->next_value) ? (int) $row->next_value : 1,
                        'created_at' => $row->created_at ?? null,
                        'updated_at' => $row->updated_at ?? null,
                    ];
                }

                if ($insert !== []) {
                    DB::table($table)->insert($insert);
                }
            });

            Schema::drop($legacy);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'environment')) {
                continue;
            }

            $legacy = $table.'_legacy';

            Schema::dropIfExists($legacy);
            Schema::rename($table, $legacy);

            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->string('seller')->primary();
                $blueprint->unsignedBigInteger('next_value')->default(1);
                $blueprint->timestamps();
            });

            // Collapsing back to a seller-only key keeps the HIGHEST counter per seller, so no plane
            // can reissue a number it already used.
            $rows = DB::table($legacy)
                ->selectRaw('seller, MAX(next_value) as next_value')
                ->groupBy('seller')
                ->get();

            foreach ($rows as $row) {
                DB::table($table)->insert([
                    'seller' => is_string($row->seller) ? $row->seller : '',
                    'next_value' => is_numeric($row->next_value) ? (int) $row->next_value : 1,
                ]);
            }

            Schema::drop($legacy);
        }
    }

    /** The plane a seller id belongs to — its register row's, else production. */
    private function planeOf(string $seller): string
    {
        if ($seller === '' || ! Schema::hasTable('seller_entities') || ! Schema::hasColumn('seller_entities', 'environment')) {
            return Environment::PRODUCTION;
        }

        $plane = DB::table('seller_entities')->where('id', $seller)->value('environment');

        return is_string($plane) && $plane !== '' ? $plane : Environment::PRODUCTION;
    }
};
