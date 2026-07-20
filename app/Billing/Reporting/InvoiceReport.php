<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Support\Initials;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read model for the Invoices screens. Projects real {@see Invoice} rows into the table
 * shape (number, customer, date, amount as engine money, status) and resolves a single
 * invoice with its lines + totals for the detail screen. URL-is-state: the optional
 * status filter narrows the list.
 */
readonly class InvoiceReport
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(?string $status = null, ?int $limit = null): Collection
    {
        $query = Invoice::query()->with('organization')->orderByDesc('issued_at')->orderByDesc('id');

        if ($status !== null && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn (Invoice $invoice): array => $this->row($invoice));
    }

    /**
     * The paginated, optionally searched list for the Invoices screen. Search matches the
     * invoice number or the customer's name; the status tab narrows at the database.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $status = null, ?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Invoice::query()->with('organization')->orderByDesc('issued_at')->orderByDesc('id');

        if ($status !== null && $status !== 'all') {
            $query->where('status', $status);
        }

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $matchingOrgIds = Organization::query()->where('name', 'like', '%'.$search.'%')->pluck('id');
            $query->where(function ($sub) use ($search, $matchingOrgIds): void {
                $sub->where('number', 'like', '%'.$search.'%')
                    ->orWhereIn('organization_id', $matchingOrgIds);
            });
        }

        return $query->paginate($perPage)
            ->through(fn (Invoice $invoice): array => $this->row($invoice))
            ->withQueryString();
    }

    public function find(int $id): ?Invoice
    {
        return Invoice::query()->with(['organization', 'lines'])->find($id);
    }

    /**
     * @return array{all: int, open: int, paid: int, draft: int}
     */
    public function counts(): array
    {
        return [
            'all' => Invoice::query()->count(),
            'open' => Invoice::query()->where('status', InvoiceStatus::Open->value)->count(),
            'paid' => Invoice::query()->where('status', InvoiceStatus::Paid->value)->count(),
            'draft' => Invoice::query()->where('status', InvoiceStatus::Draft->value)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Invoice $invoice): array
    {
        $organization = $invoice->organization;
        $name = $organization !== null ? $organization->name : 'Unknown';

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'org' => $name,
            'ini' => Initials::of($name),
            'minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
            'status' => $invoice->status->value,
            'date' => $invoice->issued_at?->format('Y-m-d') ?? '—',
        ];
    }
}
