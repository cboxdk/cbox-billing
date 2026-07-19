<?php

declare(strict_types=1);

use App\Billing\Seller\SellerCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator-authored register of selling entities of record + their per-jurisdiction tax
 * registrations. Sellers were config-only (`billing.seller.entities`); Wave 4 makes them
 * editable in the console. {@see SellerCatalog} reads these tables FIRST
 * and falls back to the config shape when empty, so a deployment that never authored a seller
 * keeps its config identity and nothing else changes. The `id` mirrors the config key (e.g.
 * `cbox-dk`) so the two sources are interchangeable; the entity that issues an invoice drives
 * the tax outcome, so this is the seller side of `tax = f(seller, buyer, product)`.
 *
 * A seller is archived (soft) rather than deleted while its `invoice_prefix` still numbers
 * finalized invoices, so the legal record is never orphaned — only a never-referenced draft
 * is hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_entities', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('legal_name');
            $table->string('registration_number');
            $table->string('establishment', 2);
            $table->string('currency', 3);
            $table->string('invoice_prefix');
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_tax_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('seller_entity_id');
            $table->string('country', 2);
            $table->string('number');
            $table->string('subdivision')->nullable();
            $table->string('scheme')->nullable();
            $table->timestamps();

            $table->foreign('seller_entity_id')->references('id')->on('seller_entities')->cascadeOnDelete();
            $table->index('seller_entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_tax_registrations');
        Schema::dropIfExists('seller_entities');
    }
};
