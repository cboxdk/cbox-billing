<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Cpq\Enums\QuoteStatus;
use App\Billing\Cpq\QuoteCalculator;
use App\Billing\Mode\Concerns\BelongsToMode;
use Cbox\Billing\Catalog\Enums\TermUnit;
use Cbox\Billing\Catalog\ValueObjects\Term;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\ValueObjects\MinimumCommitment;
use Cbox\Billing\Subscription\ValueObjects\RampSchedule;
use Cbox\Billing\Subscription\ValueObjects\RampStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A sales quote — the CPQ contract of record. It is authored by a rep, (optionally) approved,
 * sent to the customer as a branded order form, accepted by e-signature, and provisions a
 * subscription. The header carries the buyer (an existing {@see Organization} or a pre-account
 * prospect), the selling entity, the currency, the lifecycle {@see QuoteStatus}, and the
 * committed CONTRACT TERMS — the contract length ({@see Term()}), the recurring
 * {@see BillingInterval()}, the per-period {@see MinimumCommitment()} floor, and the optional
 * {@see RampSchedule()} — all projected into the engine value objects the totals and the
 * committed value compute through.
 *
 * The stored line/term amounts are inputs, not a cached price: every total is recomputed through
 * the engine quote/pricing at render time (see {@see QuoteCalculator}), so what a
 * quote shows is exactly what the subscription will bill.
 *
 * @property int $id
 * @property bool $livemode
 * @property string $number
 * @property string|null $organization_id
 * @property string|null $prospect_name
 * @property string|null $prospect_email
 * @property string|null $seller_entity_id
 * @property string $currency
 * @property QuoteStatus $status
 * @property Carbon|null $valid_until
 * @property string|null $owner_sub
 * @property string|null $owner_name
 * @property string|null $notes
 * @property int|null $coupon_id
 * @property int $term_count
 * @property string $term_unit
 * @property string $billing_interval
 * @property Carbon|null $start_date
 * @property int|null $minimum_commitment_minor
 * @property list<array{from_period_index: int, amount_minor: int}>|null $ramp
 * @property bool $approval_required
 * @property string|null $approved_by_sub
 * @property string|null $approved_by_name
 * @property Carbon|null $approved_at
 * @property string|null $rejected_by_sub
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property string|null $token_hash
 * @property string|null $token
 * @property Carbon|null $sent_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 * @property string|null $decline_reason
 * @property int|null $subscription_id
 * @property Carbon|null $provisioned_at
 */
class Quote extends Model
{
    use BelongsToMode;

    protected $fillable = [
        'number', 'organization_id', 'prospect_name', 'prospect_email', 'seller_entity_id',
        'currency', 'status', 'valid_until', 'owner_sub', 'owner_name', 'notes', 'coupon_id',
        'term_count', 'term_unit', 'billing_interval', 'start_date',
        'minimum_commitment_minor', 'ramp',
        'approval_required', 'approved_by_sub', 'approved_by_name', 'approved_at',
        'rejected_by_sub', 'rejected_at', 'rejection_reason',
        'token_hash', 'sent_at', 'accepted_at', 'declined_at', 'decline_reason',
        'subscription_id', 'provisioned_at',
    ];

    /**
     * The plaintext order-form token, held in memory only (set when the quote is sent or resolved).
     * It is NOT a database column — only its {@see $token_hash} digest is persisted — so a save
     * never writes it back and a dumped row never carries it.
     */
    protected ?string $plaintextToken = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'valid_until' => 'date',
            'start_date' => 'date',
            'coupon_id' => 'integer',
            'term_count' => 'integer',
            'minimum_commitment_minor' => 'integer',
            'ramp' => 'array',
            'approval_required' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'subscription_id' => 'integer',
            'provisioned_at' => 'datetime',
        ];
    }

    /** The SHA-256 digest a lookup keys on for the plaintext order-form `$token`. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Read the in-memory plaintext token (present only on a freshly-sent or resolved quote). */
    public function getTokenAttribute(): ?string
    {
        return $this->plaintextToken;
    }

    /** Hold the plaintext token in memory (never persisted); callers read it back as `$quote->token`. */
    public function setTokenAttribute(?string $token): void
    {
        $this->plaintextToken = $token;
    }

    /** The buyer's display name: the linked org, else the free-text prospect, else the number. */
    public function customerName(): string
    {
        if ($this->organization instanceof Organization) {
            return $this->organization->name;
        }

        if ($this->prospect_name !== null && $this->prospect_name !== '') {
            return $this->prospect_name;
        }

        return $this->number;
    }

    /** The contract length as an engine {@see Term} value object. */
    public function term(): Term
    {
        return new Term(max(1, $this->term_count), TermUnit::from($this->term_unit));
    }

    /** The recurring cadence as the engine {@see BillingInterval}. */
    public function billingInterval(): BillingInterval
    {
        return BillingInterval::from($this->billing_interval);
    }

    /**
     * The number of billing periods the term spans: the contract length in months divided by the
     * billing interval in months (at least one). A 12-month term billed monthly is 12 periods; the
     * same term billed yearly is 1 period.
     */
    public function periodCount(): int
    {
        $termMonths = match (TermUnit::from($this->term_unit)) {
            TermUnit::Year => $this->term_count * 12,
            TermUnit::Month => $this->term_count,
            TermUnit::Day => max(1, (int) round($this->term_count / 30)),
        };

        return max(1, intdiv($termMonths, $this->billingInterval()->months()));
    }

    /** The per-period {@see MinimumCommitment} floor, or null when the quote carries no commitment. */
    public function minimumCommitment(): ?MinimumCommitment
    {
        if ($this->minimum_commitment_minor === null || $this->minimum_commitment_minor <= 0) {
            return null;
        }

        return new MinimumCommitment(Money::ofMinor($this->minimum_commitment_minor, $this->currency));
    }

    /**
     * The predetermined price {@see RampSchedule}, or null when the recurring price is flat.
     * Built from the stored step list; the engine value object validates it (index-0 opening step,
     * no duplicate indices).
     */
    public function rampSchedule(): ?RampSchedule
    {
        $steps = $this->ramp;

        if (! is_array($steps) || $steps === []) {
            return null;
        }

        $rampSteps = [];

        foreach ($steps as $step) {
            $rampSteps[] = new RampStep(
                (int) $step['from_period_index'],
                Money::ofMinor((int) $step['amount_minor'], $this->currency),
            );
        }

        return new RampSchedule($rampSteps);
    }

    public function isDraft(): bool
    {
        return $this->status === QuoteStatus::Draft;
    }

    /** Whether the customer order form is live: sent, unexpired, and not yet decided. */
    public function isOpenToCustomer(): bool
    {
        return $this->status->isOpenToCustomer() && ! $this->isExpiredNow();
    }

    /** Whether the validity window has elapsed as of now. */
    public function isExpiredNow(): bool
    {
        return $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }

    /** Whether this quote has already provisioned a subscription (idempotency guard). */
    public function isProvisioned(): bool
    {
        return $this->subscription_id !== null;
    }

    /**
     * Constrain a query to a status tab (`all` and unknown tabs are no-ops).
     *
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    public function scopeTab(Builder $query, string $tab): Builder
    {
        $status = QuoteStatus::tryFrom($tab);

        return $status !== null ? $query->where('status', $status->value) : $query;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<SellerEntity, $this> */
    public function sellerEntity(): BelongsTo
    {
        return $this->belongsTo(SellerEntity::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<QuoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasOne<QuoteAcceptance, $this> */
    public function acceptance(): HasOne
    {
        return $this->hasOne(QuoteAcceptance::class);
    }
}
