<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for automatically locking fiscal periods and fiscal years.
 *
 * THREE-STEP AUTO-LOCK PROCESS, RUN PER COMPANY:
 * - STEP 1: Close periods ended > the company's country threshold (Open → Closed)
 * - STEP 2: Mark that company's fiscal years as closed when ended (is_closed = true)
 * - STEP 3: Lock all periods in those closed fiscal years (Open/Closed → Locked)
 *
 * Business Rules:
 * - The threshold is resolved from the COMPANY's own country, not a hard-coded one.
 * - A company whose country has no DEDICATED fiscal rules is SKIPPED entirely — all
 *   three steps — and the skip is logged. See "FAIL SAFE" below.
 * - A period a HUMAN reopened is never re-closed and never locked by this service.
 *   See "REOPEN IS RESPECTED" below.
 * - Already locked periods are not modified (idempotent).
 * - Every transition stamps the acting party, the from-state and a timestamp on the row.
 * - Operates across all companies in the current tenant database; the command
 *   (`fiscal:lock-expired-periods`, app/Console/Commands/LockExpiredFiscalPeriodsCommand.php) iterates tenants.
 * - Runs daily at 1:00 AM via Laravel scheduler.
 *
 * FAIL SAFE (Session B lane Q-10 (a), tenancy sub-report HIGH #2)
 * --------------------------------------------------------------
 * Until 2026-08-24 STEP 1 called `getRulesForCountry('TN')` ONCE, unconditionally, and
 * applied Tunisia's 1-month window to `FiscalPeriod::where('status', Open)` with NO
 * company and NO country predicate. A French, British or German company in the same
 * tenant was auto-locked on Tunisia's legal calendar — a compliance statement the
 * product was making on the customer's behalf.
 *
 * The threshold is now resolved per company from `$company->country_code`. When that
 * country has no dedicated entry in {@see CountryFiscalRulesProvider} the provider's
 * GENERIC defaults are deliberately NOT consumed here: a generic 1-month window is a
 * reasonable default for a settings form, but auto-locking a fiscal period on a guessed
 * legal window is not something to do silently. Such a company is skipped with a
 * warning naming the company and the country, so the operator sees the gap instead of
 * inheriting Tunisia's calendar by accident. Adding a country is one method on
 * {@see CountryFiscalRulesProvider}.
 *
 * PER-ROW AUDIT (Session B lane Q-10 (b))
 * ---------------------------------------
 * The three blind bulk `->update(['status' => …])` calls are gone. Each period is
 * transitioned individually and stamps `status_actor` ('system:auto-lock'), the
 * from-state (`status_changed_from`) and the edge timestamp (`closed_at` / `locked_at`).
 * `closed_by`/`locked_by` are USER foreign keys and stay NULL for the scheduler, which
 * is not a user — `status_actor` is the column that records it.
 *
 * Rows are walked in `chunkById(500)` batches inside the transaction so a tenant with
 * thousands of periods does not hydrate them all at once.
 *
 * REOPEN IS RESPECTED (Session B lane Q-10 fix round — gate r1 finding I-1 / C-2)
 * ------------------------------------------------------------------------------
 * A period reopened through {@see FiscalPeriodReopenService} carries `reopened_at`,
 * `reopened_by`, `reopen_reason` and a `status_actor` of `user:<uuid>`. Such a period is
 * CLOSED AGAIN ONLY BY A HUMAN:
 *
 *   - STEP 1 excludes it (`whereNull('reopened_at')`). Its `end_date` is by construction
 *     older than the country window — that is why it was closed before the reopen — so
 *     without the predicate the 01:00 scheduler silently undid every reopen the next
 *     night AND overwrote `status_actor` back to `system:auto-lock`, destroying the
 *     record of who reversed the close.
 *   - STEP 2 holds the close of the fiscal YEAR that contains such a period, and STEP 3
 *     excludes the period itself. Locking is worse than re-closing: `Locked` is terminal
 *     (the reopen refuses on it), so a swept correction could not be recovered at all.
 *
 * KNOWN GAP, stated rather than hidden: the product has NO manual close endpoint for a
 * single period — the only closers are this scheduler and the fiscal-year close, and both
 * now step around a reopened period. A reopened period therefore stays Open (and holds its
 * year open) until a manual close lands. That is the deliberate trade: an un-closed period
 * is recoverable, a wrongly re-locked one is not.
 *
 * @see CountryFiscalRulesProvider For country-specific thresholds
 * @see FiscalPeriodReopenService For the permissioned Closed → Open edge
 * @see PeriodStatus For status enum values
 */
final class FiscalPeriodAutoLockService
{
    /**
     * Actor label written to `fiscal_periods.status_actor` by the nightly scheduler.
     */
    public const SYSTEM_ACTOR = 'system:auto-lock';

    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly CountryFiscalRulesProvider $rulesProvider
    ) {}

    /**
     * Lock expired periods and close ended fiscal years, company by company.
     */
    public function lockExpiredPeriods(): void
    {
        $startTime = microtime(true);
        $periodsClosed = 0;
        $yearsClosedCount = 0;
        $periodsLocked = 0;
        $companiesSkipped = 0;

        try {
            DB::transaction(function () use (&$periodsClosed, &$yearsClosedCount, &$periodsLocked, &$companiesSkipped): void {
                Company::query()
                    ->orderBy('id')
                    ->chunkById(self::CHUNK_SIZE, function ($companies) use (&$periodsClosed, &$yearsClosedCount, &$periodsLocked, &$companiesSkipped): void {
                        foreach ($companies as $company) {
                            $countryCode = trim((string) $company->country_code);

                            if (! $this->rulesProvider->hasDedicatedRules($countryCode)) {
                                $companiesSkipped++;

                                Log::warning('Fiscal period auto-lock skipped a company: no dedicated fiscal rules for its country', [
                                    'company_id' => $company->id,
                                    'country_code' => $countryCode === '' ? null : $countryCode,
                                    'reason' => 'CountryFiscalRulesProvider has no dedicated rules for this country; refusing to auto-lock on a generic (guessed) legal window.',
                                ]);

                                continue;
                            }

                            $threshold = $this->rulesProvider->getRulesForCountry($countryCode)->periodAutoLockMonths;

                            // STEP 1: Close periods ended more than the company's threshold ago.
                            $periodsClosed += $this->closeOldPeriods($company->id, $threshold);

                            // STEP 2: Mark this company's ended fiscal years as closed.
                            $yearsClosedCount += $this->closeFiscalYears($company->id);

                            // STEP 3: Lock all of this company's periods inside closed fiscal years.
                            $periodsLocked += $this->lockPeriodsInClosedFiscalYears($company->id);
                        }
                    });
            });

            Log::info('Fiscal period auto-lock completed', [
                'periods_closed' => $periodsClosed,
                'fiscal_years_closed' => $yearsClosedCount,
                'periods_locked' => $periodsLocked,
                'companies_skipped_no_country_rules' => $companiesSkipped,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
        } catch (\Exception $e) {
            Log::error('Fiscal period auto-lock failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            throw $e;
        }
    }

    /**
     * STEP 1: Close periods that ended more than the company's threshold ago.
     *
     * Open → Closed, one row at a time, each stamping actor / from-state / closed_at.
     * Periods already Closed or Locked are not touched, and NEITHER IS A PERIOD A HUMAN
     * REOPENED (`reopened_at IS NOT NULL`) — see REOPEN IS RESPECTED in the class docblock.
     *
     * @param  int  $thresholdMonths  The company's own country lock window
     * @return int Number of periods closed
     */
    private function closeOldPeriods(string $companyId, int $thresholdMonths): int
    {
        $cutoffDate = Carbon::now()->subMonths($thresholdMonths);
        $now = Carbon::now();
        $closed = 0;

        FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('status', PeriodStatus::Open)
            ->where('end_date', '<', $cutoffDate)
            // A reopened period is closed again ONLY by a human (parent ruling on gate
            // r1 finding I-1). Its `end_date` is by construction older than the window —
            // that is why it was closed in the first place — so without this predicate
            // the scheduler re-closed every reopen within 24h.
            ->whereNull('reopened_at')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($periods) use (&$closed, $now): void {
                foreach ($periods as $period) {
                    $period->fill([
                        'status' => PeriodStatus::Closed,
                        'status_changed_from' => PeriodStatus::Open->value,
                        'status_actor' => self::SYSTEM_ACTOR,
                        'closed_at' => $now,
                        // closed_by stays NULL: the scheduler is not a user.
                        'closed_by' => null,
                    ])->save();

                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * STEP 2: Mark this company's fiscal years as closed when their end_date has passed.
     *
     * Fiscal years are never reopened once closed (the reopen edge added by lane Q-10
     * is period-level and refuses inside a closed year) — which is exactly why the close
     * is HELD while one of the year's periods is Open because of a reopen. Closing the
     * year would (a) let STEP 3 lock that period into the terminal `Locked` state and
     * (b) make the correction permanently unreachable, since neither the year nor a
     * Locked period has an in-product reopen. One period under correction therefore
     * delays its year's close until the correction is closed; that is the intended
     * trade (parent ruling on gate r1 finding I-1).
     *
     * @return int Number of fiscal years closed
     */
    private function closeFiscalYears(string $companyId): int
    {
        $now = Carbon::now();
        $count = 0;

        FiscalYear::query()
            ->where('company_id', $companyId)
            ->where('is_closed', false)
            ->where('end_date', '<', $now)
            // Hold the close of a year that still holds a period a human reopened.
            // Same sub-select idiom as STEP 3 below; `fiscal_periods.fiscal_year_id`
            // is NOT NULL (2025_11_30_132000_create_compliance_tables.php:107), so the
            // NOT IN cannot be poisoned by a NULL in the subquery.
            ->whereNotIn('id', FiscalPeriod::query()
                ->where('company_id', $companyId)
                ->where('status', PeriodStatus::Open)
                ->whereNotNull('reopened_at')
                ->select('fiscal_year_id'))
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($years) use (&$count, $now): void {
                foreach ($years as $year) {
                    $year->fill([
                        'is_closed' => true,
                        'closed_at' => $now,
                        // closed_by stays NULL: the scheduler is not a user.
                        'closed_by' => null,
                    ])->save();

                    $count++;
                }
            });

        return $count;
    }

    /**
     * STEP 3: Lock all of this company's periods inside its closed fiscal years.
     *
     * Open/Closed → Locked, one row at a time, each stamping actor / from-state /
     * locked_at. Already Locked periods are excluded by the status predicate, so the
     * step is idempotent and does not bump `updated_at` on a settled row.
     *
     * A period that is Open BECAUSE OF A REOPEN is excluded here too. STEP 2 already
     * refuses to close a year holding one, so this predicate is belt-and-braces for a
     * year closed by any other path (a seeded/imported `is_closed = true`, or a future
     * manual year close): `Locked` is terminal, so getting this wrong is unrecoverable.
     * A period whose `reopened_at` is set but which is Closed again (a human settled the
     * correction) is NOT excluded — it locks with the rest of its year.
     *
     * @return int Number of periods locked
     */
    private function lockPeriodsInClosedFiscalYears(string $companyId): int
    {
        $now = Carbon::now();
        $locked = 0;

        FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [PeriodStatus::Open, PeriodStatus::Closed])
            ->whereNot(function ($query): void {
                $query->where('status', PeriodStatus::Open)
                    ->whereNotNull('reopened_at');
            })
            ->whereIn('fiscal_year_id', FiscalYear::query()
                ->where('company_id', $companyId)
                ->where('is_closed', true)
                ->select('id'))
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($periods) use (&$locked, $now): void {
                foreach ($periods as $period) {
                    $period->fill([
                        'status' => PeriodStatus::Locked,
                        'status_changed_from' => $period->getOriginal('status') instanceof PeriodStatus
                            ? $period->getOriginal('status')->value
                            : (string) $period->getOriginal('status'),
                        'status_actor' => self::SYSTEM_ACTOR,
                        'locked_at' => $now,
                        // locked_by stays NULL: the scheduler is not a user.
                        'locked_by' => null,
                    ])->save();

                    $locked++;
                }
            });

        return $locked;
    }
}
