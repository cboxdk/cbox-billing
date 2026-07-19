<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Models\CboxIdAccessGrant;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read model for the access-grant (RBAC mirror) viewer. It projects the local
 * {@see CboxIdAccessGrant} mirror — which Cbox ID subjects hold which role on which billing
 * org, kept fresh by the provisioning webhooks — into list + per-org shapes. Strictly
 * read-only: Cbox ID owns assignment; this is an eligibility projection, never a writer.
 */
readonly class AccessGrantReport
{
    /**
     * The paginated, optionally searched grant list. Search matches the subject, org id/name,
     * or role.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, int $perPage = 25): LengthAwarePaginator
    {
        $query = CboxIdAccessGrant::query()
            ->with('organization')
            ->orderBy('organization_id')
            ->orderBy('subject')
            ->orderBy('role');

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            // Orgs whose NAME matches, so a name search reaches their grants (grants store the
            // org id, not its name).
            $orgIds = Organization::query()->where('name', 'like', '%'.$search.'%')->pluck('id')->all();

            $query->where(function ($sub) use ($search, $orgIds): void {
                $sub->where('subject', 'like', '%'.$search.'%')
                    ->orWhere('organization_id', 'like', '%'.$search.'%')
                    ->orWhere('role', 'like', '%'.$search.'%')
                    ->orWhereIn('organization_id', $orgIds);
            });
        }

        return $query->paginate($perPage)
            ->through(fn (CboxIdAccessGrant $grant): array => $this->row($grant))
            ->withQueryString();
    }

    /**
     * Every grant for one organization, newest-authoritative first.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrganization(string $organizationId): array
    {
        return array_values(CboxIdAccessGrant::query()
            ->where('organization_id', $organizationId)
            ->orderBy('subject')
            ->orderBy('role')
            ->get()
            ->map(fn (CboxIdAccessGrant $grant): array => $this->row($grant))
            ->all());
    }

    public function total(): int
    {
        return CboxIdAccessGrant::query()->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CboxIdAccessGrant $grant): array
    {
        $hasRole = $grant->role !== CboxIdAccessGrant::NO_ROLE;
        $organization = $grant->organization;

        return [
            'id' => $grant->id,
            'org_id' => $grant->organization_id,
            'org' => $organization !== null ? $organization->name : $grant->organization_id,
            'subject' => $grant->subject,
            'role' => $hasRole ? $grant->role : null,
            'kind' => $hasRole ? 'role' : 'membership',
            'environment' => $grant->environment_key,
            'updated' => $grant->updated_at?->format('Y-m-d H:i') ?? '—',
        ];
    }
}
