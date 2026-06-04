<?php

declare(strict_types=1);

namespace App\Shared\Domain\Validation;

/**
 * Canonical per-country tax-number formats for entry-time validation.
 *
 * Patterns and normalization must stay in parity with
 * FiscalPayloadConstraintValidator's fiscal payload rules.
 */
final class CountryTaxNumberRules
{
    /** @var array<string, string> */
    public const PATTERNS = [
        'FR' => '/^([0-9]{9}|[0-9]{14})$/D',
        'TN' => '/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D',
        'SA' => '/^3[0-9]{12}03$/D',
        'DE' => '/^DE[0-9]{9}$/D',
        'IT' => '/^[0-9]{11}$/D',
    ];

    public static function matches(string $countryCode, string $value): bool
    {
        $country = strtoupper($countryCode);
        $pattern = self::PATTERNS[$country] ?? null;

        if ($pattern === null) {
            return true;
        }

        return preg_match($pattern, self::normalize($country, $value)) === 1;
    }

    private static function normalize(string $countryCode, string $value): string
    {
        if ($countryCode === 'TN') {
            return str_replace('/', '', $value);
        }

        return $value;
    }
}
