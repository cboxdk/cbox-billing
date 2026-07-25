<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes EU B2B reverse charge reachable, and makes the decision auditable.
 *
 * The reverse-charge branch of the tax engine requires `customerTaxIdValidated === true`.
 * `organizations.tax_id_validated` existed but defaulted to false and NO production code path ever
 * set it — only test fixtures did. The console collected a `tax_id` and never checked it. So the
 * branch was unreachable, and a Danish seller invoicing a German GmbH with a valid DE VAT ID
 * charged German VAT it has no German registration to remit: a real liability, and a product that
 * cannot be used for EU B2B.
 *
 * Two columns are needed beyond the existing flag, for two different reasons:
 *
 *  - `customer_type` — the tax context hardcoded `CustomerType::Business` for EVERY buyer. That
 *    was harmless only because the reverse-charge gate never opened; opening it without this would
 *    flip genuine CONSUMERS into reverse charge on any cross-border supply, which is the same bug
 *    pointing the other way. The two changes have to ship together.
 *
 *  - `tax_id_validated_at` / `tax_id_validation_reference` / `tax_id_validation_source` — a
 *    zero-rated cross-border supply has to be *evidenced*, not merely asserted. VIES returns a
 *    consultation reference precisely so a seller can prove it checked; storing the bare boolean
 *    keeps the tax outcome but throws away the evidence for it. The timestamp also makes
 *    re-validation possible: a VAT ID valid last year may be deregistered today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            // Defaults to `business`, which preserves the exact behaviour of the hardcoded value
            // it replaces — so this migration changes no tax outcome on its own.
            $table->string('customer_type', 16)->default('business')->after('tax_id_validated');
            $table->timestamp('tax_id_validated_at')->nullable()->after('customer_type');
            $table->string('tax_id_validation_reference')->nullable()->after('tax_id_validated_at');
            $table->string('tax_id_validation_source', 32)->nullable()->after('tax_id_validation_reference');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_type',
                'tax_id_validated_at',
                'tax_id_validation_reference',
                'tax_id_validation_source',
            ]);
        });
    }
};
