<?php

declare(strict_types=1);

use App\Billing\Notifications\Rendering\SafeTemplateRenderer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-authored overrides for the transactional-email templates. Every lifecycle event
 * type ships a default template in code (resources/mail-templates/{locale}.php); a row here
 * OVERRIDES that default for a specific (event_type, locale, seller_entity_id) key. The
 * resolver reads DB rows first and falls back to the shipped default, so the table is a
 * superset the console writes — never a required one. A deployment that never edited a
 * template renders the shipped defaults exactly.
 *
 * `seller_entity_id` is nullable: a null-seller row is the account-wide override for that
 * (event, locale); a seller-scoped row overrides just that entity's mail. Resolution order:
 * (seller, locale) → (seller, fallback) → (null, locale) → (null, fallback) → shipped default.
 *
 * The body is authored in a restricted, sandboxed mustache syntax (variable interpolation +
 * minimal conditionals/loops) rendered by {@see SafeTemplateRenderer};
 * it is NEVER evaluated as Blade/PHP, and every interpolated value is HTML-escaped, so a
 * customer-controlled variable can never inject markup or execute code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 48);
            $table->string('locale', 12);
            $table->string('seller_entity_id')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->timestamps();

            // One override per (event, locale, seller). The DB treats NULL seller ids as
            // distinct, so the application also guards the null-seller row via updateOrCreate.
            $table->unique(['event_type', 'locale', 'seller_entity_id'], 'mail_templates_key_unique');
            $table->index(['event_type', 'locale']);

            $table->foreign('seller_entity_id')->references('id')->on('seller_entities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_templates');
    }
};
