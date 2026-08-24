<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Enums\FiscalPeriodReopenRefusalCode;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\Exceptions\FiscalPeriodReopenRefusedException;
use App\Modules\Company\Domain\FiscalPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Permissioned `Closed → Open` edge for a fiscal period (Session B lane Q-10 (c)).
 *
 * ONCE REOPENED, THE PERIOD STAYS OPEN UNTIL A HUMAN CLOSES IT — THE SCHEDULER SKIPS IT.
 * ------------------------------------------------------------------------------------
 * Parent ruling on gate r1 finding I-1 (C-2): {@see FiscalPeriodAutoLockService} excludes
 * a period with a `reopened_at` stamp from its nightly close, and holds the close of the
 * fiscal YEAR that contains it, so the scheduler can neither re-close it 24 h later nor
 * sweep it into the terminal `Locked` state. The reopen exists for corrections; a silent
 * re-close would defeat it and would overwrite the human `status_actor` with
 * `system:auto-lock`.
 *
 * HONEST CONSEQUENCE: there is currently NO manual close endpoint for a single period —
 * the only closers in the product are the nightly scheduler (which now skips this row)
 * and the fiscal-year close (which is now held for this row's year). Until a manual close
 * lands, a reopened period stays Open, and its fiscal year stays open with it. That is the
 * deliberate trade: an un-closed period is recoverable, a wrongly re-locked one is not.
 *
 * WHY THIS EXISTS
 * ---------------
 * The 2026-08-23 state-machine sweep found `PeriodStatus::Open` written in exactly ONE
 * place in the whole codebase — `FiscalYearCreationService`, at fiscal-year creation.
 * Closed and Locked therefore had ZERO in-product outgoing edges: one month after
 * go-live the nightly auto-lock made opening-period corrections impossible and the
 * operator's only recovery was a manual `UPDATE fiscal_periods`.
 *
 * SHAPE
 * -----
 * Deliberately a copy of `VatPeriodManagementService::reopenPeriod()`
 * (app/Modules/Taxation/Application/Services/VatPeriodManagementService.php:133),
 * which already models this correctly for VAT periods:
 *   - refuse unless the period is CLOSED (a `\DomainException`, surfaced as 422);
 *   - refuse when a SUCCESSOR period is already Closed or Locked, because reopening
 *     behind a settled period would break the ordering invariant the whole close
 *     sequence depends on;
 *   - do the write inside a transaction;
 *   - UNDO the close stamps rather than layering new ones on top.
 *
 * Two deliberate differences from the VAT service:
 *   1. LOCKED STAYS TERMINAL. A Locked period lives inside a closed fiscal year; giving
 *      it an outgoing edge means reopening the year too, which is the full PeriodStatus
 *      machine and is PROGRAM scope, not this lane's. `Locked → …` refuses here.
 *   2. A `reason` is REQUIRED and stored (`reopen_reason`). Reversing a fiscal close is a
 *      compliance-visible act; the row records who, when and why.
 *
 * The period row is re-read `FOR UPDATE` inside the transaction so two concurrent
 * reopen requests, or a reopen racing the nightly auto-lock, cannot both observe
 * `Closed` and both write.
 */
final class FiscalPeriodReopenService
{
    /**
     * Reopen a closed fiscal period.
     *
     * @param  string  $userId  UUID of the acting user (the route is permission-gated)
     * @param  string  $reason  Operator justification, persisted on the row
     *
     * @throws FiscalPeriodReopenRefusedException When the period cannot be reopened.
     *                                            Carries a typed
     *                                            {@see FiscalPeriodReopenRefusalCode}
     *                                            so the caller does not string-match the prose (gate r1, M-1).
     */
    public function reopen(FiscalPeriod $period, string $userId, string $reason): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $userId, $reason): FiscalPeriod {
            /** @var FiscalPeriod $fresh */
            $fresh = FiscalPeriod::query()
                ->whereKey($period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status === PeriodStatus::Locked) {
                throw FiscalPeriodReopenRefusedException::periodLocked((string) $fresh->id);
            }

            if ($fresh->status !== PeriodStatus::Closed) {
                throw FiscalPeriodReopenRefusedException::periodNotClosed((string) $fresh->id);
            }

            $fiscalYear = $fresh->fiscalYear()->first();

            if ($fiscalYear !== null && $fiscalYear->is_closed) {
                throw FiscalPeriodReopenRefusedException::fiscalYearClosed((string) $fresh->id);
            }

            if ($this->hasClosedOrLockedSuccessor($fresh)) {
                throw FiscalPeriodReopenRefusedException::successorSettled((string) $fresh->id);
            }

            $fresh->fill([
                'status' => PeriodStatus::Open,
                'status_changed_from' => PeriodStatus::Closed->value,
                'status_actor' => 'user:'.$userId,
                // Undo semantics, matching VatPeriodManagementService::reopenPeriod():
                // the close stamps are cleared, not stacked.
                'closed_at' => null,
                'closed_by' => null,
                'reopened_at' => Carbon::now(),
                'reopened_by' => $userId,
                'reopen_reason' => $reason,
            ])->save();

            Log::info('Fiscal period reopened', [
                'fiscal_period_id' => $fresh->id,
                'company_id' => $fresh->company_id,
                'user_id' => $userId,
                'from' => PeriodStatus::Closed->value,
                'to' => PeriodStatus::Open->value,
            ]);

            return $fresh;
        });
    }

    /**
     * Is there a LATER period for the same company that is already Closed or Locked?
     *
     * Ordering invariant: periods settle in sequence. Reopening one behind a settled
     * successor would let a posting land in a period whose successor has already been
     * reported on. Mirrors `VatPeriodRepository::hasClosedOrFiledSuccessor()`.
     */
    private function hasClosedOrLockedSuccessor(FiscalPeriod $period): bool
    {
        return FiscalPeriod::query()
            ->where('company_id', $period->company_id)
            ->where('start_date', '>', $period->start_date)
            ->whereIn('status', [PeriodStatus::Closed, PeriodStatus::Locked])
            ->exists();
    }
}
