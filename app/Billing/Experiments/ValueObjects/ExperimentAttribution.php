<?php

declare(strict_types=1);

namespace App\Billing\Experiments\ValueObjects;

/**
 * The attribution triple that threads a running experiment through the checkout deep-link, so a
 * conversion the operator's checkout completes is traced back to the exact variant a visitor saw.
 *
 * It is carried on every CTA URL of a served variant as three query params
 * ({@see EXPERIMENT_PARAM} / {@see VARIANT_PARAM} / {@see VISITOR_PARAM}). The operator's checkout
 * entry forwards them to `POST /api/v1/checkout-sessions` (as `experiment`/`variant`/`visitor`),
 * which stamps the minted hosted-checkout session and records the checkout-started conversion; on
 * settlement the same session drives the checkout-completed conversion. The visitor id is the
 * opaque anonymous cookie id — no PII crosses the boundary.
 */
readonly class ExperimentAttribution
{
    /** The CTA query-param names (kept as one source of truth for the builder and the API). */
    public const string EXPERIMENT_PARAM = 'cbox_exp';

    public const string VARIANT_PARAM = 'cbox_var';

    public const string VISITOR_PARAM = 'cbox_vid';

    public function __construct(
        public string $experimentKey,
        public int $variantId,
        public string $visitorId,
    ) {}

    /**
     * The attribution as CTA query params (appended to every served-variant CTA link).
     *
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        return [
            self::EXPERIMENT_PARAM => $this->experimentKey,
            self::VARIANT_PARAM => (string) $this->variantId,
            self::VISITOR_PARAM => $this->visitorId,
        ];
    }
}
