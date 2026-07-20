<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Hosted\Enums\SessionStatus;
use App\Billing\Hosted\Enums\SessionType;
use App\Billing\Mode\Concerns\BelongsToMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hosted checkout- or customer-portal session (ADR-0009 Path A). It is addressed by an
 * opaque `token` in the hosted page URL — the token, not the provider auth gate,
 * authorizes the page. A checkout carries the `plan_key` (and optional signup `currency`)
 * it collects payment for; the `payment_reference` is the reference the gateway's settled
 * webhook carries, joining the client-side intent to the exactly-once activation.
 *
 * Only the SHA-256 `token_hash` is stored at rest (P2): the raw token lives in memory just
 * long enough to mint the URL or resolve a request, and `$session->token` reads it back from
 * that in-memory copy — never a database column — so a DB dump never yields live tokens.
 *
 * @property string $id
 * @property string $token_hash
 * @property string|null $token
 * @property string $organization_id
 * @property SessionType $type
 * @property string|null $plan_key
 * @property string|null $currency
 * @property string|null $coupon_code
 * @property string $return_url
 * @property string|null $payment_reference
 * @property int|null $expected_amount_minor
 * @property string|null $expected_currency
 * @property SessionStatus $status
 * @property bool $livemode
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 */
class BillingSession extends Model
{
    use BelongsToMode;
    use HasUuids;

    protected $fillable = [
        'token_hash', 'organization_id', 'type', 'plan_key', 'currency', 'coupon_code',
        'return_url', 'payment_reference', 'expected_amount_minor', 'expected_currency',
        'status', 'expires_at', 'completed_at',
    ];

    /**
     * The plaintext token, held in memory only (set when a session is minted or resolved). It is
     * NOT a database column — only its {@see $token_hash} digest is persisted — so a save never
     * writes it back and a dumped row never carries it.
     */
    protected ?string $plaintextToken = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SessionType::class,
            'status' => SessionStatus::class,
            'expected_amount_minor' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** The SHA-256 digest a lookup keys on for the plaintext `$token`. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Read the in-memory plaintext token (present only on a freshly minted or resolved session). */
    public function getTokenAttribute(): ?string
    {
        return $this->plaintextToken;
    }

    /** Hold the plaintext token in memory (never persisted); callers read it back as `$session->token`. */
    public function setTokenAttribute(?string $token): void
    {
        $this->plaintextToken = $token;
    }

    /** Whether the session's TTL has elapsed. */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Whether a checkout has been activated (its settled webhook applied). */
    public function isComplete(): bool
    {
        return $this->status === SessionStatus::Complete;
    }

    /**
     * Whether the token still authorizes its page: pending and within its TTL. A complete
     * portal session stays usable; a complete checkout has nothing left to collect.
     */
    public function isUsable(): bool
    {
        return $this->status === SessionStatus::Pending && ! $this->isExpired();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
