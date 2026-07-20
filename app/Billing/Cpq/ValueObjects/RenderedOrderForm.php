<?php

declare(strict_types=1);

namespace App\Billing\Cpq\ValueObjects;

use App\Billing\Cpq\Enums\QuoteStatus;
use App\Billing\Notifications\Branding\SellerBranding;
use App\Models\QuoteAcceptance;
use Illuminate\Support\Carbon;

/**
 * The fully-resolved, ready-to-render order form for the hosted, seller-branded, self-contained
 * `/quote/{token}` page. The Blade reads only this VO — never a model or the container — so the
 * page is deterministic and CSP-safe.
 *
 * @property list<array{label: string, amount: string}> $rampSteps
 */
readonly class RenderedOrderForm
{
    /**
     * @param  list<array{label: string, amount: string}>  $rampSteps
     */
    public function __construct(
        public string $number,
        public string $customerName,
        public QuoteStatus $status,
        public bool $expired,
        public SellerBranding $branding,
        public string $currency,
        public QuoteComputation $computation,
        public string $termSummary,
        public ?Carbon $startDate,
        public ?Carbon $validUntil,
        public ?string $commitmentLabel,
        public array $rampSteps,
        public ?string $notes,
        public ?QuoteAcceptance $acceptance,
    ) {}

    /** Whether the customer can still accept/decline (sent + unexpired). */
    public function isActionable(): bool
    {
        return $this->status === QuoteStatus::Sent && ! $this->expired;
    }
}
