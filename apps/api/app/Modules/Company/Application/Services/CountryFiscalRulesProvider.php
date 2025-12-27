<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Application\DTOs\FiscalYearRules;

/**
 * Central registry of fiscal year rules for each country.
 *
 * To add a new country:
 * 1. Add method: getRulesForXX()
 * 2. Return FiscalYearRules with country configuration
 * 3. Update getAvailableCountries()
 */
final class CountryFiscalRulesProvider
{
    public function getRulesForCountry(string $countryCode): FiscalYearRules
    {
        $methodName = 'getRulesFor'.strtoupper($countryCode);

        if (! method_exists($this, $methodName)) {
            // Fallback to France rules for undefined countries
            return $this->getRulesForFR();
        }

        return $this->$methodName();
    }

    /**
     * Tunisia: Calendar year (Jan-Dec) required.
     */
    private function getRulesForTN(): FiscalYearRules
    {
        return new FiscalYearRules(
            countryCode: 'TN',
            defaultStartMonth: 1,
            allowCustomStartMonth: true,  // Can request tax authority approval
            pastYearsToCreate: 1,
            futureYearsToCreate: 1,
            autoClosePastYears: false,    // Manual closing for compliance
            periodAutoLockMonths: 1,      // Lock periods 1 month after end
            periodStructure: 'monthly',
            notes: 'Tunisia defaults to calendar year. Periods auto-lock 1 month after end date.'
        );
    }

    /**
     * France: Full flexibility, calendar year default.
     */
    private function getRulesForFR(): FiscalYearRules
    {
        return new FiscalYearRules(
            countryCode: 'FR',
            defaultStartMonth: 1,
            allowCustomStartMonth: true,  // Complete freedom
            pastYearsToCreate: 1,
            futureYearsToCreate: 1,
            autoClosePastYears: false,
            periodAutoLockMonths: 1,      // Lock periods 1 month after end
            periodStructure: 'monthly',
            notes: 'France allows complete flexibility. Periods auto-lock 1 month after end date.'
        );
    }

    /**
     * Get list of supported country codes.
     *
     * @return array<int, string>
     */
    public function getAvailableCountries(): array
    {
        return ['TN', 'FR'];
    }

    public function isCountrySupported(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), $this->getAvailableCountries(), true);
    }
}
