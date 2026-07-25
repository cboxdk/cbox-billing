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
     * The identity to print on an ALREADY-ISSUED document: its own snapshot when it has one, and
     * the live register only as a fallback for rows issued before snapshots existed.
     *
     * Under EU Directive 2006/112/EC the content of an issued invoice is fixed. Reading the live
     * register meant an operator correcting a legal name — or an establishment country, which is
     * the tax jurisdiction shown — silently rewrote every document that seller had ever issued.
     *
     * @param  array<array-key, mixed>|null  $snapshot  The document's stored `seller_identity`.
     * @return array{key: string, legal_name: string, registration_number: string|null, establishment: string|null, tax_registrations: list<array{country: string, number: string}>}
     *
     * @throws RuntimeException when there is no snapshot AND the entity no longer resolves.
     */
    public static function forDocument(SellerCatalog $sellers, string $seller, ?array $snapshot): array
    {
        $restored = self::fromSnapshot($snapshot);

        return $restored ?? self::resolve($sellers, $seller);
    }

    /**
     * Rebuild an identity from a stored snapshot, or null when there is nothing usable there.
     *
     * Validated rather than trusted: a snapshot missing its legal name is worse than no snapshot,
     * because it would mast head a legal document with a blank. Such a row falls back to the live
     * register, which is the pre-snapshot behaviour.
     *
     * @param  array<array-key, mixed>|null  $snapshot
     * @return array{key: string, legal_name: string, registration_number: string|null, establishment: string|null, tax_registrations: list<array{country: string, number: string}>}|null
     */
    private static function fromSnapshot(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $key = $snapshot['key'] ?? null;
        $legalName = $snapshot['legal_name'] ?? null;

        if (! is_string($key) || $key === '' || ! is_string($legalName) || $legalName === '') {
            return null;
        }

        $registrations = [];

        foreach (is_array($snapshot['tax_registrations'] ?? null) ? $snapshot['tax_registrations'] : [] as $row) {
            if (is_array($row) && is_string($row['country'] ?? null) && is_string($row['number'] ?? null)) {
                $registrations[] = ['country' => $row['country'], 'number' => $row['number']];
            }
        }

        $registration = $snapshot['registration_number'] ?? null;
        $establishment = $snapshot['establishment'] ?? null;

        return [
            'key' => $key,
            'legal_name' => $legalName,
            'registration_number' => is_string($registration) && $registration !== '' ? $registration : null,
            'establishment' => is_string($establishment) && $establishment !== '' ? $establishment : null,
            'tax_registrations' => $registrations,
        ];
    }

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
