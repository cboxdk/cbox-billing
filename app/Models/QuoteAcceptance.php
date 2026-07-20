<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Cpq\Contracts\CapturesSignature;
use Cbox\Billing\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The immutable acceptance record for a {@see Quote} — the e-signature-by-acceptance evidence:
 * the customer typed their full name, ticked an explicit agreement, and the server captured the
 * timestamp, IP and user agent. `signature_provider` records WHO captured it — `null` is the
 * in-house click-through acceptance this app ships; a real provider (DocuSign, etc.) bound to the
 * {@see CapturesSignature} seam records its own reference. A snapshot of
 * the accepted totals + committed value is stored so the record stands alone even if the catalog
 * later moves. One row per accepted quote.
 *
 * @property int $id
 * @property int $quote_id
 * @property string $signer_name
 * @property string|null $signer_email
 * @property bool $agreed
 * @property string $signature_provider
 * @property string|null $signature_reference
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $currency
 * @property int $accepted_total_minor
 * @property int $committed_value_minor
 * @property Carbon $accepted_at
 */
class QuoteAcceptance extends Model
{
    protected $fillable = [
        'quote_id', 'signer_name', 'signer_email', 'agreed', 'signature_provider',
        'signature_reference', 'ip', 'user_agent', 'currency',
        'accepted_total_minor', 'committed_value_minor', 'accepted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'agreed' => 'boolean',
            'accepted_total_minor' => 'integer',
            'committed_value_minor' => 'integer',
            'accepted_at' => 'datetime',
        ];
    }

    /** The accepted first-invoice total as an engine money value object. */
    public function acceptedTotal(): Money
    {
        return Money::ofMinor($this->accepted_total_minor, $this->currency);
    }

    /** The accepted committed contract value as an engine money value object. */
    public function committedValue(): Money
    {
        return Money::ofMinor($this->committed_value_minor, $this->currency);
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
