<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Rendering;

use App\Billing\Notifications\Contracts\ResolvesMailTemplates;
use App\Billing\Notifications\LocaleResolver;
use App\Billing\Notifications\MailEventType;
use App\Models\MailTemplate;

/**
 * The resolution chain. For an (event, locale, seller) it returns the first template that
 * exists, in strictly descending specificity:
 *
 *   1. DB override for (seller, requested locale)
 *   2. DB override for (seller, fallback locale)
 *   3. DB override for (account-wide / null seller, requested locale)
 *   4. DB override for (account-wide, fallback locale)
 *   5. shipped default (requested locale)
 *   6. shipped default (fallback locale)
 *
 * Because the shipped default in the fallback locale is guaranteed present for every event
 * type, resolution NEVER dead-ends — there is always something correct to render. The layer
 * that served is reported on the {@see ResolvedTemplate} so the console can show "default" vs
 * "overridden".
 */
readonly class MailTemplateResolver implements ResolvesMailTemplates
{
    public function __construct(
        private DefaultMailTemplates $defaults,
        private LocaleResolver $locales,
    ) {}

    public function resolve(MailEventType $event, string $locale, ?string $sellerEntityId): ResolvedTemplate
    {
        $locale = $this->locales->normalize($locale) ?? $this->locales->fallback();
        $fallback = $this->locales->fallback();

        // 1–2: seller-scoped overrides (only when a seller is given).
        if ($sellerEntityId !== null && $sellerEntityId !== '') {
            if ($row = $this->dbRow($event, $locale, $sellerEntityId)) {
                return $this->fromRow($event, $row, $locale, TemplateSource::SellerLocale);
            }

            if ($fallback !== $locale && ($row = $this->dbRow($event, $fallback, $sellerEntityId))) {
                return $this->fromRow($event, $row, $fallback, TemplateSource::SellerFallback);
            }
        }

        // 3–4: account-wide overrides (null seller).
        if ($row = $this->dbRow($event, $locale, null)) {
            return $this->fromRow($event, $row, $locale, TemplateSource::GlobalLocale);
        }

        if ($fallback !== $locale && ($row = $this->dbRow($event, $fallback, null))) {
            return $this->fromRow($event, $row, $fallback, TemplateSource::GlobalFallback);
        }

        // 5–6: shipped defaults.
        if ($shipped = $this->defaults->get($event, $locale)) {
            return new ResolvedTemplate($event, $shipped['subject'], $shipped['body'], $locale, TemplateSource::ShippedLocale);
        }

        $shipped = $this->defaults->get($event, $fallback);

        // The fallback-locale shipped default is guaranteed for every event; an empty last
        // resort still renders something valid rather than throwing into the send path.
        return new ResolvedTemplate(
            $event,
            $shipped['subject'] ?? $event->label(),
            $shipped['body'] ?? '',
            $fallback,
            TemplateSource::ShippedFallback,
        );
    }

    private function dbRow(MailEventType $event, string $locale, ?string $sellerEntityId): ?MailTemplate
    {
        $query = MailTemplate::query()
            ->where('event_type', $event->value)
            ->where('locale', $locale);

        $query = $sellerEntityId === null
            ? $query->whereNull('seller_entity_id')
            : $query->where('seller_entity_id', $sellerEntityId);

        return $query->first();
    }

    private function fromRow(MailEventType $event, MailTemplate $row, string $locale, TemplateSource $source): ResolvedTemplate
    {
        return new ResolvedTemplate($event, $row->subject, $row->body, $locale, $source);
    }
}
