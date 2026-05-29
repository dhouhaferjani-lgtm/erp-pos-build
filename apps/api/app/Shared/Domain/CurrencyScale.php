<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Value object representing the decimal scale for a currency.
 *
 * Uses the ISO 4217 standard for currency decimal places.
 * The global maximum is 3 (used by TND, LYD, BHD, IQD, JOD, KWD, OMR).
 */
final class CurrencyScale extends ValueObject
{
    /**
     * ISO 4217 currencies with non-standard (not 2) decimal places.
     *
     * @var array<string, int>
     */
    private const SCALE_MAP = [
        // 3 decimal places (MENA region)
        'BHD' => 3, // Bahraini Dinar
        'IQD' => 3, // Iraqi Dinar
        'JOD' => 3, // Jordanian Dinar
        'KWD' => 3, // Kuwaiti Dinar
        'LYD' => 3, // Libyan Dinar
        'OMR' => 3, // Omani Rial
        'TND' => 3, // Tunisian Dinar

        // 0 decimal places
        'BIF' => 0, // Burundian Franc
        'CLP' => 0, // Chilean Peso
        'DJF' => 0, // Djiboutian Franc
        'GNF' => 0, // Guinean Franc
        'ISK' => 0, // Icelandic Krona
        'JPY' => 0, // Japanese Yen
        'KMF' => 0, // Comorian Franc
        'KRW' => 0, // South Korean Won
        'PYG' => 0, // Paraguayan Guarani
        'RWF' => 0, // Rwandan Franc
        'UGX' => 0, // Ugandan Shilling
        'VND' => 0, // Vietnamese Dong
        'VUV' => 0, // Vanuatu Vatu
        'XAF' => 0, // CFA Franc BEAC
        'XOF' => 0, // CFA Franc BCEAO
        'XPF' => 0, // CFP Franc
    ];

    private const DEFAULT_SCALE = 2;

    private readonly int $scale;

    public function __construct(
        private readonly string $currencyCode
    ) {
        $this->scale = self::for($currencyCode);
    }

    /**
     * Get the decimal scale for a given ISO 4217 currency code.
     */
    public static function for(string $currencyCode): int
    {
        return self::SCALE_MAP[strtoupper($currencyCode)] ?? self::DEFAULT_SCALE;
    }

    /**
     * Get the scale value.
     */
    public function value(): int
    {
        return $this->scale;
    }

    /**
     * Format a numeric value to a fixed decimal scale using bcmath (no float conversion).
     *
     * Replaces `number_format((float) $value, $scale, '.', '')` which suffers from
     * IEEE 754 floating-point precision loss (e.g. 5.000 → 4.9999).
     *
     * @deprecated Passing null is deprecated and will be removed in a future version.
     *             Use bcformatOrNull() to explicitly handle nullable values, or
     *             bcformatStrict() when the value is guaranteed non-null and numeric.
     *
     * @param  string|int|float|null  $value  The numeric value (string preferred to avoid float)
     * @param  int  $scale  Number of decimal places
     * @return numeric-string Formatted decimal string
     */
    public static function bcformat(string|int|float|null $value, int $scale): string
    {
        if ($value === null) {
            trigger_error(
                'CurrencyScale::bcformat() called with null. Null will zero-fill silently — '
                .'use bcformatOrNull() to preserve null or bcformatStrict() for guaranteed-non-null paths.',
                E_USER_DEPRECATED,
            );
            $str = '0';
        } elseif (is_float($value)) {
            // Avoid scientific notation from (string) cast (e.g., 1e-5 → "1.0E-5")
            // which bcmath cannot parse. Use number_format with enough precision.
            $str = number_format($value, max($scale, 14), '.', '');
        } else {
            $str = (string) $value;
        }

        // Handle empty/whitespace strings
        if (trim($str) === '') {
            $str = '0';
        }

        /** @phpstan-ignore argument.type */
        return bcadd($str, '0', $scale);
    }

    /**
     * Format a numeric string to a fixed decimal scale using bcmath.
     *
     * Strict variant: rejects any input that is not a well-formed numeric string.
     * Does NOT accept null, empty strings, or whitespace-only strings.
     * Does NOT accept float (use string representation from the source instead).
     * Leading/trailing whitespace is trimmed before validation and bcmath processing.
     *
     * Guard uses is_numeric() semantics. Note: PHP's is_numeric() accepts scientific
     * notation (e.g. "1e5") but bcmath does not — passing scientific notation will
     * pass this guard but bcadd() will throw a ValueError. Callers must normalise
     * scientific notation before calling this method.
     *
     * @param  string  $value  A well-formed numeric string (e.g. "5.000", "-3.14", "42")
     * @param  int  $scale  Number of decimal places
     * @return numeric-string Formatted decimal string
     *
     * @throws \InvalidArgumentException If $value is not a well-formed numeric string
     */
    public static function bcformatStrict(string $value, int $scale): string
    {
        $trimmed = trim($value);

        if (! is_numeric($trimmed)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'CurrencyScale::bcformatStrict() expects a numeric string; "%s" given.',
                    $value,
                ),
            );
        }

        /** @phpstan-ignore argument.type */
        return bcadd($trimmed, '0', $scale);
    }

    /**
     * Format a nullable numeric string to a fixed decimal scale using bcmath.
     *
     * Preserves null — does NOT zero-fill. For non-null input, delegates to
     * bcformatStrict() and will throw if the string is not numeric.
     *
     * @param  string|null  $value  A well-formed numeric string or null
     * @param  int  $scale  Number of decimal places
     * @return numeric-string|null Formatted decimal string, or null if input was null
     *
     * @throws \InvalidArgumentException If $value is non-null and not a well-formed numeric string
     */
    public static function bcformatOrNull(?string $value, int $scale): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::bcformatStrict($value, $scale);
    }

    public function equals(ValueObject $other): bool
    {
        if (! $other instanceof self) {
            return false;
        }

        return strtoupper($this->currencyCode) === strtoupper($other->currencyCode);
    }

    public function __toString(): string
    {
        return (string) $this->scale;
    }
}
