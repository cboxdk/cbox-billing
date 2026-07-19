<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The per-line NET amount (M5). `amount_minor` is the extended GROSS (net + tax) the totals
 * reconcile against; the invoice document's line table is headed NET, so it needs the true
 * pre-tax figure per line. On a tax-exclusive invoice printing gross under a NET heading
 * makes the column fail to sum to the net Subtotal.
 *
 * Additive + backfilled: existing rows are seeded from `amount_minor` (the only figure on
 * hand) so a legacy tax-inclusive line keeps rendering the same number, while every line
 * issued from here carries its real net.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->bigInteger('net_minor')->nullable()->after('unit_minor');
        });

        // Best-effort backfill: for historical rows the net is unknown, so seed it from the
        // extended gross — the pre-fix behaviour — rather than leaving it null.
        DB::table('invoice_lines')->whereNull('net_minor')->update([
            'net_minor' => DB::raw('amount_minor'),
        ]);
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropColumn('net_minor');
        });
    }
};
