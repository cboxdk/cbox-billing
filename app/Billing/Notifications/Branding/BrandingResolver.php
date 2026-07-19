<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Branding;

use App\Models\SellerEntity;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Assembles the {@see SellerBranding} for an email: the selling entity's own authored brand,
 * with any unset field filled from the app-level defaults (config('billing.mail.branding')).
 * A seller that never authored branding resolves to the app defaults; a fully-branded seller
 * overrides all of them. The default seller (is_default) is used when no id is given, so
 * lifecycle events that are not tied to a specific issuing entity still brand consistently.
 */
readonly class BrandingResolver
{
    public function __construct(private Config $config) {}

    public function forSeller(?string $sellerEntityId): SellerBranding
    {
        $entity = $this->resolveEntity($sellerEntityId);
        $productName = $this->defaultString('product_name', 'Cbox Billing');

        return new SellerBranding(
            sellerEntityId: $entity !== null ? $entity->id : null,
            productName: $productName,
            brandColor: $this->firstNonEmpty($entity !== null ? $entity->brand_color : null, $this->defaultString('brand_color', '#2743b3')),
            logoUrl: $this->firstNullable($entity !== null ? $entity->logo_url : null, $this->defaultNullable('logo_url')),
            fromName: $this->firstNonEmpty($entity !== null ? $entity->from_name : null, $this->defaultString('from_name', 'Cbox Billing')),
            fromEmail: $this->firstNonEmpty($entity !== null ? $entity->from_email : null, $this->defaultString('from_email', 'billing@example.com')),
            replyTo: $this->firstNullable($entity !== null ? $entity->reply_to : null, $this->defaultNullable('reply_to')),
            legalName: $entity !== null ? $entity->legal_name : $productName,
            registrationNumber: $entity !== null ? $entity->registration_number : '',
            footerAddress: $entity !== null ? $entity->footer_address : null,
            supportUrl: $this->firstNullable($entity !== null ? $entity->support_url : null, $this->defaultNullable('support_url')),
            supportEmail: $this->firstNullable($entity !== null ? $entity->support_email : null, $this->defaultNullable('support_email')),
        );
    }

    private function resolveEntity(?string $sellerEntityId): ?SellerEntity
    {
        if ($sellerEntityId !== null && $sellerEntityId !== '') {
            $entity = SellerEntity::query()->whereKey($sellerEntityId)->first();

            if ($entity instanceof SellerEntity) {
                return $entity;
            }
        }

        return SellerEntity::query()->where('is_default', true)->whereNull('archived_at')->first()
            ?? SellerEntity::query()->whereNull('archived_at')->orderBy('id')->first();
    }

    private function firstNonEmpty(?string $preferred, string $fallback): string
    {
        return $preferred !== null && trim($preferred) !== '' ? $preferred : $fallback;
    }

    private function firstNullable(?string $preferred, ?string $fallback): ?string
    {
        return $preferred !== null && trim($preferred) !== '' ? $preferred : $fallback;
    }

    private function defaultString(string $key, string $fallback): string
    {
        $value = $this->config->get('billing.mail.branding.'.$key);

        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }

    private function defaultNullable(string $key): ?string
    {
        $value = $this->config->get('billing.mail.branding.'.$key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
