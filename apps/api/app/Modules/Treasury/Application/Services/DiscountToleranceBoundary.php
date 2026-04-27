<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\Exceptions\DiscountBelowToleranceException;

/**
 * Anti-abuse boundary check (spec §7): a discount is invalid if its absolute
 * amount is at-or-below the applicable payment-tolerance margin for the same
 * transaction.
 *
 *   tolerance_margin(subtotal, settings) =
 *       max(settings.max_amount, subtotal × settings.percentage)
 *
 *   discount valid iff discount_amount > tolerance_margin
 *
 * The strict-greater inequality (discount equal-to-margin REJECTS) closes the
 * sub-tolerance arbitrage where a cashier could otherwise engineer a write-off
 * shaped as a discount, simultaneously hiding skimming and reducing VAT base.
 *
 * Reuses the existing {@see PaymentToleranceService::getToleranceSettings()}
 * for company-aware threshold resolution (Company override → Country default
 * → System default), keeping a single source of truth for tolerance
 * configuration. Internal scale-4 normalisation matches the tolerance pipeline.
 */
final class DiscountToleranceBoundary
{
    private const SCALE = 4;

    public function __construct(
        private readonly PaymentToleranceService $toleranceService,
    ) {}

    /**
     * @param  string|null  $currencyCode  ISO 4217 code of the document /
     *                                     receipt being checked. Used solely
     *                                     to render the exception message at
     *                                     the currency's native scale; null
     *                                     falls back to the system default.
     *
     * @throws DiscountBelowToleranceException when the discount fails the boundary.
     */
    public function assertDiscountAboveTolerance(
        string $discountAmount,
        string $subtotal,
        string $companyId,
        ?string $currencyCode = null,
    ): void {
        // Inputs come from validated form data (`numeric` rule) or from
        // internal bc-formatted services. Coerce non-numeric input to 0 so a
        // bad caller short-circuits to the no-discount branch rather than
        // raising a TypeError on bcadd.
        $normalisedDiscount = is_numeric($discountAmount) ? $discountAmount : '0';
        $normalisedSubtotal = is_numeric($subtotal) ? $subtotal : '0';

        $discount = bcadd($normalisedDiscount, '0', self::SCALE);

        // A zero (or absent) discount is "no discount applied" — never a violation.
        if (bccomp($discount, '0', self::SCALE) === 0) {
            return;
        }

        $settings = $this->toleranceService->getToleranceSettings($companyId);

        // If tolerance is disabled at every level, the boundary is moot — the
        // payment side cannot write off and the discount side cannot abuse it.
        // Pass through silently rather than blocking otherwise-legitimate
        // small discounts on a tolerance-disabled company.
        if ($settings['enabled'] === false) {
            return;
        }

        $percentageMargin = bcmul($normalisedSubtotal, $settings['percentage'], self::SCALE);
        $absoluteMargin = $settings['max_amount'];

        $margin = bccomp($percentageMargin, $absoluteMargin, self::SCALE) > 0
            ? $percentageMargin
            : $absoluteMargin;

        // Strict inequality: discount must be STRICTLY greater than the margin.
        if (bccomp($discount, $margin, self::SCALE) <= 0) {
            throw new DiscountBelowToleranceException(
                discountAmount: $discount,
                toleranceMargin: $margin,
                subtotal: $subtotal,
                currencyCode: $currencyCode,
            );
        }
    }
}
