<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-seller transactional-email branding (additive). The selling entity of record already
 * carries the legal identity that footers every invoice; these columns give it the customer-
 * facing brand the lifecycle emails wrap around — a header logo, an accent colour on buttons
 * and rules, a validated from-name / from-email / reply-to, a footer legal address, and the
 * support / social links. Every column is nullable: an entity that never authored branding
 * falls back to the app-level defaults (config('billing.mail')), so nothing changes for a
 * fresh install until an operator fills them in.
 *
 * `default_locale` is the entity's own fallback language — the middle layer of the locale
 * resolution chain (customer locale → seller default → app fallback).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_entities', function (Blueprint $table): void {
            $table->string('brand_color', 9)->nullable()->after('invoice_prefix');
            $table->string('logo_url')->nullable()->after('brand_color');
            $table->string('from_name')->nullable()->after('logo_url');
            $table->string('from_email')->nullable()->after('from_name');
            $table->string('reply_to')->nullable()->after('from_email');
            $table->text('footer_address')->nullable()->after('reply_to');
            $table->string('support_url')->nullable()->after('footer_address');
            $table->string('support_email')->nullable()->after('support_url');
            $table->string('default_locale', 12)->nullable()->after('support_email');
        });
    }

    public function down(): void
    {
        Schema::table('seller_entities', function (Blueprint $table): void {
            $table->dropColumn([
                'brand_color', 'logo_url', 'from_name', 'from_email', 'reply_to',
                'footer_address', 'support_url', 'support_email', 'default_locale',
            ]);
        });
    }
};
