<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The foreign-exchange reference-rate store used ONLY by consolidated reporting — never by
 * the ledger, which always stays in a transaction's own currency. Each row is one directed
 * quote `1 unit of base = rate units of quote`, effective on `as_of_date`, drawn from a named
 * `source`.
 *
 * Two sources populate it (deny-by-default, never a fabricated rate):
 *  - `ecb`      — the European Central Bank euro foreign-exchange reference rates
 *    (https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml), base EUR, ingested by
 *    the `fx:refresh` pull. The single citable, free, public feed the adapter parses.
 *  - `override` — an operator/treasury-supplied rate (config `billing.fx.overrides` or a
 *    console-authored row) for a pair ECB does not cover, or a fixed internal rate. An
 *    override on the same (date, base, quote) supersedes ECB.
 *
 * This is global operator reference data (a rate is not a property of any one tenant), so the
 * table carries NO `livemode` column and is not plane-partitioned — mirroring the warehouse
 * control-plane tables. `rate` is a fixed-scale decimal STRING (never a float) so no minor-unit
 * precision is ever lost; cross-rates for pairs neither source lists directly are derived
 * exactly via the EUR pivot at read time (see the Fx repository), not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table): void {
            $table->id();
            $table->date('as_of_date');
            $table->string('base', 3);
            $table->string('quote', 3);
            // 20 integer + 12 fractional digits: enough for high-magnitude quotes (JPY, HUF per
            // EUR) at full ECB precision and beyond, stored as an exact decimal string.
            $table->decimal('rate', 32, 12);
            $table->string('source', 16);
            $table->timestamps();

            // One rate per (date, directed pair, source): a refresh upserts rather than
            // duplicating, and ECB + override can coexist on the same date/pair (override wins).
            $table->unique(['as_of_date', 'base', 'quote', 'source']);
            $table->index(['base', 'quote', 'as_of_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
