<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Jobs\IssueOrgLicenseJob;
use App\Jobs\IssueSubscriptionInvoiceJob;
use App\Jobs\ReconcileOrgUsageJob;
use App\Jobs\RenewSubscriptionJob;
use App\Jobs\RunOrgDunningJob;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The scheduled billing passes fan out to per-tenant queued jobs rather than running every
 * tenant inline. Each command is a thin dispatcher: it enqueues one job per subscription (or
 * per org) so a single tenant's failure retries in isolation and never stalls or aborts the
 * batch.
 */
class QueuedBillingJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_invoice_pass_dispatches_one_job_per_subscription(): void
    {
        $this->subscribe('org_a');
        $this->subscribe('org_b');
        $this->subscribe('org_c');

        Queue::fake();
        Artisan::call('billing:invoice');

        Queue::assertPushed(IssueSubscriptionInvoiceJob::class, 3);
    }

    public function test_renew_pass_dispatches_one_job_per_subscription(): void
    {
        $this->subscribe('org_a');
        $this->subscribe('org_b');

        Queue::fake();
        Artisan::call('billing:renew');

        Queue::assertPushed(RenewSubscriptionJob::class, 2);
    }

    public function test_dunning_pass_dispatches_one_job_per_organization(): void
    {
        Organization::query()->create(['id' => 'org_x', 'name' => 'X', 'billing_country' => 'DK']);
        Organization::query()->create(['id' => 'org_y', 'name' => 'Y', 'billing_country' => 'DK']);

        Queue::fake();
        Artisan::call('billing:dunning');

        Queue::assertPushed(RunOrgDunningJob::class, 2);
    }

    public function test_reconcile_pass_dispatches_one_job_per_active_org(): void
    {
        $this->subscribe('org_a');
        $this->subscribe('org_b');

        Queue::fake();
        Artisan::call('billing:reconcile-active');

        Queue::assertPushed(ReconcileOrgUsageJob::class, 2);
    }

    public function test_license_pass_dispatches_one_job_per_subscription(): void
    {
        $this->subscribe('org_a');
        $this->subscribe('org_b');

        Queue::fake();
        Artisan::call('billing:issue-licenses');

        Queue::assertPushed(IssueOrgLicenseJob::class, 2);
    }

    public function test_the_dispatcher_enqueues_every_tenant_even_a_broken_one(): void
    {
        // Two healthy orgs (billable) and one tax-pending org (no billing address).
        $this->subscribe('org_ok1');
        $this->subscribe('org_ok2');
        $this->subscribe('org_pending', country: null);

        // The dispatcher enqueues a job for every tenant regardless of any one's data — a
        // problematic tenant can never abort the fan-out.
        Queue::fake();
        Artisan::call('billing:invoice');
        Queue::assertPushed(IssueSubscriptionInvoiceJob::class, 3);
    }

    public function test_a_failing_tenant_does_not_abort_the_healthy_ones(): void
    {
        // Two healthy orgs and one tax-pending org, run on the real (sync) queue: the
        // tax-pending tenant's job skips itself without throwing, and the healthy tenants
        // each still produce their invoice — one bad tenant never aborts the batch.
        $this->subscribe('org_ok1');
        $this->subscribe('org_ok2');
        $this->subscribe('org_pending', country: null);

        Artisan::call('billing:invoice');

        $this->assertSame(1, Invoice::query()->where('organization_id', 'org_ok1')->count());
        $this->assertSame(1, Invoice::query()->where('organization_id', 'org_ok2')->count());
        $this->assertSame(0, Invoice::query()->where('organization_id', 'org_pending')->count());
    }

    private function subscribe(string $id, ?string $country = 'DK'): Subscription
    {
        $organization = Organization::query()->create([
            'id' => $id, 'name' => ucfirst($id), 'billing_country' => $country, 'billing_email' => $id.'@test.test',
        ]);

        return app(SubscribesOrganizations::class)->subscribe($organization, Plan::query()->with(['prices', 'product'])->where('key', 'starter')->firstOrFail());
    }
}
