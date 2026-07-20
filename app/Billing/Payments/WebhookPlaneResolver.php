<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Environments\PlaneDocumentPrefix;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\EnvironmentScope;
use App\Billing\Payments\ValueObjects\SettlementSignals;
use App\Models\BillingSession;
use App\Models\Environment;
use App\Models\GatewayCustomer;
use App\Models\Invoice;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for "which billing PLANE owns this inbound webhook" — the unscoped
 * reference → {@see Environment} resolution the public settlement / card-update webhook path needs
 * BEFORE it can do anything plane-sensitive.
 *
 * The two public webhook routes carry no credential to set the request's plane, so the ambient
 * {@see BillingContext} defaults to production. That is a problem the moment the SIGNATURE is
 * verified: the plane-aware verifier resolves its signing secret from the current plane, so a
 * sandbox that configured its OWN DB webhook secret would be verified against the production/global
 * secret instead (HP1). The controller therefore resolves the owning plane from the (still
 * UNVERIFIED) payload and pushes it onto the context BEFORE verifying — so verification uses the
 * correct plane's secret, and a payload signed with the global/production secret can NOT
 * authenticate against a sandbox reference (its secret differs), while a sandbox's own DB-secret
 * webhook verifies and applies in that sandbox.
 *
 * WHY THE INVOICE NUMBER IS NOT ENOUGH. Resolving the plane from the settlement reference alone was
 * a cross-plane COLLISION: invoice numbers are unique only per `(seller, number)`, and cloning an
 * environment duplicates the seller entities together with their invoice prefix — so
 * `CBOX-DK-2026-00001` can exist in production and in a sandbox simultaneously. An unscoped
 * `where('number', …)->first()` then picked whichever row the storage engine returned first,
 * which meant a sandbox settlement could verify against, and apply to, the PRODUCTION invoice (or a
 * legitimate sandbox event could be rejected against the wrong plane's secret).
 *
 * The plane is therefore resolved from the most GLOBALLY-UNIQUE signal available, in order (see
 * {@see SettlementSignals}):
 *
 *   1. the gateway's own OBJECT id (`pi_…`/`ch_…`) recorded against a checkout session, a settled
 *      invoice or a dunning retry attempt — a gateway never mints one twice;
 *   2. the gateway CUSTOMER handle (`cus_…`) in `gateway_customers`, which environment cloning does
 *      not copy, so a matching row names the owning plane outright;
 *   3. only then the settlement reference — and only when it is UNAMBIGUOUS. When no strong signal
 *      matched and the reference addresses SEVERAL planes, {@see forSignals()} REFUSES (returns
 *      null) and the controller rejects the payload with the ordinary verification-failure response.
 *      It never falls back to the ambient plane there — that fallback is what let a reference-only
 *      payload settle production's identically-numbered invoice.
 *
 * The refusal is deliberately indistinguishable from a bad signature (same status, same body), so it
 * cannot be used to probe which references exist, or in how many planes.
 *
 * Preferring the ambient plane among ambiguous candidates in {@see forReference()} is what keeps the
 * pre-verify peek and the post-verify apply in agreement: the ingest re-resolves the plane INSIDE the
 * plane the controller already pushed from a STRONG signal, so an ambiguous number resolves back to
 * that same plane rather than jumping to another one. That preference is safe only there, after a
 * strong signal has already decided.
 *
 * Cross-plane number collisions are also closed at the root: a cloned or config-fallback seller
 * numbers its legal documents under a plane-distinct prefix
 * ({@see PlaneDocumentPrefix}), and the counters behind those numbers are
 * keyed by `(seller, environment)`. Step 3's refusal is the backstop for what remains — e.g. an
 * operator hand-authoring the same prefix in two planes.
 *
 * The lookups here bypass {@see EnvironmentScope} (the settlement route has no ambient plane to
 * trust) and fall back to the ambient plane when nothing matches — under which nothing will match
 * either, so a stray payload simply no-ops rather than crossing planes.
 *
 * The verified-path ingest ({@see PlaneAwareWebhookIngest}) and card-updater
 * ({@see DunningCardUpdater}) resolve the plane through the SAME methods here, so the pre-verify
 * peek and the post-verify apply can never disagree about which plane a reference lives in.
 */
readonly class WebhookPlaneResolver
{
    public function __construct(
        private BillingContext $context,
        private EnvironmentRegistry $environments,
    ) {}

    /**
     * The plane a settlement reference lives in, resolved UNSCOPED from the reference ALONE — the
     * invoice number (settlements/renewals) or the pending checkout session's payment reference
     * (hosted activation).
     *
     * The reference is the weakest of the plane signals (see the class docblock): a cloned
     * environment can carry the same invoice number as production. So this deliberately does NOT
     * pick a winner when the reference is ambiguous — it prefers the AMBIENT plane when that is one
     * of the candidates (which is how the ingest re-resolves back onto the plane the controller
     * already chose from a stronger signal) and otherwise returns the ambient plane untouched.
     *
     * Callers holding the raw body should prefer {@see forSettlementPayload()}, which consults the
     * globally-unique gateway signals first.
     */
    public function forReference(string $reference): Environment
    {
        if ($reference === '') {
            return $this->context->environment();
        }

        return $this->pick($this->planesForReference($reference));
    }

    /**
     * The plane a card-update account lives in — the {@see GatewayCustomer} that maps the gateway's
     * account key (the vaulted `cus_…` for Stripe) to an organization, resolved UNSCOPED. The
     * ambient plane when nothing maps (the manual/host gateway keys the vault by org id directly and
     * carries no plane signal, so it stays in the ambient plane).
     */
    public function forAccount(string $gateway, string $account): Environment
    {
        if ($gateway === '' || $account === '') {
            return $this->context->environment();
        }

        $customer = GatewayCustomer::query()
            ->withoutGlobalScope(EnvironmentScope::class)
            ->where('gateway', $gateway)
            ->where('gateway_customer_id', $account)
            ->first();

        return $this->planeFor($customer);
    }

    /**
     * The owning plane for a raw (UNVERIFIED) settlement payload — peeks every plane signal the body
     * carries and resolves on the most globally-unique one that matches, so the controller can set
     * the plane before the signature is verified.
     *
     * NULL means REFUSE: the payload's only signal is a settlement reference that addresses more than
     * one plane, so there is no honest answer and the caller must reject the payload outright (see
     * {@see forSignals()}).
     */
    public function forSettlementPayload(WebhookPayload $payload): ?Environment
    {
        return $this->forSignals($this->settlementSignals($payload));
    }

    /**
     * Resolve the owning plane from a set of settlement signals, strongest first: the gateway object
     * id, then the gateway customer, then (only if unambiguous) the settlement reference.
     *
     * FAIL CLOSED. When a strong (globally-unique) gateway signal names exactly one plane, that plane
     * wins — unchanged. When NO strong signal matches and the reference alone addresses SEVERAL
     * planes, this returns null instead of falling back to the ambient plane. Defaulting to ambient
     * meant an invoice-number-only payload settled PRODUCTION's identically-numbered invoice whenever
     * the event was meant for a sandbox; refusing means neither plane's invoice moves. (Cloned and
     * config-fallback sellers now number their documents per plane — see
     * {@see PlaneDocumentPrefix} — so this is a backstop for residual
     * ambiguity, e.g. an operator who hand-authored the same prefix in two planes.)
     */
    public function forSignals(SettlementSignals $signals): ?Environment
    {
        $planes = $this->planesForGatewayObject($signals->gatewayObject);

        if (count($planes) !== 1) {
            $planes = $this->planesForGatewayCustomer($signals->gateway, $signals->gatewayCustomer);
        }

        if (count($planes) === 1) {
            return $this->pick($planes);
        }

        $references = $this->planesForReference($signals->reference);

        // The reference is the last and weakest signal: if it names more than one plane it names
        // none of them. Refuse rather than settle an arbitrary (in practice: the production) invoice.
        if (count($references) > 1) {
            return null;
        }

        return $this->pick($references);
    }

    /**
     * The owning plane for a raw (UNVERIFIED) card-update payload. `$routeGateway` is the gateway
     * segment of the webhook URL — used only for the manual shape; a Stripe-shaped body carries its
     * own `cus_…` customer and is always resolved against the `stripe` gateway.
     */
    public function forCardUpdatePayload(WebhookPayload $payload, string $routeGateway): Environment
    {
        [$gateway, $account] = $this->accountFromPayload($payload, $routeGateway);

        return $this->forAccount($gateway, $account);
    }

    /**
     * Collapse a candidate set of environment keys to one plane.
     *
     * The AMBIENT plane wins whenever it is among the candidates. That is what keeps the two
     * resolutions of a single webhook in agreement: the controller picks the plane from the strong
     * gateway signals and pushes it, and the ingest — which only has the (weak, possibly colliding)
     * settlement reference to go on — then re-resolves back onto that very plane instead of jumping
     * to a same-numbered invoice in another one.
     *
     * Otherwise exactly one candidate wins outright. Several candidates with the ambient plane not
     * among them means the signal simply cannot tell the planes apart: the ambient plane is returned
     * unchanged rather than guessing, so the payload fails verification / no-ops instead of being
     * applied to an arbitrary plane's row.
     *
     * The pre-verify peek does NOT reach here with an ambiguous reference — {@see forSignals()} has
     * already refused by then. This collapse is for the post-verify re-resolution, which runs inside
     * the plane a strong signal already chose.
     *
     * @param  list<string>  $keys
     */
    private function pick(array $keys): Environment
    {
        if (in_array($this->context->environmentKey(), $keys, true)) {
            return $this->context->environment();
        }

        if (count($keys) === 1) {
            return $this->environments->resolve($keys[0]);
        }

        return $this->context->environment();
    }

    /**
     * The distinct plane keys a GATEWAY OBJECT id addresses: the checkout session it paid, the
     * invoice it settled, or the dunning retry attempt that produced it. Every one of these columns
     * holds an id the gateway itself minted, so a match is unambiguous by construction — the set is
     * still collapsed to distinct keys so a single object recorded in two places still resolves.
     *
     * @return list<string>
     */
    private function planesForGatewayObject(string $object): array
    {
        if ($object === '') {
            return [];
        }

        $keys = [
            ...$this->keys(BillingSession::query()
                ->withoutGlobalScope(EnvironmentScope::class)
                ->where('payment_reference', $object)),
            ...$this->keys(Invoice::query()
                ->withoutGlobalScope(EnvironmentScope::class)
                ->where('gateway_reference', $object)),
            // `payment_retry_attempts` is not plane-partitioned itself; its parent retry is.
            ...$this->strings(DB::table('payment_retry_attempts')
                ->join('payment_retries', 'payment_retries.id', '=', 'payment_retry_attempts.payment_retry_id')
                ->where('payment_retry_attempts.gateway_reference', $object)
                ->distinct()
                ->pluck('payment_retries.environment')
                ->all()),
        ];

        return $this->distinct($keys);
    }

    /**
     * The distinct plane keys a GATEWAY CUSTOMER handle addresses. `gateway_customers` is not copied
     * when an environment is cloned and the handle is minted by the gateway, so in practice this
     * resolves to exactly one plane.
     *
     * @return list<string>
     */
    private function planesForGatewayCustomer(string $gateway, string $customer): array
    {
        if ($gateway === '' || $customer === '') {
            return [];
        }

        return $this->distinct($this->keys(
            GatewayCustomer::query()
                ->withoutGlobalScope(EnvironmentScope::class)
                ->where('gateway', $gateway)
                ->where('gateway_customer_id', $customer),
        ));
    }

    /**
     * The distinct plane keys a SETTLEMENT REFERENCE addresses — the invoice number (which is only
     * unique per seller, so this can legitimately return more than one) or the checkout session's
     * payment reference (globally unique).
     *
     * @return list<string>
     */
    private function planesForReference(string $reference): array
    {
        if ($reference === '') {
            return [];
        }

        $keys = $this->distinct($this->keys(
            Invoice::query()->withoutGlobalScope(EnvironmentScope::class)->where('number', $reference),
        ));

        if ($keys !== []) {
            return $keys;
        }

        return $this->distinct($this->keys(
            BillingSession::query()
                ->withoutGlobalScope(EnvironmentScope::class)
                ->where('payment_reference', $reference)
                ->where('type', 'checkout'),
        ));
    }

    /**
     * The `environment` values a query matches, unfiltered.
     *
     * @param  Builder<covariant Model>  $query
     * @return list<string>
     */
    private function keys(Builder $query): array
    {
        return $this->strings($query->toBase()->distinct()->pluck('environment')->all());
    }

    /**
     * Narrow a plucked column to a list of strings (a null/!string value becomes '' and is dropped
     * by {@see distinct()}).
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_map(
            static fn (mixed $value): string => is_string($value) ? $value : '',
            array_values($values),
        );
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function distinct(array $keys): array
    {
        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
    }

    /** Resolve the owning row's stamped `environment` key to a plane, or the ambient plane. */
    private function planeFor(?Model $owner): Environment
    {
        if ($owner === null) {
            return $this->context->environment();
        }

        $key = $owner->getAttribute('environment');

        return is_string($key) && $key !== ''
            ? $this->environments->resolve($key)
            : $this->context->environment();
    }

    /**
     * Peek every plane signal out of the untrusted settlement body: the Stripe shape
     * (`data.object.id`, `data.object.customer`, `data.object.metadata.reference`) and the manual
     * shape (`reference` / `account` at the root).
     */
    private function settlementSignals(WebhookPayload $payload): SettlementSignals
    {
        $data = $this->decode($payload);
        $object = $this->arrayAt($data, ['data', 'object']);

        $stripeObject = $this->str($object, 'id');
        $stripeCustomer = $this->str($object, 'customer');
        $stripeReference = $this->str($this->arrayAt($object, ['metadata']), 'reference');

        if ($stripeObject !== '' || $stripeCustomer !== '' || $stripeReference !== '') {
            return new SettlementSignals(
                reference: $stripeReference,
                gatewayObject: $stripeObject,
                gatewayCustomer: $stripeCustomer,
                gateway: 'stripe',
            );
        }

        return new SettlementSignals(reference: $this->str($data, 'reference'));
    }

    /**
     * Peek the card-update account out of the untrusted body: the Stripe shape
     * (`data.object.customer`, always the `stripe` gateway) first, then the manual shape (`account`
     * at the root, under the route's gateway).
     *
     * @return array{0: string, 1: string} [gateway, account]
     */
    private function accountFromPayload(WebhookPayload $payload, string $routeGateway): array
    {
        $data = $this->decode($payload);

        $stripeCustomer = $this->str($this->arrayAt($data, ['data', 'object']), 'customer');

        if ($stripeCustomer !== '') {
            return ['stripe', $stripeCustomer];
        }

        $manualAccount = $this->str($data, 'account');

        return $manualAccount !== '' ? [$routeGateway, $manualAccount] : ['', ''];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(WebhookPayload $payload): array
    {
        $data = json_decode($payload->body, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Walk a nested array path, returning the array at the end or an empty array.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $path
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $data, array $path): array
    {
        $cursor = $data;

        foreach ($path as $segment) {
            // $cursor is an array at every step (the param, then each verified sub-array), so we only
            // need to prove the next segment exists and is itself an array before descending.
            if (! array_key_exists($segment, $cursor) || ! is_array($cursor[$segment])) {
                return [];
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
