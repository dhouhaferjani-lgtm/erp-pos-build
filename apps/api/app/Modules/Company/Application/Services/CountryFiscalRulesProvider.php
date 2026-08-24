<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Models\Country;
use App\Modules\Company\Application\DTOs\FiscalYearRules;

/**
 * Central registry of fiscal year rules for each country.
 *
 * Countries with dedicated rules get country-specific configuration (TN, FR).
 * All other countries use sensible generic defaults (calendar year, monthly periods).
 *
 * To add country-specific rules: add a private getRulesForXX() method.
 */
final class CountryFiscalRulesProvider
{
    public function getRulesForCountry(string $countryCode): FiscalYearRules
    {
        $methodName = 'getRulesFor'.strtoupper($countryCode);

        if (! method_exists($this, $methodName)) {
            return $this->getGenericRules($countryCode);
        }

        return $this->$methodName();
    }

    /**
     * Does this country have DEDICATED (country-specific) fiscal rules?
     *
     * FALSE means {@see getRulesForCountry()} would answer with
     * {@see getGenericRules()} — a sensible default for a UI form, but a GUESS
     * about a legal calendar. Callers that make a compliance statement on the
     * customer's behalf must branch on this rather than consume the guess:
     * {@see FiscalPeriodAutoLockService} skips such a company entirely rather
     * than auto-locking its periods on a window nobody configured for it.
     *
     * (Before Session B lane Q-10 the auto-lock did worse than consume the
     * guess: it hard-coded `getRulesForCountry('TN')` and applied Tunisia's
     * window to every company in the tenant regardless of country.)
     */
    public function hasDedicatedRules(string $countryCode): bool
    {
        $countryCode = trim($countryCode);

        if ($countryCode === '') {
            return false;
        }

        return method_exists($this, 'getRulesFor'.strtoupper($countryCode));
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
     * Generic default rules for countries without dedicated configuration.
     *
     * Calendar year, monthly periods, standard auto-lock — works for most countries.
     */
    private function getGenericRules(string $countryCode): FiscalYearRules
    {
        return new FiscalYearRules(
            countryCode: strtoupper($countryCode),
            defaultStartMonth: 1,
            allowCustomStartMonth: true,
            pastYearsToCreate: 1,
            futureYearsToCreate: 1,
            autoClosePastYears: false,
            periodAutoLockMonths: 1,
            periodStructure: 'monthly',
            notes: 'Generic defaults: calendar year, monthly periods. Customize in Company Settings.'
        );
    }

    /**
     * Get list of all active country codes from the database.
     *
     * All countries are supported — those without dedicated rules use generic defaults.
     *
     * @return list<string>
     */
    public function getAvailableCountries(): array
    {
        /** @var list<string> */
        return Country::where('is_active', true)
            ->pluck('code')
            ->all();
    }

    /**
     * All countries are supported — dedicated rules for some, generic defaults for others.
     */
    public function isCountrySupported(string $countryCode): bool
    {
        return Country::where('code', strtoupper($countryCode))
            ->where('is_active', true)
            ->exists();
    }
}
