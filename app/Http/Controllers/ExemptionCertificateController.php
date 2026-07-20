<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CurrentUser;
use App\Billing\Tax\Exemptions\Enums\ExemptionStatus;
use App\Billing\Tax\Exemptions\Enums\ExemptionType;
use App\Billing\Tax\Exemptions\ExemptionCertificateService;
use App\Billing\Tax\Exemptions\ExemptionJurisdictions;
use App\Models\Organization;
use App\Models\TaxExemptionCertificate;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Operator tax-exemption management on the customer surface — thin HTTP over the
 * {@see ExemptionCertificateService}. Uploads land `pending`; verify/reject flips the status
 * and records the operator sub; the document download is authz-gated (the certificate must
 * belong to the org in the route, and the file is streamed from the PRIVATE disk — never a
 * public URL). Reads are gated `customers:read`, writes `customers:manage`.
 */
class ExemptionCertificateController extends Controller
{
    public function __construct(private readonly ExemptionCertificateService $certificates) {}

    /** `POST` — capture a new certificate for the org (operator upload). */
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $this->uploadValidator($request)->validate();

        $this->certificates->capture($organization, [
            'jurisdiction' => $request->string('jurisdiction')->toString(),
            'exemption_type' => $request->string('exemption_type')->toString(),
            'certificate_number' => $request->string('certificate_number')->toString(),
            'issued_at' => $request->filled('issued_at') ? $request->string('issued_at')->toString() : null,
            'expires_at' => $request->filled('expires_at') ? $request->string('expires_at')->toString() : null,
            'notes' => $request->filled('notes') ? $request->string('notes')->toString() : null,
        ], $request->file('document'));

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', 'Exemption certificate uploaded — pending review.');
    }

    /** `POST` — verify a pending certificate; it now exempts. */
    public function verify(Organization $organization, TaxExemptionCertificate $certificate, CurrentUser $currentUser): RedirectResponse
    {
        $this->ownedBy($organization, $certificate);

        $this->certificates->verify($certificate, $currentUser->user()?->sub);

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', sprintf('Certificate %s verified — %s is now exempt.', $certificate->certificate_number, $certificate->jurisdiction));
    }

    /** `POST` — reject a pending certificate; it never exempts. */
    public function reject(Request $request, Organization $organization, TaxExemptionCertificate $certificate, CurrentUser $currentUser): RedirectResponse
    {
        $this->ownedBy($organization, $certificate);

        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $this->certificates->reject(
            $certificate,
            $currentUser->user()?->sub,
            $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        return redirect()
            ->route('billing.customers.show', $organization->id)
            ->with('status', sprintf('Certificate %s rejected.', $certificate->certificate_number));
    }

    /**
     * `GET` — download the stored document. Authz-gated: the certificate must belong to the
     * org in the route (a cross-org id is 404, deny-by-default), and the file is streamed from
     * the private disk rather than served from a public URL.
     */
    public function download(Organization $organization, TaxExemptionCertificate $certificate): StreamedResponse
    {
        $this->ownedBy($organization, $certificate);

        $path = $certificate->document_path;

        if ($path === null || ! $this->certificates->disk()->exists($path)) {
            throw new NotFoundHttpException('The certificate document is not available.');
        }

        $disk = $this->certificates->disk();

        return response()->streamDownload(
            static function () use ($disk, $path): void {
                echo $disk->get($path) ?? '';
            },
            $certificate->document_name ?? 'exemption-certificate',
            ['Content-Type' => $certificate->document_mime ?? 'application/octet-stream'],
        );
    }

    /** `GET` — the console-wide tax-exemption overview: who is exempt where. */
    public function overview(Request $request): View
    {
        $search = $this->search($request);

        $certificates = TaxExemptionCertificate::query()
            ->with('organization')
            ->when($search !== null, function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $orgIds = Organization::query()->where('name', 'like', $like)->pluck('id');
                $query->where(function ($q) use ($like, $orgIds): void {
                    $q->where('organization_id', 'like', $like)
                        ->orWhere('jurisdiction', 'like', $like)
                        ->orWhere('certificate_number', 'like', $like)
                        ->orWhereIn('organization_id', $orgIds);
                });
            })
            ->orderByRaw("CASE status WHEN 'verified' THEN 0 WHEN 'pending' THEN 1 WHEN 'expired' THEN 2 ELSE 3 END")
            ->orderBy('jurisdiction')
            ->paginate(25)
            ->withQueryString();

        return view('billing.tax-exemptions', [
            'activeArea' => 'customers',
            'activeNav' => 'tax-exemptions',
            'certificates' => $certificates,
            'search' => $search,
            'verifiedCount' => TaxExemptionCertificate::query()->where('status', ExemptionStatus::Verified)->count(),
            'pendingCount' => TaxExemptionCertificate::query()->where('status', ExemptionStatus::Pending)->count(),
        ]);
    }

    /** The `?q=` filter term, trimmed; blank becomes null. */
    private function search(Request $request): ?string
    {
        $q = $request->query('q');

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }

    /** Deny-by-default cross-org access: a certificate not owned by the org in the route is 404. */
    private function ownedBy(Organization $organization, TaxExemptionCertificate $certificate): void
    {
        if ($certificate->organization_id !== $organization->id) {
            throw new NotFoundHttpException('This certificate is not available.');
        }
    }

    private function uploadValidator(Request $request): ValidatorContract
    {
        $validator = Validator::make($request->all(), [
            'jurisdiction' => ['required', 'string', function (string $attribute, mixed $value, callable $fail): void {
                if (! is_string($value) || ! ExemptionJurisdictions::isValid($value)) {
                    $fail('Choose a supported jurisdiction.');
                }
            }],
            'exemption_type' => ['required', 'string', 'in:'.implode(',', ExemptionType::values())],
            'certificate_number' => ['required', 'string', 'max:64'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'notes' => ['nullable', 'string', 'max:500'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // Per-type certificate-number sanity — reject obvious garbage. The operator's verify
        // step is the real check against an authority.
        $validator->after(function (ValidatorContract $validator) use ($request): void {
            $type = ExemptionType::tryFrom($request->string('exemption_type')->toString());
            $number = $request->string('certificate_number')->toString();

            if ($type !== null && $number !== '' && ! $type->acceptsCertificateNumber($number)) {
                $validator->errors()->add(
                    'certificate_number',
                    sprintf('That does not look like a valid %s certificate number.', $type->label()),
                );
            }
        });

        return $validator;
    }
}
