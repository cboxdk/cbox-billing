<?php

declare(strict_types=1);

namespace App\Billing\Invoicing;

use App\Billing\Seller\SellerCatalog;
use RuntimeException;

/**
 * Resolves a selling entity's registered identity for a legal document header (invoice OR credit
 * note) — the single place both PDF renderers read the seller's legal name, registration and tax
 * registrations, so an invoice and its credit note carry an identical masthead.
 *
 * REGISTER FIRST, THEN CONFIG. Resolution goes through {@see SellerCatalog}, which reads the
 * `seller_entities` table before falling back to `billing.seller.entities`. That ordering is not
 * cosmetic: the console writes seller entities to the DB, and this class previously read ONLY the
 * config. An operator who registered "Acme GmbH" in the console therefore got invoices mastheaded
 * with the raw seller KEY, no registration number and no VAT line — while tax was still computed
 * against the DB entity. Such a document fails EU Directive 2006/112/EC Art. 226(3) and 226(5), so
 * the buyer cannot deduct input VAT, and nothing errored to say so.
 *
 * A legal document must never be rendered with invented or placeholder identity, so an entity that
 * resolves in neither the register nor the config is a hard failure ({@see resolve()} throws)
 * rather than a silent degradation to the bare key.
 */
readonly class SellerDocumentIdentity
{
    /**
     * The registered identity for `$seller`, for a document masthead.
     *
     * @return array{key: string, legal_name: string, registration_number: string|null, establishment: string|null, tax_registrations: list<array{country: string, number: string}>}
     *
     * @throws RuntimeException when the entity exists in neither the register nor the config.
     */
    public static function resolve(SellerCatalog $sellers, string $seller): array
    {
        try {
            $entity = $sellers->entity($seller);
        } catch (RuntimeException $e) {
            // The catalog throws for two different reasons — the entity is defined nowhere, or it
            // IS defined in config but is missing a required field. Reporting the first
            // unconditionally sent an operator with a half-filled config entry to the wrong screen
            // to look for something that was already there, so the underlying reason is carried
            // through rather than replaced.
            throw new RuntimeException(
                "Cannot render a legal document for selling entity [{$seller}]: its registered "
                .'identity could not be resolved. Check Settings → Seller entities, or the '
                ."`billing.seller.entities` config, before issuing documents in its name. ({$e->getMessage()})",
                previous: $e,
            );
        }

        $registrations = [];

        foreach ($entity->taxRegistrations as $registration) {
            $registrations[] = [
                'country' => $registration->country->value,
                'number' => $registration->number,
            ];
        }

        return [
            'key' => $entity->id,
            'legal_name' => $entity->legalName,
            'registration_number' => $entity->registrationNumber !== '' ? $entity->registrationNumber : null,
            'establishment' => $entity->establishment->value,
            'tax_registrations' => $registrations,
        ];
    }
}
