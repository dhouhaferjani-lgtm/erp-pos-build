<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\DTOs;

/**
 * Defines fiscal year creation rules for a specific country.
 *
 * Encapsulates:
 * - Default fiscal year start month
 * - Whether companies can override the default
 * - How many years to create (past/current/future)
 * - Auto-closing behavior
 * - Period auto-lock threshold
 */
final readonly class FiscalYearRules
{
    public function __construct(
        public string $countryCode,
        public int $defaultStartMonth,           // 1-12
        public bool $allowCustomStartMonth,      // Can companies override?
        public int $pastYearsToCreate,           // Typically 1
        public int $futureYearsToCreate,         // Typically 1
        public bool $autoClosePastYears,         // False for TN/FR
        public int $periodAutoLockMonths,        // Months after period end to auto-lock
        public string $periodStructure = 'monthly',
        public ?string $notes = null,
    ) {
        // Validation
        if ($defaultStartMonth < 1 || $defaultStartMonth > 12) {
            throw new \InvalidArgumentException('Start month must be 1-12');
        }
        if ($periodAutoLockMonths < 1) {
            throw new \InvalidArgumentException('Period auto-lock months must be at least 1');
        }
    }

    /**
     * Get effective start month considering company override.
     */
    public function getEffectiveStartMonth(?int $companyOverride): int
    {
        if ($companyOverride !== null && $this->allowCustomStartMonth) {
            return $companyOverride;
        }

        return $this->defaultStartMonth;
    }

    /**
     * Calculate which fiscal years to create.
     *
     * @return array{past: int, current: int, future: int}
     */
    public function getYearsToCreate(int $startMonth, \DateTimeImmutable $now): array
    {
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');

        // Determine current fiscal year
        // If we're past the fiscal year start month, we're in the current calendar year's fiscal year
        // If we're before the fiscal year start month, we're still in the previous calendar year's fiscal year
        $fiscalYearOffset = ($currentMonth >= $startMonth) ? 0 : -1;
        $currentFiscalYear = $currentYear + $fiscalYearOffset;

        return [
            'past' => $currentFiscalYear - $this->pastYearsToCreate,
            'current' => $currentFiscalYear,
            'future' => $currentFiscalYear + $this->futureYearsToCreate,
        ];
    }
}
