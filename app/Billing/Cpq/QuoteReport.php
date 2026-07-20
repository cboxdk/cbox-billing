<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Cpq\Enums\QuoteStatus;
use App\Models\Organization;
use App\Models\Quote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The read model behind the Quotes console: the paginated, searchable, status-tabbed list and the
 * per-status counts the tabs show. Reads only — every mutation goes through the authoring /
 * approval / lifecycle services.
 */
readonly class QuoteReport
{
    private const PER_PAGE = 20;

    /**
     * @return LengthAwarePaginator<int, Quote>
     */
    public function paginate(?string $tab, ?string $search): LengthAwarePaginator
    {
        return Quote::query()
            ->with(['organization', 'subscription'])
            ->when($tab !== null && $tab !== 'all', fn (Builder $query): Builder => $query->tab((string) $tab))
            ->when($search !== null && $search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                // Match on the quote's own fields, or on an organization whose name matches (resolved
                // to ids up front to keep the closure's builder typed to Quote).
                $orgIds = Organization::query()->where('name', 'like', $like)->pluck('id')->all();

                $query->where(function (Builder $inner) use ($like, $orgIds): void {
                    $inner->where('number', 'like', $like)
                        ->orWhere('prospect_name', 'like', $like)
                        ->orWhere('prospect_email', 'like', $like)
                        ->orWhereIn('organization_id', $orgIds);
                });
            })
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Per-status counts for the tab bar, keyed by status value plus `all`.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        // One grouped aggregate instead of 1 (all) + N (per status) COUNT queries.
        $rows = Quote::query()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = ['all' => 0];

        foreach (QuoteStatus::cases() as $status) {
            $count = $rows->get($status->value);
            $counts[$status->value] = is_numeric($count) ? (int) $count : 0;
            $counts['all'] += $counts[$status->value];
        }

        return $counts;
    }

    /** The number of quotes awaiting a deal-desk decision (the approval-queue badge). */
    public function pendingApprovalCount(): int
    {
        return Quote::query()->where('status', QuoteStatus::PendingApproval->value)->count();
    }

    /**
     * The quotes awaiting approval, newest first.
     *
     * @return LengthAwarePaginator<int, Quote>
     */
    public function approvalQueue(): LengthAwarePaginator
    {
        return Quote::query()
            ->with(['organization'])
            ->where('status', QuoteStatus::PendingApproval->value)
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }
}
