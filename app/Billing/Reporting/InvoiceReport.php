<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Support\Initials;
use App\Models\Invoice;
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
            'open' => Invoice::query()->where('status', 'open')->count(),
            'paid' => Invoice::query()->where('status', 'paid')->count(),
            'draft' => Invoice::query()->where('status', 'draft')->count(),
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
            'status' => $invoice->status,
            'date' => $invoice->issued_at?->format('Y-m-d') ?? '—',
        ];
    }
}
