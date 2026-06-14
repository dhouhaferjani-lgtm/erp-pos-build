<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Projections;

use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\ZSessionEvent;
use RuntimeException;

final class ZSessionLifecycleProjection implements FiscalEventProjector
{
    public function name(): string
    {
        return 'pos_core_z_session_lifecycle';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return in_array($type, [
            FiscalEventType::SESSION_OPEN,
            FiscalEventType::OPENING_FLOAT,
            FiscalEventType::CASH_IN,
            FiscalEventType::CASH_OUT,
            FiscalEventType::SAFE_DROP,
            FiscalEventType::CASH_CORRECTION,
            FiscalEventType::SESSION_CLOSE,
            FiscalEventType::X_REPORT,
        ], true);
    }

    public function requiresModule(): ?string
    {
        return null;
    }

    public function priority(): int
    {
        return 50;
    }

    public function apply(FiscalEvent $event): void
    {
        if ($event->integrity_status !== IntegrityStatus::Verified) {
            return;
        }

        if (! in_array($event->chain_context, ['z_session', 'training_z_session'], true)) {
            return;
        }

        if (ZSessionEvent::query()->where('fiscal_event_id', $event->id)->exists()) {
            return;
        }

        $payload = $event->payload;
        if (! is_array($payload)) {
            throw new RuntimeException(sprintf(
                'Cannot project Z-session fiscal event %s without parsed payload.',
                $event->id,
            ));
        }

        $sessionId = $payload['session_id'] ?? null;
        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException(sprintf(
                'Cannot project Z-session fiscal event %s without payload.session_id.',
                $event->id,
            ));
        }

        $shiftId = $payload['shift_id'] ?? null;

        ZSessionEvent::query()->create([
            'id' => (string) $event->id,
            'fiscal_event_id' => (string) $event->id,
            'tenant_id' => (string) $event->tenant_id,
            'company_id' => (string) $event->company_id,
            'terminal_id' => (string) $event->terminal_id,
            'shift_id' => is_string($shiftId) && $shiftId !== '' ? $shiftId : null,
            'session_id' => $sessionId,
            'event_type' => $event->event_type->value,
            'chain_context' => (string) $event->chain_context,
            'sequence_number' => (int) $event->sequence_number,
            'previous_hash' => (string) $event->previous_hash,
            'current_hash' => (string) $event->current_hash,
            'business_date' => $event->business_date,
            'event_time_device' => $event->event_time_device,
            'payload' => $payload,
        ]);

        // `pos_shifts` is a projection of the device-authored SESSION_OPEN
        // (Phase 2). The device is authoritative for the shift lifecycle; this
        // upserts the derived row keyed by the device shift UUID.
        if ($event->event_type === FiscalEventType::SESSION_OPEN) {
            $this->projectPosShiftOpen($event, $payload, $shiftId);
        }

        if ($event->event_type === FiscalEventType::SESSION_CLOSE) {
            $this->projectPosShiftClose($event, $payload, $shiftId);
        }
    }

    /**
     * Project a SESSION_OPEN into the derived `pos_shifts` row.
     *
     * Idempotent and one-open-safe by pre-check (NOT by catching a DB error —
     * a caught constraint violation inside the projection transaction would
     * poison it under PostgreSQL):
     *   - same device shift already projected  → no-op (re-delivery);
     *   - a different shift already OPEN on the terminal → no-op (a stale or
     *     replayed SESSION_OPEN; mirrors `pos_shifts_one_open_per_terminal`),
     *     so dead-letter replay never crashes the projector (Codex F-15).
     *
     * @param  array<string, mixed>  $payload
     */
    private function projectPosShiftOpen(FiscalEvent $event, array $payload, mixed $shiftId): void
    {
        if (! is_string($shiftId) || $shiftId === '') {
            return;
        }

        if (Shift::query()->whereKey($shiftId)->exists()) {
            return;
        }

        $terminalId = (string) $event->terminal_id;
        if (Shift::query()
            ->where('terminal_id', $terminalId)
            ->where('status', ShiftStatus::Open)
            ->exists()
        ) {
            return;
        }

        $shiftNumber = $payload['shift_number'] ?? null;
        $operatorId = $payload['operator_id'] ?? null;
        if (! is_int($shiftNumber)) {
            throw new RuntimeException(sprintf(
                'Cannot project pos_shift for SESSION_OPEN %s without an integer payload.shift_number.',
                $event->id,
            ));
        }
        if (! is_string($operatorId) || $operatorId === '') {
            throw new RuntimeException(sprintf(
                'Cannot project pos_shift for SESSION_OPEN %s without payload.operator_id.',
                $event->id,
            ));
        }

        $openingFloat = $payload['opening_float_amount'] ?? null;
        $openedAt = $payload['opened_at_device'] ?? $event->event_time_device;

        $shift = new Shift;
        $shift->id = $shiftId;
        $shift->fill([
            'terminal_id' => $terminalId,
            'cashier_id' => $operatorId,
            'shift_number' => $shiftNumber,
            'opening_cash' => is_string($openingFloat) ? $openingFloat : '0',
            'status' => ShiftStatus::Open,
            'opened_at' => $openedAt,
        ]);
        $shift->save();
    }

    /**
     * Close the projected `pos_shifts` row from a device-authored SESSION_CLOSE.
     *
     * Idempotent: re-applying SESSION_CLOSE to an already-CLOSED row is a no-op
     * (the v3 409 already prevents a competing server-side close), so a
     * reconnect that re-delivers the close never double-closes or corrupts the
     * chain (Codex F-12). A SESSION_CLOSE with no projected row (grandfathered
     * or out-of-order) is skipped — never a phantom row.
     *
     * @param  array<string, mixed>  $payload
     */
    private function projectPosShiftClose(FiscalEvent $event, array $payload, mixed $shiftId): void
    {
        if (! is_string($shiftId) || $shiftId === '') {
            return;
        }

        $shift = Shift::query()->whereKey($shiftId)->first();
        if ($shift === null || $shift->status === ShiftStatus::Closed) {
            return;
        }

        $operatorId = $payload['operator_id'] ?? null;
        if (! is_string($operatorId) || $operatorId === '') {
            throw new RuntimeException(sprintf(
                'Cannot close pos_shift for SESSION_CLOSE %s without payload.operator_id.',
                $event->id,
            ));
        }

        $expectedRaw = $payload['expected_cash'] ?? null;
        $countedRaw = $payload['counted_cash'] ?? null;
        $expectedCash = is_string($expectedRaw) && is_numeric($expectedRaw) ? $expectedRaw : null;
        $countedCash = is_string($countedRaw) && is_numeric($countedRaw) ? $countedRaw : null;
        $varianceSeverity = is_string($payload['variance_severity'] ?? null) ? $payload['variance_severity'] : null;

        $managerApproval = $payload['manager_approval'] ?? null;
        $managerOverrideBy = is_array($managerApproval) && is_string($managerApproval['supervisor_user_id'] ?? null)
            ? $managerApproval['supervisor_user_id']
            : null;

        $shift->status = ShiftStatus::Closed;
        $shift->closed_at = $payload['generated_at_device'] ?? $event->event_time_device;
        $shift->closed_by = $operatorId;
        $shift->expected_cash = $expectedCash;

        // pos_shifts_variance_calc CHECK requires either (variance NULL AND
        // actual_cash NULL) OR (variance = actual_cash - expected_cash). Only
        // set the pair when both inputs exist, and recompute the difference so
        // it matches the column scale exactly (the PG CHECK is not enforced on
        // the SQLite test DB).
        if ($countedCash !== null && $expectedCash !== null) {
            $shift->actual_cash = $countedCash;
            $shift->variance = bcsub($countedCash, $expectedCash, 4);
        } else {
            $shift->actual_cash = null;
            $shift->variance = null;
        }

        if ($varianceSeverity !== null) {
            $shift->variance_severity = $varianceSeverity;
        }
        if ($managerOverrideBy !== null) {
            $shift->manager_override_by = $managerOverrideBy;
        }

        $shift->save();
    }
}
