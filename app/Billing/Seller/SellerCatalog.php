<?php

declare(strict_types=1);

namespace App\Billing\Seller;

use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Billing\Seller\ValueObjects\TaxRegistration;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * Builds the engine's {@see SellerEntity} value objects from the `billing.seller` config
 * — the legal selling entities of record. The entity that issues an invoice drives the
 * tax outcome (its establishment + registrations are the seller side of
 * `tax = f(seller, buyer, product)`), so this is the single place that identity is
 * assembled from configuration.
 */
readonly class SellerCatalog
{
    public function __construct(private Config $config) {}

    /** The configured default selling entity. */
    public function default(): SellerEntity
    {
        $id = $this->config->get('billing.seller.default');

        return $this->entity(is_string($id) ? $id : 'cbox-dk');
    }

    /** The selling entity for `$id`, or a runtime error when it is not configured. */
    public function entity(string $id): SellerEntity
    {
        $entities = $this->config->get('billing.seller.entities');
        $definition = is_array($entities) ? ($entities[$id] ?? null) : null;

        if (! is_array($definition)) {
            throw new RuntimeException("No selling entity configured for [{$id}].");
        }

        return new SellerEntity(
            id: $id,
            legalName: self::str($definition, 'legal_name'),
            registrationNumber: self::str($definition, 'registration_number'),
            establishment: new CountryCode(self::str($definition, 'establishment')),
            defaultCurrency: self::str($definition, 'currency'),
            invoicePrefix: self::str($definition, 'invoice_prefix'),
            taxRegistrations: $this->registrations($definition),
        );
    }

    /**
     * @param  array<array-key, mixed>  $definition
     * @return list<TaxRegistration>
     */
    private function registrations(array $definition): array
    {
        $rows = $definition['tax_registrations'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $registrations = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $subdivision = $row['subdivision'] ?? null;

            $registrations[] = new TaxRegistration(
                country: new CountryCode(self::str($row, 'country')),
                number: self::str($row, 'number'),
                subdivision: is_string($subdivision) && $subdivision !== '' ? new SubdivisionCode($subdivision) : null,
                scheme: isset($row['scheme']) && is_string($row['scheme']) ? $row['scheme'] : null,
            );
        }

        return $registrations;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Seller config key [{$key}] must be a non-empty string.");
        }

        return $value;
    }
}
