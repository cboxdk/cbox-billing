<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Cpq\Exceptions\QuoteActionDenied;
use App\Billing\Cpq\Exceptions\SignatureRejected;
use App\Billing\Cpq\OrderFormPresenter;
use App\Billing\Cpq\QuoteAcceptanceService;
use App\Billing\Cpq\ValueObjects\SignatureRequest;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Billing\Mode\LivemodeScope;
use App\Models\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The PUBLIC, no-auth hosted order form (CPQ Wave 5): a fully self-contained (inline CSS/JS, no
 * external hosts — CSP-safe like the storefront), seller-branded page addressed by the quote's
 * opaque `token`. The token is the whole authorization — an unknown/wrong token 404s (cross-quote
 * isolation), so a customer only ever sees their own quote. Acceptance is an
 * e-signature-by-acceptance (typed name + explicit agree) captured through the CPQ acceptance
 * service, which records the immutable acceptance and provisions the subscription idempotently.
 *
 * Thin: resolve by token, project through {@see OrderFormPresenter}, render; the accept/decline
 * actions delegate to {@see QuoteAcceptanceService}.
 */
class OrderFormController extends Controller
{
    public function __construct(private readonly BillingContext $context) {}

    public function show(string $token, OrderFormPresenter $presenter): Response
    {
        $quote = $this->resolve($token);

        return $this->html(view('quotes.order-form', [
            'form' => $presenter->present($quote),
            'token' => $token,
        ])->render());
    }

    public function accept(Request $request, string $token, QuoteAcceptanceService $acceptance): RedirectResponse
    {
        $request->validate([
            'signer_name' => ['required', 'string', 'max:200'],
            'signer_email' => ['nullable', 'email', 'max:200'],
            'agree' => ['accepted'],
        ]);

        $quote = $this->resolve($token);

        try {
            $acceptance->accept($quote, new SignatureRequest(
                signerName: $request->string('signer_name')->toString(),
                signerEmail: $request->filled('signer_email') ? $request->string('signer_email')->toString() : null,
                agreed: $request->boolean('agree'),
                ip: $request->ip(),
                userAgent: substr((string) $request->userAgent(), 0, 500),
            ));
        } catch (SignatureRejected|QuoteActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('quote.show', $token)->with('status', 'accepted');
    }

    public function decline(Request $request, string $token, QuoteAcceptanceService $acceptance): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $quote = $this->resolve($token);

        try {
            $acceptance->decline($quote, $request->filled('reason') ? $request->string('reason')->toString() : null);
        } catch (QuoteActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('quote.show', $token)->with('status', 'declined');
    }

    /**
     * Resolve a quote by its opaque order-form token; an unknown token 404s (cross-quote
     * isolation). The token is globally unique and is the whole authorization, so this
     * bootstrap lookup runs WITHOUT the plane scope (a public route carries no credential to
     * set the mode). The resolved quote's `livemode` is then the SOURCE OF TRUTH for the
     * request's plane (HP1): the ambient {@see BillingContext} is set from it before the
     * presenter reads lines or acceptance provisions a subscription — so a test-plane quote
     * token resolves and acts on ONLY test data, and cannot touch a same-id live org.
     */
    private function resolve(string $token): Quote
    {
        // Only the SHA-256 digest is stored, so look the quote up by the hash of the URL token —
        // the plaintext travels in the URL but never lives in the row.
        $quote = Quote::query()
            ->withoutGlobalScope(LivemodeScope::class)
            ->where('token_hash', Quote::hashToken($token))
            ->whereNotNull('token_hash')
            ->first();

        if (! $quote instanceof Quote) {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        $this->context->setMode(BillingMode::fromLivemode($quote->livemode));

        return $quote;
    }

    private function html(string $body): Response
    {
        return new Response($body, SymfonyResponse::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
