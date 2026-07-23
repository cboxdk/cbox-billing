<?php

declare(strict_types=1);

namespace App\Billing\Nexus;

/**
 * The US states + DC, keyed by ISO 3166-2 code — the operator-facing list the nexus console
 * offers when declaring physical presence or external-channel sales. Reference data (not a tax
 * determination): which of these actually carry an economic-nexus threshold comes from the
 * us-tax-data dataset via the engine, not from here.
 */
final class UsStates
{
    /** @var array<string, string> code => name */
    private const STATES = [
        'US-AL' => 'Alabama', 'US-AK' => 'Alaska', 'US-AZ' => 'Arizona', 'US-AR' => 'Arkansas',
        'US-CA' => 'California', 'US-CO' => 'Colorado', 'US-CT' => 'Connecticut', 'US-DE' => 'Delaware',
        'US-DC' => 'District of Columbia', 'US-FL' => 'Florida', 'US-GA' => 'Georgia', 'US-HI' => 'Hawaii',
        'US-ID' => 'Idaho', 'US-IL' => 'Illinois', 'US-IN' => 'Indiana', 'US-IA' => 'Iowa',
        'US-KS' => 'Kansas', 'US-KY' => 'Kentucky', 'US-LA' => 'Louisiana', 'US-ME' => 'Maine',
        'US-MD' => 'Maryland', 'US-MA' => 'Massachusetts', 'US-MI' => 'Michigan', 'US-MN' => 'Minnesota',
        'US-MS' => 'Mississippi', 'US-MO' => 'Missouri', 'US-MT' => 'Montana', 'US-NE' => 'Nebraska',
        'US-NV' => 'Nevada', 'US-NH' => 'New Hampshire', 'US-NJ' => 'New Jersey', 'US-NM' => 'New Mexico',
        'US-NY' => 'New York', 'US-NC' => 'North Carolina', 'US-ND' => 'North Dakota', 'US-OH' => 'Ohio',
        'US-OK' => 'Oklahoma', 'US-OR' => 'Oregon', 'US-PA' => 'Pennsylvania', 'US-RI' => 'Rhode Island',
        'US-SC' => 'South Carolina', 'US-SD' => 'South Dakota', 'US-TN' => 'Tennessee', 'US-TX' => 'Texas',
        'US-UT' => 'Utah', 'US-VT' => 'Vermont', 'US-VA' => 'Virginia', 'US-WA' => 'Washington',
        'US-WV' => 'West Virginia', 'US-WI' => 'Wisconsin', 'US-WY' => 'Wyoming',
    ];

    /** @return array<string, string> code => name */
    public static function all(): array
    {
        return self::STATES;
    }

    /** @return list<string> the valid ISO 3166-2 codes */
    public static function codes(): array
    {
        return array_keys(self::STATES);
    }

    public static function name(string $code): string
    {
        return self::STATES[$code] ?? $code;
    }
}
