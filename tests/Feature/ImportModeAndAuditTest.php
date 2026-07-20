<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Import\Enums\ImportSource;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Models\Invoice;
use App\Models\OperatorAuditEvent;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ImportsFixtures;
use Tests\TestCase;

/**
 * A run is scoped to a plane — importing in test mode stays `livemode=false` and never leaks into
 * live — and is audit-logged as an operator action.
 */
class ImportModeAndAuditTest extends TestCase
{
    use ImportsFixtures;
    use RefreshDatabase;

    public function test_import_into_test_mode_stays_test_and_never_leaks_into_live(): void
    {
        app(BillingContext::class)->setMode(BillingMode::Test);

        [$run] = $this->commitImport(ImportSource::Stripe);

        $this->assertFalse((bool) $run->livemode);

        // The plane-scoped records are test-plane.
        $org = Organization::query()->where('billing_email', 'ann@acme.test')->firstOrFail();
        $this->assertFalse($org->isLive());
        $this->assertFalse((bool) Subscription::query()->firstOrFail()->livemode);
        $this->assertFalse((bool) Invoice::query()->firstOrFail()->livemode);

        // Switching to live must not see any of them.
        app(BillingContext::class)->setMode(BillingMode::Live);
        $this->assertSame(0, Organization::query()->where('billing_email', 'ann@acme.test')->count());
        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_import_is_audit_logged(): void
    {
        $this->commitImport(ImportSource::Stripe);

        $this->assertTrue(
            OperatorAuditEvent::query()->where('action', 'data.imported')->exists(),
            'The import commit should record a data.imported audit event.',
        );
    }
}
