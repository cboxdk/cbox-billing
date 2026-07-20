<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Resolves a selling entity's registered identity for a legal document header (invoice OR credit
 * note) from the seller config — the single place both PDF renderers read the seller's legal
 * name, registration and tax registrations, so an invoice and its credit note carry an identical
 * masthead. Falls back to just the seller key when the entity is not configured, rather than
 * inventing legal details.
 */
readonly class SellerDocumentIdentity
{
    /**
     * @return array{key: string, legal_name: string, registration_number: string|null, establishment: string|null, tax_registrations: list<array{country: string, number: string}>}
     */
    public static function resolve(Config $config, string $seller): array
    {
        $entities = $config->get('billing.seller.entities', []);
        $entity = is_array($entities) && is_array($entities[$seller] ?? null) ? $entities[$seller] : [];

        $legalName = $entity['legal_name'] ?? null;
        $registration = $entity['registration_number'] ?? null;
        $establishment = $entity['establishment'] ?? null;

        $registrations = [];

        foreach (is_array($entity['tax_registrations'] ?? null) ? $entity['tax_registrations'] : [] as $registrationRow) {
            if (is_array($registrationRow) && is_string($registrationRow['country'] ?? null) && is_string($registrationRow['number'] ?? null)) {
                $registrations[] = ['country' => $registrationRow['country'], 'number' => $registrationRow['number']];
            }
        }

        return [
            'key' => $seller,
            'legal_name' => is_string($legalName) ? $legalName : $seller,
            'registration_number' => is_string($registration) ? $registration : null,
            'establishment' => is_string($establishment) ? $establishment : null,
            'tax_registrations' => $registrations,
        ];
    }
}
