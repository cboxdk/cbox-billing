<?php

declare(strict_types=1);

namespace App\Billing\Notifications;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Resolves the locale a transactional email renders in, walking the chain:
 * customer/org locale → selling entity's default locale → app fallback. Every candidate is
 * normalized and checked against the supported set (config('billing.mail.locales')); an
 * unsupported candidate is skipped, so a stale or bogus value never dead-ends the send — the
 * app fallback (which is always supported) is the floor.
 */
readonly class LocaleResolver
{
    public function __construct(private Config $config) {}

    /** The candidate chain, first supported one wins; the fallback always closes it out. */
    public function resolve(?string $orgLocale, ?string $sellerDefaultLocale): string
    {
        foreach ([$orgLocale, $sellerDefaultLocale] as $candidate) {
            $normalized = $this->normalize($candidate);

            if ($normalized !== null && $this->isSupported($normalized)) {
                return $normalized;
            }
        }

        return $this->fallback();
    }

    public function fallback(): string
    {
        $fallback = $this->config->get('billing.mail.fallback_locale');
        $normalized = is_string($fallback) ? $this->normalize($fallback) : null;

        return $normalized !== null && $this->isSupported($normalized) ? $normalized : 'en';
    }

    /** @return array<string, string> code → display label */
    public function supported(): array
    {
        $locales = $this->config->get('billing.mail.locales');

        if (! is_array($locales) || $locales === []) {
            return ['en' => 'English'];
        }

        $out = [];
        foreach ($locales as $code => $label) {
            $out[strtolower((string) $code)] = is_string($label) ? $label : (string) $code;
        }

        return $out;
    }

    public function isSupported(string $locale): bool
    {
        return array_key_exists(strtolower($locale), $this->supported());
    }

    /** Lowercase + trim; an empty/whitespace candidate resolves to null (skipped). */
    public function normalize(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $trimmed = strtolower(trim($locale));

        return $trimmed === '' ? null : $trimmed;
    }
}
