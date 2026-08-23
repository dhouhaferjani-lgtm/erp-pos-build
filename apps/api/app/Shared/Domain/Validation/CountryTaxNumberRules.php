<?php

declare(strict_types=1);

namespace App\Shared\Domain\Validation;

/**
 * Canonical per-country tax-number formats — the SINGLE SOURCE OF TRUTH.
 *
 * Every entry point that validates a tax number consumes this class:
 * partner requests (`vat_number`), location requests (`tax_id`), the advisory
 * `TaxIdValidationService`, and the sealed-payload gate
 * (`FiscalPayloadConstraintValidator::TAX_NUMBER_PATTERNS` IS this table).
 * The device fiscal engine (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts`)
 * mirrors these literals byte-for-byte; the mirror is guarded by
 * `Tests\Unit\Shared\TunisianMatriculeConvergenceTest`.
 *
 * Divergence here is a P0: a value accepted at entry but rejected at seal time
 * makes the document unsealable (research spec 2026-08-23 §3.3).
 */
final class CountryTaxNumberRules
{
    /** @var array<string, string> */
    public const PATTERNS = [
        'FR' => '/^([0-9]{9}|[0-9]{14})$/D',
        /*
         * Tunisian matricule fiscale, COMPACT form.
         *
         * 7-8 digits + 2 OR 3 letters + 3-digit establishment number.
         *   - the 3-letter arm is the canonical 13-character MF
         *     (control key + VAT status + activity category), e.g. `1234567AMN000`;
         *   - the 2-letter arm is the 12-character form that the previous
         *     pattern `/^[0-9]{7,8}[A-Z]{2}[0-9]{3}$/D` accepted and that is
         *     therefore ALREADY PRESENT IN SEALED BYTES.
         *
         * The only change from that previous pattern is the quantifier
         * `{2}` -> `{2,3}`, so the language is a strict SUPERSET and no
         * historical sealed payload becomes retroactively invalid on
         * verify-chain replay. Proven in TunisianMatriculeConvergenceTest.
         */
        'TN' => '/^[0-9]{7,8}[A-Z]{2,3}[0-9]{3}$/D',
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

        return preg_match($pattern, self::normalizeForMatching($country, $value)) === 1;
    }

    /**
     * Match-time canonicalization — MUST stay byte-identical to
     * `FiscalPayloadConstraintValidator::normalizeTaxNumberForCountry()` and to
     * the device's `normalizeTaxNumberForCountry()`.
     *
     * Deliberately minimal: it strips ONLY the TN long-form slashes. Anything
     * more permissive here would accept values the seal boundary rejects,
     * which is the exact defect this class exists to prevent. Aggressive input
     * cleanup belongs at the entry boundary — see `normalizeForStorage()`.
     */
    public static function normalizeForMatching(string $countryCode, string $value): string
    {
        if (strtoupper($countryCode) === 'TN') {
            return str_replace('/', '', $value);
        }

        return $value;
    }

    /**
     * Entry-boundary canonicalization: turn operator input into the value that
     * is STORED and later sealed.
     *
     * The stored convention for TN is the COMPACT UPPERCASE form — verified
     * against seeded data (`DemoPharmacySeeder` stores `1234567AM000`,
     * `CoffeeShopSeeder` stores `1234567A`) and against the canonical device
     * payload fixtures (`AccountChargePayload.ts` seals `1234567AM000`). So
     * `1234567/A/M/000` and `1234567 am 000` both normalize to `1234567AM000`.
     *
     * Only TN is normalized; other countries are returned untouched so no
     * non-TN behaviour changes.
     */
    public static function normalizeForStorage(string $countryCode, string $value): string
    {
        if (strtoupper($countryCode) !== 'TN') {
            return $value;
        }

        return strtoupper(str_replace(['/', ' ', "\t"], '', $value));
    }
}
