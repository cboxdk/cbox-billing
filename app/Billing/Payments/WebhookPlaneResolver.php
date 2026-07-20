<?php

declare(strict_types=1);

namespace App\Billing\Payments;

use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\EnvironmentScope;
use App\Models\BillingSession;
use App\Models\Environment;
use App\Models\GatewayCustomer;
use App\Models\Invoice;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Illuminate\Database\Eloquent\Model;

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
 * Peeking the reference out of the raw bytes to PICK the plane is safe: it only selects which
 * secret to verify against; authenticity is still proved by the signature check that follows. The
 * lookups here bypass {@see EnvironmentScope} (the settlement route has no ambient plane to trust)
 * and fall back to the ambient plane when nothing matches — under which nothing will match either,
 * so a stray payload simply no-ops rather than crossing planes.
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
     * The plane a settlement reference lives in — the invoice (settlements/renewals) or the pending
     * checkout session (hosted activation) that owns it, resolved UNSCOPED. The ambient plane when
     * the reference matches neither.
     */
    public function forReference(string $reference): Environment
    {
        if ($reference === '') {
            return $this->context->environment();
        }

        $owner = Invoice::query()
            ->withoutGlobalScope(EnvironmentScope::class)
            ->where('number', $reference)
            ->first();

        if (! $owner instanceof Invoice) {
            $owner = BillingSession::query()
                ->withoutGlobalScope(EnvironmentScope::class)
                ->where('payment_reference', $reference)
                ->where('type', 'checkout')
                ->first();
        }

        return $this->planeFor($owner);
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
     * The owning plane for a raw (UNVERIFIED) settlement payload — peeks the settlement reference
     * out of the body so the controller can set the plane before the signature is verified.
     */
    public function forSettlementPayload(WebhookPayload $payload): Environment
    {
        return $this->forReference($this->referenceFromPayload($payload));
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
     * Peek the settlement reference out of the untrusted body: the Stripe shape
     * (`data.object.metadata.reference`) first, then the manual shape (`reference` at the root).
     */
    private function referenceFromPayload(WebhookPayload $payload): string
    {
        $data = $this->decode($payload);

        $object = $this->arrayAt($data, ['data', 'object']);
        $metadata = $this->arrayAt($object, ['metadata']);
        $stripeReference = $metadata['reference'] ?? null;

        if (is_string($stripeReference) && $stripeReference !== '') {
            return $stripeReference;
        }

        $manualReference = $data['reference'] ?? null;

        return is_string($manualReference) ? $manualReference : '';
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

        $object = $this->arrayAt($data, ['data', 'object']);
        $stripeCustomer = $object['customer'] ?? null;

        if (is_string($stripeCustomer) && $stripeCustomer !== '') {
            return ['stripe', $stripeCustomer];
        }

        $manualAccount = $data['account'] ?? null;

        return is_string($manualAccount) && $manualAccount !== ''
            ? [$routeGateway, $manualAccount]
            : ['', ''];
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
