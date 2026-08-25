<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Enums\FiscalPeriodCloseRefusalCode;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\Exceptions\FiscalPeriodCloseRefusedException;
use App\Modules\Company\Domain\FiscalPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Permissioned MANUAL `Open → Closed` edge for a fiscal period (Session B2 lane C-24 (i)).
 *
 * THE MISSING EDGE
 * ----------------
 * Lane Q-10 made {@see FiscalPeriodAutoLockService} skip any period a human reopened
 * (`reopened_at IS NOT NULL`) and HOLD the close of the fiscal YEAR containing it, so the
 * scheduler could neither re-close a correction 24 h later nor sweep it into the terminal
 * `Locked` state. Both that service's docblock and
 * {@see FiscalPeriodReopenService}'s stated the honest consequence: with no manual close,
 * a reopened period — and its whole fiscal year — stays Open forever.
 *
 * This service is that manual close, and it is the ONLY thing the fix needed. The
 * scheduler is unchanged: `FiscalPeriodAutoLockService::lockPeriodsInClosedFiscalYears()`
 * already excludes only `Open AND reopened_at IS NOT NULL`, and `closeFiscalYears()` holds
 * only on the same predicate — so the moment a human writes `Closed` on the reopened row,
 * the year hold releases by itself and the nightly run finishes the sequence.
 *
 * WHY IT IS NOT "the reopen, backwards"
 * ------------------------------------
 * The reopen UNDOES a close (it nulls `closed_at`/`closed_by`). This close does NOT undo a
 * reopen: `reopened_at` / `reopened_by` / `reopen_reason` are HISTORY and are deliberately
 * kept (LEDGER C-24: "stamps closed_by, clears nothing"). Two reasons:
 *   1. the row is the audit trail of a compliance-visible reversal — erasing it on the
 *      next close would erase the record that the reversal ever happened;
 *   2. the scheduler's own predicates read `reopened_at`, and STEP 3 explicitly documents
 *      the "reopened but Closed again by a human" row as one that locks with its year.
 *      Clearing the stamp would silently change which arm that row takes.
 *
 * FIVE REFUSALS, all typed ({@see FiscalPeriodCloseRefusalCode}):
 *   - LOCKED is terminal — never `Locked → Closed`; lifting it is a year-level reopen,
 *     which does not exist. Checked FIRST so a locked row never falls through to the
 *     generic "not open" message.
 *   - only `Open → Closed`; anything else (already Closed) refuses.
 *   - the fiscal YEAR must be open — a closed year's periods belong to the nightly lock.
 *   - NO EARLIER PERIOD OF THE SAME COMPANY MAY STILL BE OPEN. The exact mirror of the
 *     reopen's `successorSettled` guard: periods settle in sequence, and both the
 *     scheduler and every downstream report assume oldest-first settlement. Closing
 *     ahead of an open predecessor lets that assumption drift.
 *   - the period must HAVE ENDED. `end_date` today or later still accepts postings by
 *     definition, so closing it is not a settlement, it is a data-loss trap. JUDGEMENT
 *     CALL flagged to the reviewer by the lane brief: if the treasury gate rules that an
 *     early close is legitimate (a period closed the same day it ends, say), this is the
 *     one guard to drop — nothing else in the service depends on it.
 *
 * The row is re-read `FOR UPDATE` inside the transaction so two concurrent closes, or a
 * close racing the 01:00 scheduler, cannot both observe `Open` and both write.
 *
 * NO `close_reason` COLUMN: the endpoint accepts an optional `reason` and LOGS it, but
 * there is nowhere to persist it and this lane ships no migration (an additive
 * `close_reason` column is a Slice-D-adjacent residual, recorded by the lane report).
 * A domain event for the transition is C-24 (iv) and stays a residual too — the reopen
 * has none either, and adding one for only one side of the edge would be worse.
 */
final class FiscalPeriodCloseService
{
    /**
     * Close an open fiscal period.
     *
     * @param  string  $userId  UUID of the acting user (the route is permission-gated)
     * @param  string|null  $reason  Optional operator note. LOGGED, NOT PERSISTED — there
     *                               is no `close_reason` column (see the class docblock).
     *
     * @throws FiscalPeriodCloseRefusedException When the period cannot be closed. Carries
     *                                           a typed {@see FiscalPeriodCloseRefusalCode}
     *                                           so the caller does not string-match the prose.
     */
    public function close(FiscalPeriod $period, string $userId, ?string $reason): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $userId, $reason): FiscalPeriod {
            /** @var FiscalPeriod $fresh */
            $fresh = FiscalPeriod::query()
                ->whereKey($period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status === PeriodStatus::Locked) {
                throw FiscalPeriodCloseRefusedException::periodLocked((string) $fresh->id);
            }

            if ($fresh->status !== PeriodStatus::Open) {
                throw FiscalPeriodCloseRefusedException::periodNotOpen((string) $fresh->id);
            }

            $fiscalYear = $fresh->fiscalYear()->first();

            if ($fiscalYear !== null && $fiscalYear->is_closed) {
                throw FiscalPeriodCloseRefusedException::fiscalYearClosed((string) $fresh->id);
            }

            // Company-local "today" is not modelled anywhere in this module (the nightly
            // scheduler uses `Carbon::now()` for the same class of comparison), so the
            // app timezone is the honest boundary here.
            if (! $fresh->end_date->lt(Carbon::today())) {
                throw FiscalPeriodCloseRefusedException::periodNotEnded((string) $fresh->id);
            }

            if ($this->hasOpenPredecessor($fresh)) {
                throw FiscalPeriodCloseRefusedException::predecessorOpen((string) $fresh->id);
            }

            $fresh->fill([
                'status' => PeriodStatus::Closed,
                'status_changed_from' => PeriodStatus::Open->value,
                'status_actor' => 'user:'.$userId,
                'closed_at' => Carbon::now(),
                'closed_by' => $userId,
                // `reopened_at` / `reopened_by` / `reopen_reason` are NOT touched: they
                // are history, and the scheduler's predicates read them. See docblock.
            ])->save();

            Log::info('Fiscal period closed', [
                'fiscal_period_id' => $fresh->id,
                'company_id' => $fresh->company_id,
                'user_id' => $userId,
                'from' => PeriodStatus::Open->value,
                'to' => PeriodStatus::Closed->value,
                // Not persisted — there is no `close_reason` column.
                'reason' => $reason,
                'was_reopened' => $fresh->reopened_at !== null,
            ]);

            return $fresh;
        });
    }

    /**
     * Is there an EARLIER period for the same company that is still Open?
     *
     * Ordering invariant, the mirror of `FiscalPeriodReopenService::hasClosedOrLockedSuccessor()`:
     * periods settle in sequence. Closing one ahead of an open predecessor would leave a
     * settled period behind an unsettled one, which is exactly the shape the reopen guard
     * refuses to create from the other direction.
     */
    private function hasOpenPredecessor(FiscalPeriod $period): bool
    {
        return FiscalPeriod::query()
            ->where('company_id', $period->company_id)
            ->where('start_date', '<', $period->start_date)
            ->where('status', PeriodStatus::Open)
            ->exists();
    }
}
