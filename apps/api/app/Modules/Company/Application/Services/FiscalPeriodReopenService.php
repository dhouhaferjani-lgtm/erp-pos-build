<?php

declare(strict_types=1);

namespace App\Modules\Company\Application\Services;

use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Permissioned `Closed → Open` edge for a fiscal period (Session B lane Q-10 (c)).
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
     * @throws \DomainException When the period cannot be reopened
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
                throw new \DomainException('Locked periods cannot be reopened: the fiscal year they belong to is closed.');
            }

            if ($fresh->status !== PeriodStatus::Closed) {
                throw new \DomainException('Only closed periods can be reopened');
            }

            $fiscalYear = $fresh->fiscalYear()->first();

            if ($fiscalYear !== null && $fiscalYear->is_closed) {
                throw new \DomainException('Cannot reopen: the fiscal year this period belongs to is closed.');
            }

            if ($this->hasClosedOrLockedSuccessor($fresh)) {
                throw new \DomainException('Cannot reopen: a successor period is already closed or locked');
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
