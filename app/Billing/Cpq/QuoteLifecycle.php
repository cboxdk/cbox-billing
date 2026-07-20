<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Cpq\Enums\QuoteStatus;
use App\Billing\Cpq\Exceptions\QuoteActionDenied;
use App\Models\Quote;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The send/resend/expire/clone lifecycle of a quote. Sending an APPROVED quote mints the opaque
 * order-form token (the whole authorization of the no-auth `/quote/{token}` page) and moves it to
 * `sent`; resending re-stamps the timestamp on the same link; expiring closes it; cloning starts a
 * fresh editable draft from an existing quote (new number, no token/approval/acceptance carried
 * over).
 */
readonly class QuoteLifecycle
{
    public function __construct(
        private Config $config,
        private QuoteNumberGenerator $numbers,
    ) {}

    /** Send an approved quote: mint the token (once) and open the order form. */
    public function send(Quote $quote): Quote
    {
        if (! $quote->status->isApproved()) {
            throw QuoteActionDenied::notSendable();
        }

        $this->stampToken($quote, [
            'status' => QuoteStatus::Sent,
            'sent_at' => Carbon::now(),
        ]);

        return $quote;
    }

    /** Re-send an already-sent quote: same link, refreshed timestamp. */
    public function resend(Quote $quote): Quote
    {
        if (! $quote->status->isSent()) {
            throw QuoteActionDenied::notOpen();
        }

        $this->stampToken($quote, ['sent_at' => Carbon::now()]);

        return $quote;
    }

    /**
     * Persist `$attributes` and ensure the quote carries an order-form token: mint one (once) when
     * the quote has no `token_hash` yet, storing ONLY the SHA-256 digest and holding the plaintext
     * in memory so the caller can build the shareable URL from `$quote->token` this request. An
     * already-tokenized quote keeps its digest (its link is unchanged). The plaintext is never
     * recoverable from the row again — the digest is the only copy at rest.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function stampToken(Quote $quote, array $attributes): void
    {
        if ($quote->token_hash === null) {
            $plaintext = $this->mintToken();
            $attributes['token_hash'] = Quote::hashToken($plaintext);
            $quote->token = $plaintext; // in-memory only, for this request's URL
        }

        $quote->forceFill($attributes)->save();
    }

    /** Expire an outstanding quote (approved or sent). Terminal. */
    public function expire(Quote $quote): Quote
    {
        if ($quote->status->isTerminal()) {
            throw QuoteActionDenied::notOpen();
        }

        $quote->forceFill(['status' => QuoteStatus::Expired])->save();

        return $quote;
    }

    /** Clone a quote into a fresh editable draft: header + terms + lines, nothing lifecycle-bound. */
    public function clone(Quote $quote): Quote
    {
        $quote->loadMissing('lines');

        return DB::transaction(function () use ($quote): Quote {
            $copy = Quote::query()->create([
                'number' => $this->numbers->next(),
                'status' => QuoteStatus::Draft,
                'organization_id' => $quote->organization_id,
                'prospect_name' => $quote->prospect_name,
                'prospect_email' => $quote->prospect_email,
                'seller_entity_id' => $quote->seller_entity_id,
                'currency' => $quote->currency,
                'valid_until' => Carbon::now()->addDays($this->validDays()),
                'owner_sub' => $quote->owner_sub,
                'owner_name' => $quote->owner_name,
                'notes' => $quote->notes,
                'coupon_id' => $quote->coupon_id,
                'term_count' => $quote->term_count,
                'term_unit' => $quote->term_unit,
                'billing_interval' => $quote->billing_interval,
                'start_date' => $quote->start_date,
                'minimum_commitment_minor' => $quote->minimum_commitment_minor,
                'ramp' => $quote->ramp,
            ]);

            foreach ($quote->lines as $line) {
                $copy->lines()->create([
                    'sort_order' => $line->sort_order,
                    'type' => $line->type,
                    'plan_id' => $line->plan_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_amount_minor' => $line->unit_amount_minor,
                    'discount_kind' => $line->discount_kind,
                    'discount_value' => $line->discount_value,
                    'recurring' => $line->recurring,
                ]);
            }

            return $copy;
        });
    }

    private function mintToken(): string
    {
        $bytes = $this->config->get('billing.quotes.token_bytes');
        $bytes = is_numeric($bytes) ? max(16, (int) $bytes) : 32;

        return bin2hex(random_bytes($bytes));
    }

    private function validDays(): int
    {
        $days = $this->config->get('billing.quotes.valid_days');

        return is_numeric($days) ? max(1, (int) $days) : 30;
    }
}
