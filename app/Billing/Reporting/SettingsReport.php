<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Seller\SellerCatalog;
use App\Models\ApiToken;
use App\Models\SellerEntity;
use Cbox\Billing\Seller\ValueObjects\TaxRegistration;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Collection;

/**
 * Read model for the Settings screen. Assembles the operator-facing configuration from
 * its real sources: selling entities + their tax registrations via the {@see SellerCatalog}
 * (config), payment gateways from config, the enforcement {@see ApiToken}s from the
 * database, and the inbound settlement-webhook verification config.
 */
readonly class SettingsReport
{
    public function __construct(
        private SellerCatalog $sellers,
        private Config $config,
    ) {}

    /**
     * The selling entities of record — the operator-authored DB register (Wave 4) unioned
     * with any config-only entities not yet migrated into it, so every entity that can issue
     * an invoice is listed. Each row carries its `id`, whether it is the default, whether it
     * is archived, and its source, plus its per-jurisdiction tax registrations.
     *
     * @return list<array<string, mixed>>
     */
    public function sellers(): array
    {
        $models = SellerEntity::query()->with('taxRegistrations')->orderBy('legal_name')->get();
        $dbIds = $models->pluck('id')->all();
        $configDefault = $this->config->get('billing.seller.default');
        $rows = [];

        foreach ($models as $model) {
            $entity = $this->sellers->entity($model->id);

            $rows[] = [
                'id' => $entity->id,
                'legal_name' => $entity->legalName,
                'registration_number' => $entity->registrationNumber,
                'establishment' => (string) $entity->establishment,
                'currency' => $entity->defaultCurrency,
                'invoice_prefix' => $entity->invoicePrefix,
                'is_default' => $model->is_default,
                'archived' => $model->isArchived(),
                'source' => 'db',
                'registrations' => $this->registrationRows($entity->taxRegistrations),
            ];
        }

        // Config-only entities not yet authored into the DB (read-only until migrated).
        $entities = $this->config->get('billing.seller.entities');

        foreach (is_array($entities) ? array_keys($entities) : [] as $id) {
            $id = (string) $id;

            if (in_array($id, $dbIds, true)) {
                continue;
            }

            $entity = $this->sellers->entity($id);

            $rows[] = [
                'id' => $entity->id,
                'legal_name' => $entity->legalName,
                'registration_number' => $entity->registrationNumber,
                'establishment' => (string) $entity->establishment,
                'currency' => $entity->defaultCurrency,
                'invoice_prefix' => $entity->invoicePrefix,
                'is_default' => $rows === [] && $id === $configDefault,
                'archived' => false,
                'source' => 'config',
                'registrations' => $this->registrationRows($entity->taxRegistrations),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<TaxRegistration>  $registrations
     * @return list<array<string, mixed>>
     */
    private function registrationRows(array $registrations): array
    {
        return array_map(static fn (TaxRegistration $registration): array => [
            'country' => (string) $registration->country,
            'number' => $registration->number,
            'subdivision' => $registration->subdivision !== null ? (string) $registration->subdivision : null,
            'scheme' => $registration->scheme,
        ], $registrations);
    }

    /**
     * Flattened tax registrations across all selling entities.
     *
     * @return list<array<string, mixed>>
     */
    public function taxRegistrations(): array
    {
        $rows = [];

        foreach ($this->sellers() as $seller) {
            $registrations = $seller['registrations'];

            if (! is_array($registrations)) {
                continue;
            }

            foreach ($registrations as $registration) {
                if (! is_array($registration)) {
                    continue;
                }

                $rows[] = [
                    'seller' => $seller['legal_name'],
                    'country' => $registration['country'],
                    'number' => $registration['number'],
                    'subdivision' => $registration['subdivision'],
                    'scheme' => $registration['scheme'] ?? 'standard',
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function gateways(): array
    {
        $gateways = $this->config->get('billing.gateways');
        $rows = [];

        if (! is_array($gateways)) {
            return $rows;
        }

        foreach ($gateways as $key => $gateway) {
            if (! is_array($gateway)) {
                continue;
            }

            $rows[] = [
                'key' => (string) $key,
                'name' => is_string($gateway['name'] ?? null) ? $gateway['name'] : (string) $key,
                'mode' => is_string($gateway['mode'] ?? null) ? $gateway['mode'] : 'off',
                'connected' => (bool) ($gateway['connected'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function apiTokens(): Collection
    {
        return ApiToken::query()
            ->with('organization')
            ->orderBy('revoked_at')
            ->orderBy('name')
            ->get()
            ->map(fn (ApiToken $token): array => $this->token($token));
    }

    /**
     * @return array<string, mixed>
     */
    private function token(ApiToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'scope' => $token->organization_id === null ? 'operator (any org)' : $token->organization_id,
            'org' => $token->organization?->name,
            'product' => $token->product_id !== null ? (string) $token->product_id : null,
            'mode' => $token->mode,
            'last_used' => $token->last_used_at?->diffForHumans() ?? 'never',
            'created' => $token->created_at?->format('Y-m-d') ?? '—',
            'revoked' => $token->isRevoked(),
            'revoked_at' => $token->revoked_at?->format('Y-m-d') ?? null,
        ];
    }

    /**
     * The inbound settlement webhook the payment seam verifies (deny-by-default: with no
     * secret it refuses every payload).
     *
     * @return array<string, mixed>
     */
    public function webhook(): array
    {
        $secret = $this->config->get('billing.webhook.secret');
        $header = $this->config->get('billing.webhook.signature_header');

        return [
            'endpoint' => '/webhooks/{gateway}',
            'signature_header' => is_string($header) ? $header : 'X-Cbox-Signature',
            'secret_configured' => is_string($secret) && $secret !== '',
        ];
    }

    /**
     * The two inbound webhook RECEIVERS this app runs, with their real env-driven signing
     * state and honest rotation guidance. Both secrets are environment variables (not DB
     * rows), so the console surfaces status + how to rotate rather than storing a shadow
     * secret — a settlement receiver for gateway payments, and the Cbox ID provisioning
     * receiver (verified by the SDK against `CBOX_ID_WEBHOOK_SECRET`).
     *
     * @return list<array<string, mixed>>
     */
    public function webhookReceivers(): array
    {
        $settlementSecret = $this->config->get('billing.webhook.secret');
        $settlementHeader = $this->config->get('billing.webhook.signature_header');
        $provisioningSecret = $this->config->get('cbox-id-client.webhooks.secret');
        $provisioningPath = $this->config->get('cbox-id-client.webhooks.path');

        return [
            [
                'key' => 'settlement',
                'name' => 'Payment settlement',
                'endpoint' => '/webhooks/{gateway}',
                'signature_header' => is_string($settlementHeader) ? $settlementHeader : 'X-Cbox-Signature',
                'env_key' => 'CBOX_BILLING_WEBHOOK_SECRET',
                'configured' => is_string($settlementSecret) && $settlementSecret !== '',
                'description' => 'The manual gateway posts a signed settlement here (HMAC-SHA256). Deny-by-default: no secret refuses every payload.',
            ],
            [
                'key' => 'provisioning',
                'name' => 'Cbox ID provisioning',
                'endpoint' => is_string($provisioningPath) && $provisioningPath !== '' ? $provisioningPath : '/webhooks/cbox-id',
                'signature_header' => 'X-Cbox-Signature',
                'env_key' => 'CBOX_ID_WEBHOOK_SECRET',
                'configured' => is_string($provisioningSecret) && $provisioningSecret !== '',
                'description' => 'Cbox ID pushes member/role/directory/org events here; the SDK verifies the HMAC. Deny-by-default: no secret refuses every payload.',
            ],
        ];
    }

    /**
     * Per-gateway connection status + the env keys each needs, so the console shows a guided,
     * honest picture of the env-driven payment configuration rather than a DB row it does not
     * have. `connected` is computed from real env presence in `config/billing.php`.
     *
     * @return list<array<string, mixed>>
     */
    public function gatewayGuidance(): array
    {
        // The env keys each gateway reads to come online — surfaced so an operator knows
        // exactly what to set. These are documentation of the real config, not authored data.
        $envKeys = [
            'manual' => ['CBOX_BILLING_WEBHOOK_SECRET'],
            'stripe' => ['STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET'],
            'mollie' => ['MOLLIE_KEY'],
        ];

        return array_map(function (array $gateway) use ($envKeys): array {
            $key = is_string($gateway['key']) ? $gateway['key'] : '';

            return [
                'key' => $key,
                'name' => $gateway['name'],
                'mode' => $gateway['mode'],
                'connected' => $gateway['connected'],
                'env_keys' => $envKeys[$key] ?? [],
            ];
        }, $this->gateways());
    }
}
