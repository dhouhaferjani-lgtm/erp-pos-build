<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * FiscalPeriodResolverService
 *
 * Application service responsible for resolving fiscal periods and years into
 * concrete date ranges for financial reporting.
 *
 * This service acts as a bridge between user-facing period selections
 * (e.g., "Q1 2025", "Current Period") and the actual date ranges needed
 * for SQL queries in report generation.
 *
 * Key Responsibilities:
 * - Resolve current fiscal period for a company
 * - Resolve fiscal year boundaries
 * - Convert period IDs to date ranges
 * - Calculate year-to-date (YTD) ranges
 * - Handle edge cases (no fiscal year setup, date outside any period)
 *
 * Architectural Notes:
 * - Application Layer service (orchestrates domain models)
 * - Depends on FiscalYear and FiscalPeriod domain models
 * - Used by report services to translate period selections into queries
 * - Follows hexagonal architecture: no dependencies on infrastructure
 */
class FiscalPeriodResolverService
{
    /**
     * Get the current open fiscal period for a company.
     *
     * The "current period" is defined as the period where:
     * - start_date <= today <= end_date
     * - status = 'open'
     *
     * If multiple periods match (overlapping periods - data quality issue),
     * returns the first one ordered by start_date.
     *
     * @param  string  $companyId  UUID of the company
     * @return FiscalPeriod The current open period
     *
     * @throws ModelNotFoundException When no current period exists
     *
     * @example
     * ```php
     * $period = $resolver->getCurrentPeriod($companyId);
     * // Returns period like: { name: "January 2025", start_date: 2025-01-01, end_date: 2025-01-31 }
     * ```
     */
    public function getCurrentPeriod(string $companyId): FiscalPeriod
    {
        $today = Carbon::now()->startOfDay();

        $period = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->where('status', PeriodStatus::Open)
            ->orderBy('start_date')
            ->first();

        if ($period === null) {
            throw new ModelNotFoundException(
                "No open fiscal period found for company {$companyId} containing today's date ({$today->toDateString()}). ".
                'Please ensure fiscal periods are properly configured in the system.'
            );
        }

        return $period;
    }

    /**
     * Get the current fiscal year for a company.
     *
     * The "current year" is defined as the fiscal year where:
     * - start_date <= today <= end_date
     * - is_closed = false (optional, we still return closed years if they contain today)
     *
     * @param  string  $companyId  UUID of the company
     * @return FiscalYear The current fiscal year
     *
     * @throws ModelNotFoundException When no fiscal year contains today's date
     *
     * @example
     * ```php
     * $year = $resolver->getCurrentFiscalYear($companyId);
     * // Returns: { name: "2025", start_date: 2025-01-01, end_date: 2025-12-31 }
     * ```
     */
    public function getCurrentFiscalYear(string $companyId): FiscalYear
    {
        $today = Carbon::now()->startOfDay();

        $year = FiscalYear::query()
            ->where('company_id', $companyId)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->orderBy('start_date')
            ->first();

        if ($year === null) {
            throw new ModelNotFoundException(
                "No fiscal year found for company {$companyId} containing today's date ({$today->toDateString()}). ".
                'Please create a fiscal year that includes the current date.'
            );
        }

        return $year;
    }

    /**
     * Resolve a fiscal period ID into concrete date boundaries.
     *
     * If $periodId is null, uses the current open period.
     * If $periodId is provided, looks up that specific period.
     *
     * Returns an associative array with 'start_date' and 'end_date' Carbon instances.
     *
     * @param  string  $companyId  UUID of the company
     * @param  string|null  $periodId  UUID of the period (null = current period)
     * @return array{start_date: Carbon, end_date: Carbon, period: FiscalPeriod}
     *
     * @throws ModelNotFoundException When period not found
     *
     * @example
     * ```php
     * // Get current period dates
     * $dates = $resolver->resolvePeriodDates($companyId, null);
     * // Returns: ['start_date' => Carbon(2025-01-01), 'end_date' => Carbon(2025-01-31), 'period' => FiscalPeriod]
     *
     * // Get specific period dates
     * $dates = $resolver->resolvePeriodDates($companyId, $periodId);
     * ```
     */
    public function resolvePeriodDates(string $companyId, ?string $periodId = null): array
    {
        if ($periodId === null) {
            $period = $this->getCurrentPeriod($companyId);
        } else {
            $period = FiscalPeriod::query()
                ->where('id', $periodId)
                ->where('company_id', $companyId)
                ->firstOrFail();
        }

        return [
            'start_date' => $period->start_date->copy()->startOfDay(),
            'end_date' => $period->end_date->copy()->endOfDay(),
            'period' => $period,
        ];
    }

    /**
     * Calculate year-to-date (YTD) date range for the current fiscal year.
     *
     * YTD is defined as:
     * - start_date: Beginning of the current fiscal year
     * - end_date: Today (or end of fiscal year if today is beyond it)
     *
     * This is commonly used for reports like "YTD Revenue" or "YTD Profit".
     *
     * @param  string  $companyId  UUID of the company
     * @return array{start_date: Carbon, end_date: Carbon, fiscal_year: FiscalYear}
     *
     * @throws ModelNotFoundException When no current fiscal year exists
     *
     * @example
     * ```php
     * $ytd = $resolver->getYearToDate($companyId);
     * // Today is 2025-06-15, fiscal year is 2025-01-01 to 2025-12-31
     * // Returns: ['start_date' => 2025-01-01, 'end_date' => 2025-06-15, 'fiscal_year' => FiscalYear]
     * ```
     */
    public function getYearToDate(string $companyId): array
    {
        $fiscalYear = $this->getCurrentFiscalYear($companyId);
        $today = Carbon::now()->startOfDay();

        // If today is beyond the fiscal year end, use the year end date
        $endDate = $today->greaterThan($fiscalYear->end_date)
            ? $fiscalYear->end_date->copy()->endOfDay()
            : Carbon::now()->endOfDay();

        return [
            'start_date' => $fiscalYear->start_date->copy()->startOfDay(),
            'end_date' => $endDate,
            'fiscal_year' => $fiscalYear,
        ];
    }

    /**
     * Get a fiscal period by its name (fuzzy matching).
     *
     * Useful for user-friendly period selection like "January 2025", "Q1 2025".
     * Performs a case-insensitive LIKE search on the period name.
     *
     * @param  string  $companyId  UUID of the company
     * @param  string  $periodName  Name or partial name to search for
     * @return FiscalPeriod|null The first matching period, or null if none found
     *
     * @example
     * ```php
     * $period = $resolver->getPeriodByName($companyId, 'January 2025');
     * $period = $resolver->getPeriodByName($companyId, 'Q1'); // Finds first match like "Q1 2025"
     * ```
     */
    public function getPeriodByName(string $companyId, string $periodName): ?FiscalPeriod
    {
        // Escape SQL wildcards to prevent unexpected matches
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $periodName);

        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('name', 'ILIKE', "%{$escaped}%")
            ->orderBy('start_date')
            ->first();
    }

    /**
     * Validate that a date falls within an open fiscal period.
     *
     * This is used to prevent posting transactions to closed periods,
     * which is a critical compliance requirement.
     *
     * @param  string  $companyId  UUID of the company
     * @param  Carbon  $date  The date to validate
     * @return bool True if date is in an open period, false otherwise
     *
     * @example
     * ```php
     * if (!$resolver->isDateInOpenPeriod($companyId, $transactionDate)) {
     *     throw new \Exception("Cannot post transaction: period is closed");
     * }
     * ```
     */
    public function isDateInOpenPeriod(string $companyId, Carbon $date): bool
    {
        $period = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->where('status', PeriodStatus::Open)
            ->first();

        return $period !== null;
    }

    /**
     * Check whether a date falls within a fiscal period that EXISTS and is
     * CLOSED (or Locked — see {@see FiscalPeriod::isClosed()}).
     *
     * Guard semantics (Treasury spine BLOCKER-2): this is deliberately NOT
     * the inverse of {@see isDateInOpenPeriod()}. That method returns false
     * both when a period is closed AND when no period exists at all for the
     * date — collapsing those two very different situations into one
     * boolean. Reusing it to gate posting would reject postings for any
     * company that hasn't configured fiscal periods yet (absence of
     * configuration is not the same as a deliberately closed period).
     *
     * This method distinguishes them: it returns true ONLY when a period
     * exists for the date AND that period is closed. Absence of any period
     * for the date returns false (posting is allowed).
     *
     * @param  string  $companyId  UUID of the company
     * @param  Carbon  $date  The entry date to validate
     * @return bool True only if a period exists for the date and is closed
     */
    public function isDateInClosedPeriod(string $companyId, Carbon $date): bool
    {
        // Order-independent: return true iff a covering period whose status is
        // Closed OR Locked EXISTS. The previous ->first()->isClosed() form was
        // row-order-dependent — with two periods overlapping the date it could
        // sample an Open period and wrongly allow a post into a date that is
        // ALSO covered by a Closed period. The whereIn([Closed, Locked]) mirrors
        // FiscalPeriod::isClosed() exactly (Locked is treated as closed too).
        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->whereIn('status', [PeriodStatus::Closed, PeriodStatus::Locked])
            ->exists();
    }

    /**
     * Get all open periods for a fiscal year.
     *
     * Useful for UI dropdowns showing available periods for transaction posting.
     *
     * @param  string  $fiscalYearId  UUID of the fiscal year
     * @return Collection<int, FiscalPeriod>
     */
    public function getOpenPeriodsForYear(string $fiscalYearId): Collection
    {
        return FiscalPeriod::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', PeriodStatus::Open)
            ->orderBy('period_number')
            ->get();
    }

    /**
     * Resolve date range with optional fiscal period override.
     *
     * This is a convenience method that handles three scenarios:
     * 1. Explicit date range provided → use as-is
     * 2. Fiscal period ID provided → resolve to period dates
     * 3. Nothing provided → use current period dates
     *
     * Used by report services to standardize date range resolution.
     *
     * @param  string  $companyId  UUID of the company
     * @param  Carbon|null  $dateFrom  Explicit start date (overrides period)
     * @param  Carbon|null  $dateTo  Explicit end date (overrides period)
     * @param  string|null  $fiscalPeriodId  Optional period ID
     * @return array{start_date: Carbon, end_date: Carbon}
     *
     * @example
     * ```php
     * // Explicit dates (highest priority)
     * $range = $resolver->resolveDateRange($companyId, Carbon::parse('2025-01-01'), Carbon::parse('2025-03-31'));
     *
     * // Fiscal period
     * $range = $resolver->resolveDateRange($companyId, null, null, $periodId);
     *
     * // Current period (fallback)
     * $range = $resolver->resolveDateRange($companyId, null, null, null);
     * ```
     */
    public function resolveDateRange(
        string $companyId,
        ?Carbon $dateFrom,
        ?Carbon $dateTo,
        ?string $fiscalPeriodId = null
    ): array {
        // If explicit dates provided, use them (highest priority)
        if ($dateFrom !== null && $dateTo !== null) {
            if ($dateFrom->greaterThan($dateTo)) {
                throw new \InvalidArgumentException(
                    "Start date ({$dateFrom->toDateString()}) cannot be after end date ({$dateTo->toDateString()})"
                );
            }

            return [
                'start_date' => $dateFrom->copy()->startOfDay(),
                'end_date' => $dateTo->copy()->endOfDay(),
            ];
        }

        // If fiscal period ID provided, resolve it
        if ($fiscalPeriodId !== null) {
            $resolved = $this->resolvePeriodDates($companyId, $fiscalPeriodId);

            return [
                'start_date' => $resolved['start_date'],
                'end_date' => $resolved['end_date'],
            ];
        }

        // Fallback: use current period
        $resolved = $this->resolvePeriodDates($companyId, null);

        return [
            'start_date' => $resolved['start_date'],
            'end_date' => $resolved['end_date'],
        ];
    }
}
