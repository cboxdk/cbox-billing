<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Seller\SellerCatalog;
use App\Models\ApiToken;
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
     * @return list<array<string, mixed>>
     */
    public function sellers(): array
    {
        $entities = $this->config->get('billing.seller.entities');
        $default = $this->config->get('billing.seller.default');
        $rows = [];

        if (! is_array($entities)) {
            return $rows;
        }

        foreach (array_keys($entities) as $id) {
            $id = (string) $id;
            $entity = $this->sellers->entity($id);

            $rows[] = [
                'id' => $entity->id,
                'legal_name' => $entity->legalName,
                'registration_number' => $entity->registrationNumber,
                'establishment' => (string) $entity->establishment,
                'currency' => $entity->defaultCurrency,
                'invoice_prefix' => $entity->invoicePrefix,
                'is_default' => $id === $default,
                'registrations' => array_map(static fn (TaxRegistration $registration): array => [
                    'country' => (string) $registration->country,
                    'number' => $registration->number,
                    'subdivision' => $registration->subdivision !== null ? (string) $registration->subdivision : null,
                    'scheme' => $registration->scheme,
                ], $entity->taxRegistrations),
            ];
        }

        return $rows;
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
            'name' => $token->name,
            'scope' => $token->organization_id === null ? 'operator (any org)' : $token->organization_id,
            'org' => $token->organization?->name,
            'last_used' => $token->last_used_at?->diffForHumans() ?? 'never',
            'created' => $token->created_at?->format('Y-m-d') ?? '—',
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
}
