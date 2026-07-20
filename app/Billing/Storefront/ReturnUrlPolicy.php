<?php

declare(strict_types=1);

namespace App\Billing\Storefront;

use App\Billing\Notifications\Branding\SellerBranding;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The allow-list guard for the public paywall's caller-supplied `return_url` (#57). The paywall
 * is served under no auth, so an unvalidated `return_url` on the "maybe later" CTA is an open
 * redirect. This confines it to the seller's own known/branding hosts (deny-by-default): the
 * app's own origin, any explicitly configured storefront hosts, and the resolved seller
 * branding's support/logo hosts. An off-domain URL is refused; a same-host one is accepted.
 */
readonly class ReturnUrlPolicy
{
    public function __construct(private Config $config) {}

    /**
     * Whether `$url` returns to a seller-known host. A syntactically invalid URL, or one with no
     * host, or a host not on the allow-list, is refused.
     */
    public function allows(string $url, ?SellerBranding $branding = null): bool
    {
        $host = $this->hostOf($url);

        if ($host === null) {
            return false;
        }

        return in_array($host, $this->allowedHosts($branding), true);
    }

    /**
     * The lower-cased hosts a `return_url` may point at: the app origin, the configured storefront
     * origins, any explicit `return_url_allowed_hosts`, and the seller branding's own hosts.
     *
     * @return list<string>
     */
    public function allowedHosts(?SellerBranding $branding = null): array
    {
        $hosts = [];

        foreach ($this->configuredUrls() as $url) {
            $host = $this->hostOf($url);
            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        foreach ($this->explicitHosts() as $host) {
            $normalized = $this->normalizeHost($host);
            if ($normalized !== null) {
                $hosts[] = $normalized;
            }
        }

        if ($branding !== null) {
            foreach ([$branding->supportUrl, $branding->logoUrl] as $url) {
                $host = $url !== null ? $this->hostOf($url) : null;
                if ($host !== null) {
                    $hosts[] = $host;
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    /** @return list<string> */
    private function configuredUrls(): array
    {
        $urls = [];

        foreach (['app.url', 'billing.storefront.embed_base_url', 'billing.storefront.checkout_url'] as $key) {
            $value = $this->config->get($key);
            if (is_string($value) && $value !== '') {
                $urls[] = $value;
            }
        }

        return $urls;
    }

    /** @return list<string> */
    private function explicitHosts(): array
    {
        $value = $this->config->get('billing.storefront.return_url_allowed_hosts');

        if (! is_array($value)) {
            return [];
        }

        $hosts = [];
        foreach ($value as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /** The lower-cased host of an absolute URL, or null when it has none. */
    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** Normalise a bare host or a host:port/URL into a lower-cased host, or null. */
    private function normalizeHost(string $host): ?string
    {
        if (str_contains($host, '://')) {
            return $this->hostOf($host);
        }

        $host = strtolower(trim($host));
        // Strip any accidental port or path so the allow-list stays host-only.
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];

        return $host !== '' ? $host : null;
    }
}
