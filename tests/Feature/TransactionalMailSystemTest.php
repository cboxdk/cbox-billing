<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Notifications\BillingNotifier;
use App\Billing\Notifications\Contracts\ComposesTransactionalMail;
use App\Billing\Notifications\Contracts\ResolvesMailTemplates;
use App\Billing\Notifications\MailEventType;
use App\Billing\Notifications\Rendering\TemplateSource;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\TestMode\CapturedNotifications;
use App\Mail\InvoiceIssuedMail;
use App\Models\MailTemplate;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SellerEntity;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The brandable + localized transactional-email system end to end: the layered resolution
 * chain, the branded/localized render, the sandbox (escaped variable values), the console
 * editor / live preview / reset / test-send surfaces, and the permission gate on writes.
 */
class TransactionalMailSystemTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $session = ['auth.user' => [
        'sub' => 'demo|op', 'name' => 'Op', 'email' => 'op@example.test', 'org' => 'Cbox Systems', 'picture' => null,
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->brandedSeller();
    }

    // --- Resolution chain --------------------------------------------------------------------

    public function test_resolution_chain_prefers_seller_then_account_then_shipped_default(): void
    {
        $resolver = app(ResolvesMailTemplates::class);
        $event = MailEventType::InvoiceIssued;

        // Nothing overridden → the shipped default in the requested locale.
        $this->assertSame(TemplateSource::ShippedLocale, $resolver->resolve($event, 'en', 'cbox-dk')->source);

        // Account-wide override wins over the shipped default.
        MailTemplate::query()->create(['event_type' => $event->value, 'locale' => 'en', 'seller_entity_id' => null, 'subject' => 'ACCOUNT {{ invoice_number }}', 'body' => 'account body']);
        $this->assertSame(TemplateSource::GlobalLocale, $resolver->resolve($event, 'en', 'cbox-dk')->source);

        // A seller-scoped override wins over the account-wide one.
        MailTemplate::query()->create(['event_type' => $event->value, 'locale' => 'en', 'seller_entity_id' => 'cbox-dk', 'subject' => 'SELLER {{ invoice_number }}', 'body' => 'seller body']);
        $resolved = $resolver->resolve($event, 'en', 'cbox-dk');
        $this->assertSame(TemplateSource::SellerLocale, $resolved->source);
        $this->assertSame('SELLER {{ invoice_number }}', $resolved->subject);
    }

    public function test_resolution_never_dead_ends_for_an_unshipped_locale(): void
    {
        $resolved = app(ResolvesMailTemplates::class)->resolve(MailEventType::InvoiceIssued, 'de', 'cbox-dk');

        // No German default ships, so it falls all the way to the fallback-locale shipped default.
        $this->assertSame(TemplateSource::ShippedFallback, $resolved->source);
        $this->assertNotSame('', $resolved->body);
        $this->assertSame('en', $resolved->locale);
    }

    public function test_the_fallback_locale_override_is_used_when_the_requested_locale_has_none(): void
    {
        // Only an EN account override exists; a request for DA falls back to it before the shipped DA default.
        MailTemplate::query()->create(['event_type' => 'invoice_issued', 'locale' => 'en', 'seller_entity_id' => null, 'subject' => 'EN ONLY', 'body' => 'x']);

        $resolved = app(ResolvesMailTemplates::class)->resolve(MailEventType::InvoiceIssued, 'da', null);

        $this->assertSame(TemplateSource::GlobalFallback, $resolved->source);
        $this->assertSame('EN ONLY', $resolved->subject);
    }

    // --- Branding + localization -------------------------------------------------------------

    public function test_compose_applies_seller_branding_and_the_requested_locale(): void
    {
        $composer = app(ComposesTransactionalMail::class);

        $da = $composer->compose(MailEventType::InvoiceIssued, MailEventType::InvoiceIssued->sampleVariables(), 'cbox-dk', 'da');
        $this->assertStringContainsString('Faktura', $da->subject);
        $this->assertStringContainsString('Faktura', $da->html);
        $this->assertStringContainsString('#ff8800', $da->html);            // seller accent colour
        $this->assertStringContainsString('Cbox ApS · DK12345678', $da->html); // legal footer line
        $this->assertStringContainsString('Havnegade 1', $da->html);        // footer address
        $this->assertSame('Cbox Billing Team', $da->fromName);
        $this->assertSame('billing@cbox.test', $da->fromEmail);

        $en = $composer->compose(MailEventType::InvoiceIssued, MailEventType::InvoiceIssued->sampleVariables(), 'cbox-dk', 'en');
        $this->assertStringContainsString('Invoice', $en->subject);
        $this->assertStringNotContainsString('Faktura', $en->html);
    }

    public function test_a_hostile_variable_value_is_escaped_not_executed(): void
    {
        $composer = app(ComposesTransactionalMail::class);

        $variables = MailEventType::InvoiceIssued->sampleVariables();
        $variables['organization_name'] = '<script>alert(document.cookie)</script>';

        $rendered = $composer->compose(MailEventType::InvoiceIssued, $variables, 'cbox-dk', 'en');

        $this->assertStringNotContainsString('<script>alert', $rendered->html);
        $this->assertStringContainsString('&lt;script&gt;', $rendered->html);
    }

    public function test_lifecycle_invoice_mail_renders_branded_and_localized_for_a_da_customer(): void
    {
        Mail::fake();
        $subscription = $this->subscribeOrg('org_da', 'starter', 'da');

        app(GeneratesInvoices::class)->generate($subscription);

        $mail = Mail::queued(InvoiceIssuedMail::class)->first();
        $this->assertInstanceOf(InvoiceIssuedMail::class, $mail);
        $this->assertSame('da', $mail->mailLocale);
        // The amount was formatted in the customer's locale (Danish grouping: comma decimal).
        $this->assertStringContainsString(',', $mail->amountFormatted);

        $html = $mail->render();
        $this->assertStringContainsString('Faktura', $html);
        $this->assertStringContainsString('#ff8800', $html);
    }

    // --- Console surfaces --------------------------------------------------------------------

    public function test_the_index_lists_every_event_with_its_source(): void
    {
        $this->withSession($this->session)->get('/settings/emails')
            ->assertOk()
            ->assertSee('Invoice issued')
            ->assertSee('License delivered')
            ->assertSee('Default');
    }

    public function test_the_editor_shows_the_variable_reference(): void
    {
        $this->withSession($this->session)->get('/settings/emails/invoice_issued/edit?locale=en&seller=')
            ->assertOk()
            ->assertSee('Available variables')
            ->assertSee('invoice_number');
    }

    public function test_saving_an_override_persists_and_is_then_resolved(): void
    {
        $this->withSession($this->session)->put('/settings/emails/invoice_issued', [
            'locale' => 'en', 'seller' => '', 'subject' => 'Custom {{ invoice_number }}', 'body' => 'Body for {{ organization_name }}',
        ])->assertRedirect();

        $this->assertDatabaseHas('mail_templates', ['event_type' => 'invoice_issued', 'locale' => 'en', 'seller_entity_id' => null, 'subject' => 'Custom {{ invoice_number }}']);

        $resolved = app(ResolvesMailTemplates::class)->resolve(MailEventType::InvoiceIssued, 'en', null);
        $this->assertSame(TemplateSource::GlobalLocale, $resolved->source);

        $this->withSession($this->session)->get('/settings/emails?seller=')->assertOk()->assertSee('Overridden');
    }

    public function test_reset_restores_the_shipped_default(): void
    {
        MailTemplate::query()->create(['event_type' => 'invoice_issued', 'locale' => 'en', 'seller_entity_id' => null, 'subject' => 'Custom', 'body' => 'b']);

        $this->withSession($this->session)->post('/settings/emails/invoice_issued/reset', ['locale' => 'en', 'seller' => ''])->assertRedirect();

        $this->assertDatabaseMissing('mail_templates', ['event_type' => 'invoice_issued', 'locale' => 'en', 'seller_entity_id' => null]);
        $this->assertSame(TemplateSource::ShippedLocale, app(ResolvesMailTemplates::class)->resolve(MailEventType::InvoiceIssued, 'en', null)->source);
    }

    public function test_the_get_preview_returns_the_branded_html_for_the_chosen_seller_and_locale(): void
    {
        $response = $this->withSession($this->session)->get('/settings/emails/invoice_issued/preview?locale=da&seller=cbox-dk');

        $response->assertOk();
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $response->assertSee('Faktura', false);
        $response->assertSee('#ff8800', false);
    }

    public function test_the_post_preview_renders_the_unsaved_draft(): void
    {
        $response = $this->withSession($this->session)->post('/settings/emails/invoice_issued/preview', [
            'locale' => 'en', 'seller' => 'cbox-dk', 'subject' => 'Draft subject', 'body' => 'DRAFT-MARKER for {{ organization_name }}',
        ]);

        $response->assertOk();
        $response->assertSee('DRAFT-MARKER for Northwind Traders', false);
    }

    // --- Test send ---------------------------------------------------------------------------

    public function test_test_send_is_captured_not_delivered_in_test_mode(): void
    {
        app(BillingContext::class)->setMode(BillingMode::Test);

        $captured = app(BillingNotifier::class)->sendTest(MailEventType::InvoiceIssued, 'cbox-dk', 'en', 'sandbox@example.test');

        $this->assertTrue($captured);
        $this->assertSame(1, app(CapturedNotifications::class)->count());
    }

    public function test_test_send_is_delivered_not_captured_in_live_mode(): void
    {
        app(BillingContext::class)->setMode(BillingMode::Live);
        Mail::fake();

        $captured = app(BillingNotifier::class)->sendTest(MailEventType::InvoiceIssued, 'cbox-dk', 'en', 'real@example.test');

        $this->assertFalse($captured);
        $this->assertSame(0, app(CapturedNotifications::class)->count());
    }

    // --- Permission gate ---------------------------------------------------------------------

    public function test_a_write_route_is_gated_by_settings_manage(): void
    {
        config()->set('billing.rbac.enforce', true);

        // A read-only holder cannot save an override.
        $this->withSession($this->operatorWith(['settings:read']))
            ->put('/settings/emails/invoice_issued', ['locale' => 'en', 'seller' => '', 'subject' => 'x', 'body' => 'y'])
            ->assertStatus(403);

        // The manage holder clears the gate (the action then redirects).
        $this->withSession($this->operatorWith(['settings:manage']))
            ->put('/settings/emails/invoice_issued', ['locale' => 'en', 'seller' => '', 'subject' => 'x', 'body' => 'y'])
            ->assertStatus(302);
    }

    // --- Helpers -----------------------------------------------------------------------------

    private function brandedSeller(): SellerEntity
    {
        return SellerEntity::query()->create([
            'id' => 'cbox-dk',
            'legal_name' => 'Cbox ApS',
            'registration_number' => 'DK12345678',
            'establishment' => 'DK',
            'currency' => 'DKK',
            'invoice_prefix' => 'CBOX-DK',
            'is_default' => true,
            'brand_color' => '#ff8800',
            'from_name' => 'Cbox Billing Team',
            'from_email' => 'billing@cbox.test',
            'footer_address' => 'Havnegade 1, 1050 Copenhagen',
            'default_locale' => 'en',
        ]);
    }

    private function subscribeOrg(string $id, string $planKey, string $locale): Subscription
    {
        $organization = Organization::query()->create([
            'id' => $id,
            'name' => ucfirst($id),
            'locale' => $locale,
            'billing_country' => 'DK',
            'billing_email' => 'billing@'.$id.'.test',
        ]);

        $plan = Plan::query()->with(['prices', 'product'])->where('key', $planKey)->firstOrFail();

        return app(SubscribesOrganizations::class)->subscribe($organization, $plan)->refresh()->load('organization', 'plan');
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    private function operatorWith(array $permissions): array
    {
        return ['auth.user' => [
            'sub' => 'demo|op', 'name' => 'Op', 'email' => 'op@example.test', 'org' => 'Cbox Systems', 'picture' => null, 'permissions' => $permissions,
        ]];
    }
}
