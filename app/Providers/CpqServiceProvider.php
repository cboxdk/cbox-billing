<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\Cpq\Contracts\CapturesSignature;
use App\Billing\Cpq\Contracts\ProvisionsFromQuote;
use App\Billing\Cpq\NullSignatureProvider;
use App\Billing\Cpq\QuoteProvisioner;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the CPQ (sales quoting + contracts) module's two seams:
 *
 *  - {@see CapturesSignature} — the e-signature-by-acceptance provider. The default is the in-house
 *    {@see NullSignatureProvider}; a host binds a real provider (DocuSign, etc.) here. The concrete
 *    is chosen by `billing.quotes.signature.provider` (only `null` ships in this app).
 *  - {@see ProvisionsFromQuote} — turns an accepted quote into a subscription, idempotently.
 *
 * Every other CPQ service is a plain readonly class the container auto-resolves from its already
 * bound dependencies (the engine quote builder, tax context, coupon, subscribe seam, audit).
 */
class CpqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CapturesSignature::class, function (): CapturesSignature {
            $provider = config('billing.quotes.signature.provider');

            // Only the honest in-house provider ships here; a real provider is a host binding.
            return match ($provider) {
                default => new NullSignatureProvider,
            };
        });

        $this->app->singleton(ProvisionsFromQuote::class, QuoteProvisioner::class);
    }
}
