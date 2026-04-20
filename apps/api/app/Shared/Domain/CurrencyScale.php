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
     * @param  string|int|float|null  $value  The numeric value (string preferred to avoid float)
     * @param  int  $scale  Number of decimal places
     * @return numeric-string Formatted decimal string
     */
    public static function bcformat(string|int|float|null $value, int $scale): string
    {
        if ($value === null) {
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
