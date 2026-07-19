<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Licensing\Contracts\IssuesLicenses;
use App\Billing\Licensing\Exceptions\LicensingException;
use App\Billing\Licensing\LicenseReport;
use App\Models\Organization;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The on-prem licensing provider console — thin HTTP over the {@see LicenseReport} read
 * model and the {@see IssuesLicenses} service. It lists issued licenses with their derived
 * status, drives the issue / renew / revoke actions, and shows the distribution panel (the
 * public key + current signed revocation list) for air-gapped hand-off. No logic lives
 * here; the service mints and the report projects.
 */
class LicenseController extends Controller
{
    public function index(Request $request, LicenseReport $report, Config $config): View
    {
        $q = $request->query('q');
        $search = is_string($q) && trim($q) !== '' ? trim($q) : null;

        return view('billing.licenses', [
            'activeArea' => 'licenses',
            'activeNav' => 'issued',
            'search' => $search,
            'licenses' => $report->paginate($search),
            'counts' => $report->counts(),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'licensablePlans' => $this->licensablePlans($config),
            'signingConfigured' => $report->settings()['signing_key_configured'],
        ]);
    }

    public function settings(LicenseReport $report): View
    {
        return view('billing.license-settings', [
            'activeArea' => 'licenses',
            'activeNav' => 'distribution',
            'settings' => $report->settings(),
        ]);
    }

    public function issue(Request $request, IssuesLicenses $licenses): RedirectResponse
    {
        $request->validate([
            'customer_id' => ['required', 'string'],
            'plan' => ['required', 'string'],
            'deployment_id' => ['nullable', 'string'],
            'licensed_domain' => ['nullable', 'string'],
        ]);

        if (! Organization::query()->whereKey($request->string('customer_id')->toString())->exists()) {
            return back()->with('license_error', 'Unknown organization.');
        }

        try {
            $license = $licenses->issue(
                customerId: $request->string('customer_id')->toString(),
                planId: $request->string('plan')->toString(),
                deploymentId: $this->nullableInput($request, 'deployment_id'),
                licensedDomain: $this->nullableInput($request, 'licensed_domain'),
            );
        } catch (LicensingException $e) {
            return back()->with('license_error', $e->getMessage());
        }

        return redirect()
            ->route('billing.licenses')
            ->with('issued_license', $this->artifact($license));
    }

    public function renew(Request $request, string $id, IssuesLicenses $licenses): RedirectResponse
    {
        $request->validate(['expires_at' => ['nullable', 'date']]);

        try {
            $license = $licenses->renew($id, $this->nullableDate($request, 'expires_at'));
        } catch (LicensingException $e) {
            return back()->with('license_error', $e->getMessage());
        }

        return redirect()
            ->route('billing.licenses')
            ->with('issued_license', $this->artifact($license));
    }

    public function revoke(Request $request, string $id, IssuesLicenses $licenses): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string']]);

        $licenses->revoke($id, $this->nullableInput($request, 'reason'));

        return redirect()
            ->route('billing.licenses')
            ->with('license_notice', 'License '.$id.' revoked. It will be refused once the new revocation list is pulled.');
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function licensablePlans(Config $config): array
    {
        $profiles = $config->get('billing.licensing.profiles', []);
        $plans = [];

        foreach (is_array($profiles) ? array_keys($profiles) : [] as $key) {
            $key = (string) $key;
            $plans[] = ['key' => $key, 'label' => $key];
        }

        return $plans;
    }

    /**
     * @return array{id: string, key: string, deployment_id: string, plan: string, expires_at: string}
     */
    private function artifact(IssuedLicense $license): array
    {
        return [
            'id' => $license->id,
            'key' => $license->key,
            'deployment_id' => $license->deploymentId,
            'plan' => $license->plan,
            'expires_at' => $license->expiresAt->format('Y-m-d'),
        ];
    }

    private function nullableInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableDate(Request $request, string $key): ?DateTimeImmutable
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
