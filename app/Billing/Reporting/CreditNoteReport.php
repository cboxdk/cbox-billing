<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Support\Initials;
use App\Models\CreditNote;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read model for the Credit notes screens (Wave 3). Projects real {@see CreditNote} rows
 * (issued by the engine refund/adjustment flow) into the table shape and resolves one
 * note with its lines for the detail screen. Search matches the credit-note number, the
 * referenced invoice number, or the customer's name.
 */
readonly class CreditNoteReport
{
    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = CreditNote::query()->with('organization')->orderByDesc('issued_at')->orderByDesc('id');

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $matchingOrgIds = Organization::query()->where('name', 'like', '%'.$search.'%')->pluck('id');
            $query->where(function ($sub) use ($search, $matchingOrgIds): void {
                $sub->where('number', 'like', '%'.$search.'%')
                    ->orWhere('invoice_number', 'like', '%'.$search.'%')
                    ->orWhereIn('organization_id', $matchingOrgIds);
            });
        }

        return $query->paginate($perPage)
            ->through(fn (CreditNote $note): array => $this->row($note))
            ->withQueryString();
    }

    public function find(int $id): ?CreditNote
    {
        return CreditNote::query()->with(['organization', 'lines', 'invoice'])->find($id);
    }

    /** The total number of credit notes — for the nav count. */
    public function total(): int
    {
        return CreditNote::query()->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CreditNote $note): array
    {
        $organization = $note->organization;
        $name = $organization !== null ? $organization->name : 'Unknown';

        return [
            'id' => $note->id,
            'number' => $note->number,
            'invoice_number' => $note->invoice_number,
            'org' => $name,
            'ini' => Initials::of($name),
            'minor' => $note->gross_minor,
            'currency' => $note->currency,
            'reason' => $note->reason,
            'kind' => $note->kind,
            'date' => $note->issued_at->format('Y-m-d'),
        ];
    }
}
