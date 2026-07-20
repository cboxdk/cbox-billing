<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Cpq\Enums\QuoteLineType;
use App\Billing\Cpq\Enums\QuoteStatus;
use App\Models\OperatorAuditEvent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The public, hosted order form: it renders seller-branded and fully self-contained (CSP-safe, no
 * external hosts) with the right totals; the opaque token isolates one quote from another;
 * acceptance is an e-signature-by-acceptance that records the immutable acceptance and provisions
 * EXACTLY ONE subscription (idempotent on re-accept); decline and expire transition correctly; and
 * acceptance is audit-logged.
 */
class QuoteOrderFormTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        $product = Product::query()->create(['key' => 'cpq-prod', 'name' => 'CPQ Product', 'shape' => 'recurring']);
        $plan = Plan::query()->create(['product_id' => $product->id, 'key' => 'cpq-seat', 'name' => 'Seat Plan', 'interval' => 'month', 'active' => true]);
        PlanPrice::query()->create(['plan_id' => $plan->id, 'currency' => 'DKK', 'price_minor' => 20000, 'pricing_model' => 'per_unit']);

        return $plan;
    }

    private function sentQuote(string $token = 'tok-alpha', int $seats = 10): Quote
    {
        $plan = $this->plan();
        $org = Organization::query()->create([
            'id' => 'org_of', 'name' => 'Order Buyer ApS', 'billing_email' => 'ap@of.example', 'billing_country' => 'DK',
        ]);

        $quote = Quote::query()->create([
            'number' => 'Q-OF001', 'organization_id' => $org->id, 'status' => QuoteStatus::Sent,
            'currency' => 'DKK', 'term_count' => 12, 'term_unit' => 'month', 'billing_interval' => 'monthly',
            'minimum_commitment_minor' => 300000, 'token' => $token, 'sent_at' => Carbon::now(),
            'valid_until' => Carbon::now()->addDays(14),
        ]);
        $quote->lines()->create(['sort_order' => 0, 'type' => QuoteLineType::Plan, 'plan_id' => $plan->id, 'quantity' => $seats, 'recurring' => true]);
        $quote->lines()->create(['sort_order' => 1, 'type' => QuoteLineType::Custom, 'description' => 'Onboarding', 'quantity' => 1, 'unit_amount_minor' => 50000, 'recurring' => false]);

        return $quote;
    }

    public function test_the_order_form_renders_branded_and_self_contained(): void
    {
        $quote = $this->sentQuote();

        $response = $this->get('/quote/'.$quote->token)->assertOk();
        $html = $response->getContent();
        $this->assertIsString($html);

        // The right figures: first-invoice gross (2500.00 net + 25% = 3125.00) and the committed value.
        $this->assertStringContainsString('Order form Q-OF001', $html);
        $this->assertStringContainsString('DKK 3.125,00', $html);   // due at start
        $this->assertStringContainsString('DKK 36.000,00', $html);  // committed contract value

        // Self-contained / CSP-safe — no external stylesheet, script, font or host, no @import.
        $this->assertDoesNotMatchRegularExpression('/<link\b/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/(src|href)\s*=\s*["\']https?:\/\//i', $html);
        $this->assertDoesNotMatchRegularExpression('/@import/i', $html);
        $this->assertDoesNotMatchRegularExpression('/url\(\s*https?:/i', $html);
    }

    public function test_an_unknown_token_is_isolated_with_a_404(): void
    {
        $this->sentQuote('tok-real');

        $this->get('/quote/tok-does-not-exist')->assertNotFound();
    }

    public function test_acceptance_records_the_signature_and_provisions_exactly_one_subscription(): void
    {
        $quote = $this->sentQuote();
        $before = Subscription::query()->count();

        $this->post('/quote/'.$quote->token.'/accept', [
            'signer_name' => 'Jane Buyer',
            'signer_email' => 'jane@of.example',
            'agree' => '1',
        ])->assertRedirect(route('quote.show', $quote->token));

        $quote->refresh();
        $this->assertSame(QuoteStatus::Accepted, $quote->status);
        $this->assertNotNull($quote->subscription_id);
        $this->assertNotNull($quote->provisioned_at);

        // Exactly one new subscription, on the committed plan + seats.
        $this->assertSame($before + 1, Subscription::query()->count());
        $subscription = Subscription::query()->findOrFail($quote->subscription_id);
        $this->assertSame('org_of', $subscription->organization_id);
        $this->assertSame(10, $subscription->seats);

        // The immutable acceptance record captured the signer + the committed value snapshot.
        $acceptance = $quote->acceptance()->firstOrFail();
        $this->assertSame('Jane Buyer', $acceptance->signer_name);
        $this->assertTrue($acceptance->agreed);
        $this->assertSame('null', $acceptance->signature_provider);
        $this->assertSame(3_600_000, $acceptance->committed_value_minor);

        // Audit-logged.
        $this->assertSame(1, OperatorAuditEvent::query()->where('action', 'quote.accepted')->count());
    }

    public function test_re_accepting_is_idempotent(): void
    {
        $quote = $this->sentQuote();

        $this->post('/quote/'.$quote->token.'/accept', ['signer_name' => 'Jane Buyer', 'agree' => '1'])->assertRedirect();
        $countAfterFirst = Subscription::query()->count();
        $subId = $quote->refresh()->subscription_id;

        // A second acceptance provisions no second subscription and keeps the single record.
        $this->post('/quote/'.$quote->token.'/accept', ['signer_name' => 'Someone Else', 'agree' => '1'])->assertRedirect();
        $quote->refresh();

        $this->assertSame($countAfterFirst, Subscription::query()->count());
        $this->assertSame($subId, $quote->subscription_id);
        $this->assertSame(1, $quote->acceptance()->count());
        $this->assertSame('Jane Buyer', $quote->acceptance()->firstOrFail()->signer_name);
    }

    public function test_acceptance_requires_the_agreement_box(): void
    {
        $quote = $this->sentQuote();

        // No `agree` → validation refuses; nothing is recorded or provisioned.
        $this->post('/quote/'.$quote->token.'/accept', ['signer_name' => 'Jane Buyer'])
            ->assertSessionHasErrors('agree');

        $this->assertSame(QuoteStatus::Sent, $quote->refresh()->status);
        $this->assertNull($quote->subscription_id);
        $this->assertSame(0, $quote->acceptance()->count());
    }

    public function test_decline_transitions_the_quote(): void
    {
        $quote = $this->sentQuote();

        $this->post('/quote/'.$quote->token.'/decline', ['reason' => 'Budget cut'])->assertRedirect();

        $quote->refresh();
        $this->assertSame(QuoteStatus::Declined, $quote->status);
        $this->assertSame('Budget cut', $quote->decline_reason);
        $this->assertSame(1, OperatorAuditEvent::query()->where('action', 'quote.declined')->count());
    }

    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $quote = $this->sentQuote();
        $quote->update(['valid_until' => Carbon::now()->subDay()]);

        $this->post('/quote/'.$quote->token.'/accept', ['signer_name' => 'Jane Buyer', 'agree' => '1'])
            ->assertRedirect();

        $quote->refresh();
        $this->assertSame(QuoteStatus::Sent, $quote->status);
        $this->assertNull($quote->subscription_id);
    }
}
