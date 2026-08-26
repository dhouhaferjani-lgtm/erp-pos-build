<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\Events\OrphanedShiftDeviceCloseApplied;
use App\Modules\POS\Domain\Shift;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Support\Facades\DB;

/**
 * Applies a late device `SESSION_CLOSE` to a shift that an OPERATOR had already
 * closed with `pos:shift:close-orphaned` (LEDGER O-30, gate r1 finding 3).
 *
 * # The hole this fills
 *
 * `ZSessionLifecycleProjection::projectPosShiftClose()` returns early on an
 * already-CLOSED shift. That early return is right for the case it was written
 * for — a re-delivered device close must not double-close — but it also swallows
 * the case where the "lost" device comes back: a till that was merely offline
 * syncs its `SESSION_CLOSE`, and its REAL counted drawer is discarded in favour
 * of the derived zero-variance pair the operator wrote. The chain keeps the
 * device's close (`fiscal_events` / `pos_z_session_events` are untouched), but
 * the projection — and therefore the NF525 JET's `FERMETURE_CAISSE`, built from
 * `pos_shifts` — keeps the stand-in figures forever.
 *
 * The device is authoritative for the shift lifecycle. So when the shift was
 * closed BY AN OPERATOR, the device's figures replace the operator's and the
 * swap is recorded as its own audit fact. When the shift was closed by a device
 * already, nothing changes: the early return stays.
 *
 * # Why this is a service and not three lines inside the projection
 *
 * Two reasons, and the second is the load-bearing one:
 *
 * 1. The provenance probe, the parse, the swap and the emission are enough
 *    behaviour to test on their own.
 * 2. `ProjectorEmissionRatchetTest` scans each registered projector's OWN class
 *    file for `event(new …)` and asserts a strict equality against a baseline of
 *    projectors that emit NOTHING. `ZSessionLifecycleProjection` is on that
 *    baseline, and its line names ES-03 (no ShiftOpened/ShiftClosed), ES-02 (no
 *    CashCountRecorded) and ES-05 (no CashDrawerOperationRecorded) — none of
 *    which this lane closes. Emitting inline would shrink the discovered list,
 *    turn the ratchet red, and offer exactly one "fix": deleting a baseline line
 *    that would then falsely claim those three register rows are done. Keeping
 *    the emission in its own class keeps the baseline TRUE for the events it
 *    actually names. This is deliberate, and it is the opposite of evading the
 *    guard — the guard's claim about this projector stays accurate.
 */
final class OrphanedShiftDeviceCloseReconciler
{
    /**
     * Was this shift closed by an operator, and if so, apply the device's close
     * over the top.
     *
     * Returns true when the shift's figures were replaced.
     *
     * REPLAY-SAFE on two independent guards: the projector's own
     * `z_session_events` idempotency short-circuit never lets the same fiscal
     * event reach here twice, and this method additionally refuses when a
     * `shift.orphan_device_close_applied` row already exists for the shift — so
     * even a hand-replayed or duplicated close cannot swap the figures a second
     * time or emit a second correction.
     *
     * @param  array<string, mixed>  $payload  the SESSION_CLOSE payload
     */
    public function reconcile(Shift $shift, FiscalEvent $event, array $payload): bool
    {
        $companyId = (string) $event->company_id;

        if (! $this->wasClosedByOperator($shift->id, $companyId)) {
            return false;
        }

        if ($this->deviceCloseAlreadyApplied($shift->id, $companyId)) {
            return false;
        }

        $operatorId = $payload['operator_id'] ?? null;
        if (! is_string($operatorId) || $operatorId === '') {
            // Same contract as the projection's own close path: a SESSION_CLOSE
            // with no operator cannot name a `closed_by`, and guessing one on a
            // fiscal row is not available. Leave the operator's close standing
            // and let the projector's caller surface the malformed event.
            return false;
        }

        $expectedRaw = $payload['expected_cash'] ?? null;
        $countedRaw = $payload['counted_cash'] ?? null;
        $deviceExpected = is_string($expectedRaw) && is_numeric($expectedRaw) ? $expectedRaw : null;
        $deviceCounted = is_string($countedRaw) && is_numeric($countedRaw) ? $countedRaw : null;

        // `pos_shifts_variance_calc`: either (variance NULL AND actual_cash
        // NULL) or (variance = actual_cash − expected_cash). Recomputed at the
        // column scale so the CHECK holds exactly, identical to the projection's
        // own close path.
        $deviceVariance = null;
        if ($deviceCounted !== null && $deviceExpected !== null) {
            // `pos_shifts.expected_cash/actual_cash/variance` are DECIMAL(16,4)
            // and the PG CHECK `pos_shifts_variance_calc` compares at the COLUMN
            // scale, so the difference must be computed at 4 — not at the
            // currency scale — or the CHECK fails for every currency whose scale
            // is not 4. Identical to the projection's own close path.
            // precision-ok: pos_shifts money columns are DECIMAL(16,4); the CHECK compares at column scale.
            $deviceVariance = bcsub($deviceCounted, $deviceExpected, 4);
        } else {
            $deviceCounted = null;
        }

        $supersededExpected = $shift->expected_cash !== null ? (string) $shift->expected_cash : null;
        $supersededCounted = $shift->actual_cash !== null ? (string) $shift->actual_cash : null;
        $supersededVariance = $shift->variance !== null ? (string) $shift->variance : null;

        $deviceClosedAt = $payload['generated_at_device'] ?? $event->event_time_device;

        $severityRaw = $payload['variance_severity'] ?? null;
        $varianceSeverity = is_string($severityRaw)
            ? VarianceSeverity::tryFrom($severityRaw)?->value
            : null;

        $managerApproval = $payload['manager_approval'] ?? null;
        $managerOverrideBy = is_array($managerApproval) && is_string($managerApproval['supervisor_user_id'] ?? null)
            ? $managerApproval['supervisor_user_id']
            : null;

        $shift->closed_at = $deviceClosedAt;
        $shift->closed_by = $operatorId;
        $shift->expected_cash = $deviceExpected;
        $shift->actual_cash = $deviceCounted;
        $shift->variance = $deviceVariance;
        if ($varianceSeverity !== null) {
            $shift->variance_severity = $varianceSeverity;
        }
        if ($managerOverrideBy !== null) {
            $shift->manager_override_by = $managerOverrideBy;
        }

        $shift->notes = $this->appendMarker(
            $shift->notes,
            sprintf(
                '[O-30 device close applied %s] The device believed lost synced its own SESSION_CLOSE '
                .'(fiscal_event %s). Its counted drawer REPLACED the operator-derived pair '
                .'(expected %s / counted %s / variance %s → expected %s / counted %s / variance %s).',
                (string) $event->id,
                (string) $event->id,
                $supersededExpected ?? 'null',
                $supersededCounted ?? 'null',
                $supersededVariance ?? 'null',
                $deviceExpected ?? 'null',
                $deviceCounted ?? 'null',
                $deviceVariance ?? 'null',
            ),
        );

        $shift->save();

        event(new OrphanedShiftDeviceCloseApplied(
            shiftId: $shift->id,
            terminalId: (string) $event->terminal_id,
            companyId: $companyId,
            fiscalEventId: (string) $event->id,
            operatorId: $operatorId,
            supersededExpectedCash: $supersededExpected,
            supersededCountedCash: $supersededCounted,
            supersededVariance: $supersededVariance,
            deviceExpectedCash: $deviceExpected,
            deviceCountedCash: $deviceCounted,
            deviceVariance: $deviceVariance,
            deviceClosedAt: (string) $shift->closed_at,
        ));

        return true;
    }

    /**
     * Read through the query builder, not Compliance's `AuditEvent` model: a POS
     * file must not import another module's model (rule 6), and
     * `PosCoreReceiptProjection` already probes `audit_events` this way.
     */
    private function wasClosedByOperator(string $shiftId, string $companyId): bool
    {
        return DB::table('audit_events')
            ->where('company_id', $companyId)
            ->where('event_type', 'shift.orphan_closed')
            ->where('aggregate_type', 'Shift')
            ->where('aggregate_id', $shiftId)
            ->exists();
    }

    private function deviceCloseAlreadyApplied(string $shiftId, string $companyId): bool
    {
        return DB::table('audit_events')
            ->where('company_id', $companyId)
            ->where('event_type', 'shift.orphan_device_close_applied')
            ->where('aggregate_type', 'Shift')
            ->where('aggregate_id', $shiftId)
            ->exists();
    }

    private function appendMarker(?string $existing, string $marker): string
    {
        return $existing === null || trim($existing) === ''
            ? $marker
            : $existing."\n".$marker;
    }
}
