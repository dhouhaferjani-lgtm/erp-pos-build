<?php

declare(strict_types=1);

namespace App\Shared\Domain\Validation;

/**
 * Canonical per-country tax-number formats — the SINGLE SOURCE OF TRUTH.
 *
 * Every entry point that ALREADY validates a tax number consumes this class:
 * partner requests (`vat_number`), location requests (`tax_id`), the advisory
 * `TaxIdValidationService`, and the sealed-payload gate
 * (`FiscalPayloadConstraintValidator::TAX_NUMBER_PATTERNS` IS this table).
 * The device fiscal engine (`apps/pos/src/lib/fiscal/FiscalEventEngine.ts`)
 * mirrors these literals byte-for-byte; the mirror is guarded from both sides
 * by `Tests\Unit\Shared\TunisianMatriculeConvergenceTest` and
 * `apps/pos/src/lib/fiscal/__tests__/taxNumberPatterns.test.ts`.
 *
 * KNOWN GAP (pre-existing, deliberately NOT closed in this lane): the COMPANY
 * `tax_id` — the value that becomes `seller.tax_number` at seal time — has no
 * format rule at any entry point, and neither has company `vat_number`
 * (`CreateCompanyRequest.php:28,30` and `UpdateCompanyRequest.php:45,47` are
 * all `['nullable','string','max:50']`). So this class is the single source of
 * truth for the rules that exist, not proof that every sealed identifier has
 * been validated on the way in. Tracked, with the lane's other residuals, in
 * `docs/superpowers/tickets/2026-08-23-company-tax-id-no-entry-validation.md`.
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
     *
     * `$countryCode` MUST already be the canonical uppercase ISO code. The
     * comparison is case-SENSITIVE on purpose: it is byte-identical to the
     * inline normalizer this replaced, and it matches the case-sensitive
     * `PATTERNS` lookup that decides whether a pattern applies at all. Making
     * either step case-insensitive on its own would let a payload whose
     * country arrived as `'tn'` start being pattern-checked when it was not
     * before — i.e. a previously-sealed payload could become invalid on
     * verify-chain replay (gate R1 F-5).
     */
    public static function normalizeForMatching(string $countryCode, string $value): string
    {
        if ($countryCode === 'TN') {
            return str_replace('/', '', $value);
        }

        return $value;
    }

    /**
     * Entry-boundary canonicalization: turn operator input into the value that
     * is STORED and later sealed.
     *
     * The stored convention for TN is the COMPACT UPPERCASE form — verified
     * against `DemoPharmacySeeder` (`1234567AM000`) and the canonical device
     * payload fixtures (`AccountChargePayload.ts` seals `1234567AM000`). So
     * `1234567/A/M/000` and `1234567 am 000` both normalize to `1234567AM000`.
     *
     * Counter-example, stated so the convention is not over-trusted:
     * `CoffeeShopSeeder.php:283,332` seeds a TN tenant/company `tax_id` of
     * `1234567A` — 8 characters, short-form legacy demo data that the
     * converged rule REJECTS (`matches('TN','1234567A') === false`). It is
     * demo-only and predates this rule; it is NOT evidence for the convention.
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
