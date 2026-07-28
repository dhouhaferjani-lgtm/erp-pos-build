<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Application\DTOs\PosPaymentPolicyDTO;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
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
 *   - a denomination that does not round-trip at the company currency scale
 *     is NEVER emitted — rounding is reported disabled instead
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
        $scale = CurrencyScale::for($currencyCode);

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
