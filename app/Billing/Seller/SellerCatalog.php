<?php

declare(strict_types=1);

namespace App\Billing\Seller;

use App\Billing\Environments\PlaneDocumentPrefix;
use App\Billing\Mode\BillingContext;
use App\Models\SellerEntity as SellerEntityModel;
use Cbox\Billing\Seller\ValueObjects\SellerEntity;
use Cbox\Billing\Seller\ValueObjects\TaxRegistration;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * Builds the engine's {@see SellerEntity} value objects — the legal selling entities of
 * record. The entity that issues an invoice drives the tax outcome (its establishment +
 * registrations are the seller side of `tax = f(seller, buyer, product)`), so this is the
 * single place that identity is assembled.
 *
 * Source of truth, in order: the operator-authored `seller_entities` DB register (Wave 4),
 * then the `billing.seller` config. A deployment that never authored a seller resolves the
 * config exactly as before — the DB is a superset the console writes, never a required one.
 *
 * PLANE-DISTINCT NUMBERING. The DB register is plane-scoped, but the CONFIG fallback is not: every
 * plane that has authored no seller row resolves the same config entity — the same id AND the same
 * invoice prefix. Two planes would then mint identical legal document numbers (and, before the
 * sequence was keyed by plane, share one counter). The config fallback is therefore plane-marked
 * ({@see PlaneDocumentPrefix}): production resolves the configured prefix verbatim, every sandbox
 * resolves the same identity under its own prefix.
 */
readonly class SellerCatalog
{
    public function __construct(private Config $config, private BillingContext $context) {}

    /** The configured default selling entity — the DB `is_default` row, else the config default. */
    public function default(): SellerEntity
    {
        $default = SellerEntityModel::query()->where('is_default', true)->whereNull('archived_at')->first();

        if ($default instanceof SellerEntityModel) {
            return $this->fromModel($default);
        }

        $id = $this->config->get('billing.seller.default');

        return $this->entity(is_string($id) ? $id : 'cbox-dk');
    }

    /** The selling entity for `$id` — the DB row when present, else config, else a runtime error. */
    public function entity(string $id): SellerEntity
    {
        $model = SellerEntityModel::query()->whereKey($id)->first();

        if ($model instanceof SellerEntityModel) {
            return $this->fromModel($model);
        }

        return $this->fromConfig($id);
    }

    private function fromModel(SellerEntityModel $model): SellerEntity
    {
        $registrations = $model->taxRegistrations
            ->map(static fn ($registration): TaxRegistration => new TaxRegistration(
                country: new CountryCode($registration->country),
                number: $registration->number,
                subdivision: is_string($registration->subdivision) && $registration->subdivision !== ''
                    ? new SubdivisionCode($registration->subdivision)
                    : null,
                scheme: is_string($registration->scheme) && $registration->scheme !== '' ? $registration->scheme : null,
            ))
            ->all();

        return new SellerEntity(
            id: $model->id,
            legalName: $model->legal_name,
            registrationNumber: $model->registration_number,
            establishment: new CountryCode($model->establishment),
            defaultCurrency: $model->currency,
            invoicePrefix: $model->invoice_prefix,
            taxRegistrations: array_values($registrations),
        );
    }

    private function fromConfig(string $id): SellerEntity
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
            invoicePrefix: PlaneDocumentPrefix::for(
                self::str($definition, 'invoice_prefix'),
                $this->context->environmentKey(),
            ),
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
