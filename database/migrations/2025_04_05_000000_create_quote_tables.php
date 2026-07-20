<?php

declare(strict_types=1);

use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPQ — sales quoting + contracts (Wave 5). A rep authors a quote, it is (optionally) approved
 * above the deal-desk threshold, sent to the customer as a branded hosted order form, accepted
 * by e-signature-by-acceptance, and provisions a subscription through the engine's
 * {@see SubscribesOrganizations} seam. Three tables:
 *
 *  1. `quotes` — the header: who it is for (an existing {@see Organization}, OR a
 *     free-text prospect while pre-account), the selling entity whose branding the order form
 *     wraps around, the currency, the lifecycle `status`, the owner (rep), validity, notes, an
 *     optional order-level `coupon_id`, and the CONTRACT TERMS embedded on the header — contract
 *     length (`term_count`/`term_unit`), the recurring `billing_interval`, the `start_date`, an
 *     optional per-period `minimum_commitment_minor` floor (engine `MinimumCommitment`), and an
 *     optional `ramp` (a JSON list of {from_period_index, amount_minor} → engine `RampSchedule`).
 *     Approval, send/accept, and provisioning columns track the lifecycle; `token` addresses the
 *     no-auth order form; `subscription_id` links the provisioned subscription (idempotency: a
 *     quote provisions at most once).
 *  2. `quote_lines` — the ordered line items: a catalog plan line (plan + quantity) or a custom
 *     one-off (description + unit amount), each with an optional per-line discount. Totals are
 *     always recomputed through the engine quote/pricing at render time — the stored amounts are
 *     inputs, never a cached price.
 *  3. `quote_acceptances` — the IMMUTABLE acceptance record: the typed signer name, the explicit
 *     agreement, the captured timestamp/IP/user-agent, the signature provider that captured it
 *     (`null` = in-house e-sign-by-acceptance), and a snapshot of the accepted totals + committed
 *     value. One row per accepted quote (unique `quote_id`).
 *
 * A plan referenced here is only ever archived in the catalog, never hard-deleted, so a line
 * never orphans; `restrictOnDelete` on the plan makes that explicit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 40)->unique();

            // Who the quote is for. An existing billing org (string PK = the cbox-id org id), OR
            // a pre-account prospect captured as free text — a quote can be authored before the
            // customer has a billing organization, and acceptance provisions into `organization_id`.
            $table->string('organization_id')->nullable();
            $table->string('prospect_name', 200)->nullable();
            $table->string('prospect_email', 200)->nullable();

            // The selling entity whose branding (logo, accent, legal name) the order form wraps
            // around; null falls back to the default seller / app-level branding defaults.
            $table->string('seller_entity_id')->nullable();

            $table->string('currency', 3);
            // draft · pending_approval · approved · sent · accepted · declined · expired
            $table->string('status', 20)->default('draft');
            $table->date('valid_until')->nullable();

            // The rep who owns the quote (the Cbox ID `sub` + a display name snapshot).
            $table->string('owner_sub')->nullable();
            $table->string('owner_name', 200)->nullable();
            $table->text('notes')->nullable();

            // An order-level promo, applied to the recurring net before tax (engine CouponApplier).
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();

            // --- Contract terms (the committed deal) ---
            $table->unsignedInteger('term_count')->default(12);
            $table->string('term_unit', 8)->default('month');       // day · month · year (engine TermUnit)
            $table->string('billing_interval', 10)->default('monthly'); // monthly · yearly (engine BillingInterval)
            $table->date('start_date')->nullable();
            // The per-billing-period minimum spend the org commits to (engine MinimumCommitment
            // floor, minor units). Null = no commitment.
            $table->unsignedBigInteger('minimum_commitment_minor')->nullable();
            // An optional predetermined ramp: a JSON list of {from_period_index:int, amount_minor:int}
            // steps (must include index 0) → engine RampSchedule. Null = flat recurring.
            $table->json('ramp')->nullable();

            // --- Approval routing ---
            $table->boolean('approval_required')->default(false);
            $table->string('approved_by_sub')->nullable();
            $table->string('approved_by_name', 200)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_by_sub')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            // --- Send / acceptance / provisioning ---
            $table->string('token', 128)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->timestamp('provisioned_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('organization_id');
        });

        Schema::create('quote_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            // plan · custom
            $table->string('type', 10)->default('plan');
            // A catalog plan line: the plan priced in the quote currency at `quantity` units.
            $table->foreignId('plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            // A custom one-off line: the free-text description + a per-unit amount (minor units).
            $table->string('description', 300)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount_minor')->nullable();
            // An optional per-line discount, applied to the line net before tax.
            $table->string('discount_kind', 10)->nullable();     // percent · fixed
            $table->unsignedBigInteger('discount_value')->nullable(); // percent 0–100, or a fixed minor amount
            // Whether this line is part of the recurring subscription (a plan line) or a one-time
            // charge (a custom line defaults to one-off). Derived from `type` but stored so a
            // custom recurring line is expressible.
            $table->boolean('recurring')->default(true);
            $table->timestamps();

            $table->index(['quote_id', 'sort_order']);
        });

        Schema::create('quote_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->unique()->constrained('quotes')->cascadeOnDelete();
            $table->string('signer_name', 200);
            $table->string('signer_email', 200)->nullable();
            $table->boolean('agreed')->default(false);
            // The e-signature provider that captured the acceptance. `null` is the in-house
            // e-sign-by-acceptance default; a real provider (DocuSign, etc.) records its own id.
            $table->string('signature_provider', 40)->default('null');
            $table->string('signature_reference', 200)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            // A snapshot of what was accepted, so the record stands alone even if the catalog moves.
            $table->string('currency', 3);
            $table->unsignedBigInteger('accepted_total_minor')->default(0);
            $table->unsignedBigInteger('committed_value_minor')->default(0);
            $table->timestamp('accepted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_acceptances');
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
    }
};
