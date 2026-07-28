<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\POS\Application\DTOs\PosPaymentPolicyDTO;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Shared\Domain\CashRoundingCaps;
use App\Shared\Domain\CurrencyScale;
use InvalidArgumentException;

/**
 * Resolves the POS payment policy for a company (spec §4.2).
 *
 * Country-row-only in v1 and FAIL-CLOSED everywhere:
 *   - no country row              => rounding OFF, tolerance OFF
 *   - `pos_tolerance_enabled`     => the ONLY switch that turns POS
 *                                    auto-accept on; the B2B
 *                                    `payment_tolerance_enabled` column is
 *                                    never inherited (and
 *                                    PaymentToleranceService's
 *                                    enabled-by-default fallback is never
 *                                    consulted)
 *   - `companies.payment_tolerance_enabled === false` force-disables;
 *     `true`/`null` defer to the country row (fail-closed DIRECTION only)
 *   - a denomination that does not round-trip at the company currency scale,
 *     is non-positive, or exceeds the static §4.1 cap for that scale is NEVER
 *     emitted — rounding is reported disabled instead
 *
 * Always returns a complete DTO; the device caches it verbatim.
 */
final class PosPaymentPolicyResolver
{
    /** Scale of the tolerance percentage FRACTION (not currency-scaled). */
    private const PERCENTAGE_SCALE = 4;

    /** Scale of the `country_payment_settings.cash_rounding_denomination` decimal(15,4) column. */
    private const DENOMINATION_STORAGE_SCALE = 4;

    public function forCompany(string $companyId): PosPaymentPolicyDTO
    {
        $company = Company::query()->findOrFail($companyId);

        $currencyCode = (string) $company->currency;
        $scale = $this->resolveScale($company, $currencyCode);

        /** @var CountryPaymentSettings|null $row */
        $row = CountryPaymentSettings::query()
            ->where('country_code', (string) $company->country_code)
            ->first();

        $zero = CurrencyScale::bcformatStrict('0', $scale);
        $refreshedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');

        if ($row === null) {
            return new PosPaymentPolicyDTO(
                companyId: $companyId,
                currencyCode: $currencyCode,
                currencyScale: $scale,
                cashRoundingEnabled: false,
                cashRoundingDenomination: $zero,
                tenderToleranceEnabled: false,
                tenderTolerancePercentage: CurrencyScale::bcformatStrict('0', self::PERCENTAGE_SCALE),
                tenderToleranceMaxAmount: $zero,
                refreshedAt: $refreshedAt,
            );
        }

        [$roundingEnabled, $denomination] = $this->resolveRounding($row, $scale, $zero);

        return new PosPaymentPolicyDTO(
            companyId: $companyId,
            currencyCode: $currencyCode,
            currencyScale: $scale,
            cashRoundingEnabled: $roundingEnabled,
            cashRoundingDenomination: $denomination,
            tenderToleranceEnabled: $this->resolveToleranceEnabled($row, $company),
            tenderTolerancePercentage: $this->normalize(
                $row->payment_tolerance_percentage,
                self::PERCENTAGE_SCALE,
            ) ?? CurrencyScale::bcformatStrict('0', self::PERCENTAGE_SCALE),
            tenderToleranceMaxAmount: $this->normalize($row->max_payment_tolerance_amount, $scale) ?? $zero,
            refreshedAt: $refreshedAt,
        );
    }

    /**
     * Resolve the company currency scale through the SAME source the rest of
     * the system uses for a company-bound resolution.
     *
     * `CurrencyScaleResolver::getScale()` reads `countries.currency_decimal_places`
     * FIRST and only falls back to the static ISO 4217 map
     * (`CurrencyScaleResolver.php:56-70`). Calling `CurrencyScale::for()` here
     * would be a SECOND scale source: on a tenant whose `countries` row diverges
     * from the ISO map, the device would cache a scale the server's own receipt
     * and Z math does not use — the cross-layer mismatch class that quarantines
     * receipts.
     *
     * The company path is mirrored explicitly rather than injecting
     * `CurrencyScaleResolverInterface` because that interface only consults the
     * `countries` column on its NO-ARGUMENT form (an explicit `$currencyCode`
     * short-circuits straight to the ISO map, `:38-41`), and the no-arg form
     * throws outside a bound `CompanyContext` — which this resolver must
     * survive (rule 20: queued/console callers have none).
     */
    private function resolveScale(Company $company, string $currencyCode): int
    {
        $decimalPlaces = Country::query()->find((string) $company->country_code)?->currency_decimal_places;

        // The column is a NOT-NULL tinyInteger, so the fallback only fires when
        // the country row itself is absent (unseeded lookup table).
        if (is_int($decimalPlaces)) {
            return $decimalPlaces;
        }

        return CurrencyScale::for($currencyCode);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function resolveRounding(CountryPaymentSettings $row, int $scale, string $zero): array
    {
        if (! (bool) $row->cash_rounding_enabled) {
            return [false, $zero];
        }

        $raw = $row->cash_rounding_denomination;
        if (! is_string($raw)) {
            return [false, $zero];
        }

        $scaled = $this->normalize($raw, $scale);
        if ($scaled === null) {
            return [false, $zero];
        }

        // Round-trip validity: the stored decimal(15,4) value must be exactly
        // representable at the company currency scale. bcformatStrict
        // TRUNCATES, so a value with digits past the scale (e.g. 0.0025 on
        // scale 3) collapses to a different number — reject it rather than
        // emit a denomination the device would sign but the server could not
        // reconstruct. The comparison runs at the WIDER of the two scales so
        // nothing is truncated away before the equality test.
        $comparisonScale = max(self::DENOMINATION_STORAGE_SCALE, $scale);
        $stored = $this->normalize($raw, $comparisonScale);
        if ($stored === null || bccomp($scaled, $stored, $comparisonScale) !== 0) {
            return [false, $zero];
        }

        if (bccomp($scaled, '0', $scale) <= 0) {
            return [false, $zero];
        }

        // Static §4.1 ceiling. This resolver is the LAST server gate before the
        // value becomes signed device bytes: an oversized denomination (5.000 on
        // scale 3) would be cached, signed, and then rejected by the Task-6
        // validator bind — quarantining 100% of the tenant's receipts. The cap
        // table is shared with the Task-4 ops command and the Task-6 validator
        // (CashRoundingCaps) precisely so the two ends cannot drift.
        // An unlisted scale has no sanctioned cap => fail closed.
        if (! CashRoundingCaps::isWithinCap($scaled, $scale)) {
            return [false, $zero];
        }

        return [true, $scaled];
    }

    private function resolveToleranceEnabled(CountryPaymentSettings $row, Company $company): bool
    {
        if (! (bool) $row->pos_tolerance_enabled) {
            return false;
        }

        // Fail-closed DIRECTION override only: an explicit company `false`
        // disables; `true` / `null` defer to the country row.
        if ($company->payment_tolerance_enabled === false) {
            return false;
        }

        return true;
    }

    /**
     * Re-scale a stored decimal string, or null when it is unusable.
     *
     * Fail-closed by construction: anything that is not a PLAIN decimal
     * literal (NULL column, empty string, and in particular the scientific
     * notation is_numeric() accepts but bcmath cannot parse) yields null and
     * the caller substitutes the canonical zero rather than emitting garbage.
     *
     * @return numeric-string|null
     */
    private function normalize(mixed $value, int $scale): ?string
    {
        if (! is_string($value) || preg_match('/^[+-]?\d+(\.\d+)?$/', trim($value)) !== 1) {
            return null;
        }

        try {
            return CurrencyScale::bcformatStrict($value, $scale);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
