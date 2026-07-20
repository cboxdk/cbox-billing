<?php

declare(strict_types=1);

namespace App\Billing\Tax\Exemptions;

/**
 * The jurisdictions an exemption certificate can be scoped to, and how to validate/label one.
 * US sales-tax exemptions are state-scoped (ISO 3166-2, e.g. `US-CA`) with a federal (`US`)
 * option for government/federal grounds; a small set of country codes covers the generic
 * non-US path. The stored value is what {@see TaxExemptionCertificate::coversPlace()} matches.
 */
class ExemptionJurisdictions
{
    /** ISO 3166-2 subdivision code (bare, without the `US-` prefix) => state name. */
    private const US_STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
        'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
        'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
        'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
        'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
        'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
        'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
        'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /** ISO 3166-1 alpha-2 => country name, for the generic (non-US) exemption path. */
    private const COUNTRIES = [
        'US' => 'United States (federal)',
        'CA' => 'Canada', 'GB' => 'United Kingdom', 'AU' => 'Australia', 'NZ' => 'New Zealand',
        'DK' => 'Denmark', 'DE' => 'Germany', 'FR' => 'France', 'NL' => 'Netherlands', 'SE' => 'Sweden',
    ];

    /**
     * The grouped option list a select renders: US states (as `US-XX`) and the country/federal
     * codes. Value => human label.
     *
     * @return array{states: array<string, string>, countries: array<string, string>}
     */
    public static function options(): array
    {
        $states = [];
        foreach (self::US_STATES as $code => $name) {
            $states['US-'.$code] = sprintf('%s (US-%s)', $name, $code);
        }

        return ['states' => $states, 'countries' => self::COUNTRIES];
    }

    /**
     * Every acceptable stored jurisdiction value (states + countries).
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        return array_values(array_unique(array_merge(
            array_map(static fn (string $code): string => 'US-'.$code, array_keys(self::US_STATES)),
            array_keys(self::COUNTRIES),
        )));
    }

    public static function isValid(string $jurisdiction): bool
    {
        return in_array(strtoupper(trim($jurisdiction)), self::allowed(), true);
    }

    /** A human label for a stored jurisdiction value, or the value itself when unknown. */
    public static function label(string $jurisdiction): string
    {
        $value = strtoupper(trim($jurisdiction));

        if (str_starts_with($value, 'US-')) {
            $state = substr($value, 3);

            return isset(self::US_STATES[$state]) ? self::US_STATES[$state].' (US)' : $value;
        }

        return self::COUNTRIES[$value] ?? $value;
    }
}
