<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Modules\Taxation\Domain\ValueObjects\WithholdingCalculation;
use Carbon\Carbon;

/**
 * Withholding Calculation Service
 *
 * Domain service responsible for calculating withholding tax amounts
 * based on rules and conditions.
 */
class WithholdingCalculationService
{
    /**
     * Calculate withholding for a payment.
     *
     * Returns null if no withholding applies (partner exempt or no matching rule).
     *
     * @param  numeric-string  $amount  Payment amount
     * @param  string  $countryCode  Country code for rule matching
     * @param  string|null  $companyId  Company ID for company-specific rules
     * @param  TransactionType|null  $transactionType  Type of transaction
     * @param  Carbon|null  $paymentDate  Date for rule effective date check
     */
    public function calculateForPayment(
        Partner $partner,
        string $amount,
        string $currency,
        string $countryCode,
        ?string $companyId = null,
        ?TransactionType $transactionType = null,
        ?Carbon $paymentDate = null,
    ): ?WithholdingCalculation {
        // Check if partner is exempt from withholding
        if ($partner->withholding_exempt ?? false) {
            return null;
        }

        $paymentDate = $paymentDate ?? now();

        // Find applicable rule
        $rule = $this->findApplicableRule(
            $partner->tax_status,
            $amount,
            $countryCode,
            $companyId,
            $transactionType,
            $paymentDate
        );

        if (! $rule) {
            return null;
        }

        // Calculate withholding
        return WithholdingCalculation::calculate(
            $amount,
            $rule->rate,
            $currency,
            $transactionType
        )->withRule(
            $rule->id,
            $rule->code,
            $rule->name
        );
    }

    /**
     * Calculate with manual rate override.
     *
     * Used when user manually sets a different rate.
     *
     * @param  numeric-string  $amount  Payment amount
     * @param  float  $ratePercentage  Rate as percentage (e.g., 5.0 for 5%)
     */
    public function calculateWithOverride(
        string $amount,
        string $currency,
        float $ratePercentage,
        string $overrideReason,
        ?TransactionType $transactionType = null
    ): WithholdingCalculation {
        // Convert percentage to decimal (5.0 → 0.0500)
        $rate = bcdiv((string) $ratePercentage, '100', 4);

        return WithholdingCalculation::calculate(
            $amount,
            $rate,
            $currency,
            $transactionType
        )->withOverride($overrideReason);
    }

    /**
     * Preview withholding calculation without persisting.
     *
     * Returns suggested rate and calculation for UI display.
     *
     * @param  numeric-string  $amount
     * @return array{should_withhold: bool, calculation: WithholdingCalculation|null, suggested_rate: float|null}
     */
    public function preview(
        Partner $partner,
        string $amount,
        string $currency,
        string $countryCode,
        ?string $companyId = null,
        ?TransactionType $transactionType = null
    ): array {
        $calculation = $this->calculateForPayment(
            $partner,
            $amount,
            $currency,
            $countryCode,
            $companyId,
            $transactionType
        );

        return [
            'should_withhold' => $calculation !== null,
            'calculation' => $calculation,
            'suggested_rate' => $calculation?->getRateAsPercentage(),
        ];
    }

    /**
     * Find the most specific applicable withholding rule.
     *
     * Priority: Company-specific rules first, then global country rules.
     *
     * @param  numeric-string  $amount
     */
    private function findApplicableRule(
        \App\Modules\Taxation\Domain\Enums\PartnerTaxStatus $partnerStatus,
        string $amount,
        string $countryCode,
        ?string $companyId,
        ?TransactionType $transactionType,
        Carbon $date
    ): ?WithholdingTaxRule {
        // Build query for applicable rules
        $query = WithholdingTaxRule::where('country_code', $countryCode)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            });

        // Prioritize company-specific rules if company ID provided
        if ($companyId) {
            $query->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$companyId]);
        }

        // Order by specificity: most specific conditions first
        $query->orderByRaw('CASE
            WHEN transaction_type IS NOT NULL AND partner_tax_status IS NOT NULL THEN 0
            WHEN transaction_type IS NOT NULL THEN 1
            WHEN partner_tax_status IS NOT NULL THEN 2
            ELSE 3
        END');

        $rules = $query->get();

        // Find first matching rule
        foreach ($rules as $rule) {
            if ($rule->appliesTo($partnerStatus, $amount, $transactionType, $date)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Validate a withholding calculation.
     *
     * Checks if amounts are internally consistent.
     */
    public function validateCalculation(WithholdingCalculation $calculation): bool
    {
        return $calculation->validate();
    }
}
