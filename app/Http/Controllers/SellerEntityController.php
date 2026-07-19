<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Seller\Exceptions\SellerActionDenied;
use App\Billing\Seller\SellerAuthoring;
use App\Models\SellerEntity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Selling-entity authoring (Wave 4) — thin HTTP over {@see SellerAuthoring}. Create / edit
 * the legal seller of record (name, registration number, establishment, currency, invoice
 * prefix) and its per-jurisdiction tax registrations, make one the default, archive, or
 * delete. Delete is guarded server-side: a seller whose prefix still numbers invoices is
 * archived, never hard-deleted, so the legal record is never orphaned. Gated `settings:manage`.
 */
class SellerEntityController extends Controller
{
    public function create(): View
    {
        return $this->form(null);
    }

    public function edit(SellerEntity $sellerEntity): View
    {
        return $this->form($sellerEntity);
    }

    public function store(Request $request, SellerAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request, null);

        try {
            $authoring->create($data);
        } catch (SellerActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.settings', ['tab' => 'sellers'])
            ->with('status', sprintf('Selling entity “%s” created.', $data['legal_name']));
    }

    public function update(Request $request, SellerEntity $sellerEntity, SellerAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request, $sellerEntity);

        $authoring->update($sellerEntity, $data);

        return redirect()
            ->route('billing.settings', ['tab' => 'sellers'])
            ->with('status', sprintf('Selling entity “%s” updated.', $sellerEntity->legal_name));
    }

    public function setDefault(SellerEntity $sellerEntity, SellerAuthoring $authoring): RedirectResponse
    {
        $authoring->setDefault($sellerEntity);

        return back()->with('status', sprintf('“%s” is now the default selling entity.', $sellerEntity->legal_name));
    }

    public function archive(SellerEntity $sellerEntity, SellerAuthoring $authoring): RedirectResponse
    {
        $authoring->archive($sellerEntity);

        return back()->with('status', sprintf('Selling entity “%s” archived.', $sellerEntity->legal_name));
    }

    public function unarchive(SellerEntity $sellerEntity, SellerAuthoring $authoring): RedirectResponse
    {
        $authoring->unarchive($sellerEntity);

        return back()->with('status', sprintf('Selling entity “%s” reinstated.', $sellerEntity->legal_name));
    }

    public function destroy(SellerEntity $sellerEntity, SellerAuthoring $authoring): RedirectResponse
    {
        $name = $sellerEntity->legal_name;

        try {
            $authoring->delete($sellerEntity);
        } catch (SellerActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.settings', ['tab' => 'sellers'])
            ->with('status', sprintf('Selling entity “%s” deleted.', $name));
    }

    private function form(?SellerEntity $seller): View
    {
        $seller?->loadMissing('taxRegistrations');

        return view('billing.settings.seller-form', [
            'activeArea' => 'settings',
            'activeNav' => 'sellers',
            'seller' => $seller,
        ]);
    }

    /**
     * @return array{id: string, legal_name: string, registration_number: string, establishment: string, currency: string, invoice_prefix: string, is_default: bool, registrations: list<array{country: string, number: string, subdivision: ?string, scheme: ?string}>, branding: array<string, string|null>}
     */
    private function validated(Request $request, ?SellerEntity $seller): array
    {
        $rules = [
            'legal_name' => ['required', 'string', 'max:190'],
            'registration_number' => ['required', 'string', 'max:64'],
            'establishment' => ['required', 'string', 'size:2', 'alpha'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'invoice_prefix' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'is_default' => ['nullable', 'boolean'],
            'registrations' => ['nullable', 'array'],
            'registrations.*.country' => ['nullable', 'string', 'size:2', 'alpha'],
            'registrations.*.number' => ['nullable', 'string', 'max:64'],
            'registrations.*.subdivision' => ['nullable', 'string', 'max:16'],
            'registrations.*.scheme' => ['nullable', 'string', 'max:32'],
            // Transactional-email branding (all optional; blank falls back to the app defaults).
            'brand_color' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'from_name' => ['nullable', 'string', 'max:190'],
            'from_email' => ['nullable', 'email', 'max:254'],
            'reply_to' => ['nullable', 'email', 'max:254'],
            'footer_address' => ['nullable', 'string', 'max:500'],
            'support_url' => ['nullable', 'url', 'max:500'],
            'support_email' => ['nullable', 'email', 'max:254'],
            'default_locale' => ['nullable', 'string', 'max:12'],
        ];

        if ($seller === null) {
            $rules['id'] = ['required', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/'];
        }

        $request->validate($rules);

        return [
            'id' => $seller !== null ? $seller->id : $request->string('id')->toString(),
            'legal_name' => $request->string('legal_name')->toString(),
            'registration_number' => $request->string('registration_number')->toString(),
            'establishment' => strtoupper($request->string('establishment')->toString()),
            'currency' => strtoupper($request->string('currency')->toString()),
            'invoice_prefix' => $request->string('invoice_prefix')->toString(),
            'is_default' => $request->boolean('is_default'),
            'registrations' => $this->registrations($request),
            'branding' => $this->branding($request),
        ];
    }

    /**
     * The optional per-seller email-branding fields. Blank values are normalised to null by the
     * authoring service, so an unset field falls back to the app-level default.
     *
     * @return array<string, string|null>
     */
    private function branding(Request $request): array
    {
        $fields = ['brand_color', 'logo_url', 'from_name', 'from_email', 'reply_to', 'footer_address', 'support_url', 'support_email', 'default_locale'];
        $branding = [];

        foreach ($fields as $field) {
            $value = $request->string($field)->toString();
            $branding[$field] = $value !== '' ? $value : null;
        }

        return $branding;
    }

    /**
     * @return list<array{country: string, number: string, subdivision: ?string, scheme: ?string}>
     */
    private function registrations(Request $request): array
    {
        $rows = $request->input('registrations');
        $registrations = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $country = is_string($row['country'] ?? null) ? strtoupper(trim($row['country'])) : '';
            $number = is_string($row['number'] ?? null) ? trim($row['number']) : '';

            // A registration needs both a country and a number to be meaningful; skip blanks.
            if ($country === '' || $number === '') {
                continue;
            }

            $subdivision = is_string($row['subdivision'] ?? null) && trim($row['subdivision']) !== '' ? trim($row['subdivision']) : null;
            $scheme = is_string($row['scheme'] ?? null) && trim($row['scheme']) !== '' ? trim($row['scheme']) : null;

            $registrations[] = [
                'country' => $country,
                'number' => $number,
                'subdivision' => $subdivision,
                'scheme' => $scheme,
            ];
        }

        return $registrations;
    }
}
