<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentStatusTransition;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Events\AppointmentCancelled;
use App\Modules\Scheduling\Domain\Exceptions\InvalidAppointmentTransitionException;
use App\Modules\Scheduling\Domain\Services\AppointmentStatusMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Single authoritative entry point for Appointment status transitions.
 *
 * Routes every caller through one of two guardrailed methods:
 *   - {@see transitionForPublic()}   — enforced via
 *     {@see AppointmentStatusMachine::assertAllowedForPublicTransition()};
 *     rejects system-mirrored targets (InProgress / Completed / Closed).
 *   - {@see transitionForSystemMirror()} — used by the 4 MirrorAppointmentOn*
 *     listeners (Task 12) for WO-driven state sync; bypasses the public
 *     guardrail but still validates against the full adjacency matrix.
 *
 * Both paths:
 *   - lock the row via `findForUpdate`,
 *   - write a `scheduling_appointment_status_transitions` audit row,
 *   - dispatch `AppointmentCancelled` when transitioning to Cancelled (so
 *     downstream subscribers still fire regardless of which path drove the
 *     change). Other target-specific events remain owned by the
 *     {@see AppointmentAuthoringService} explicit helper methods.
 */
final class AppointmentTransitionService
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly AppointmentStatusMachine $statusMachine,
    ) {}

    /**
     * Public HTTP controller path — used by operators / customers to move
     * the appointment. Rejects system-mirrored targets (see
     * `AppointmentStatus::isSystemMirrored()`).
     *
     * @throws InvalidAppointmentTransitionException
     */
    public function transitionForPublic(
        string $appointmentId,
        AppointmentStatus $to,
        ?string $reasonCode = null,
        ?string $triggeredByUserId = null,
    ): Appointment {
        return DB::transaction(function () use ($appointmentId, $to, $reasonCode, $triggeredByUserId): Appointment {
            $appointment = $this->appointments->findForUpdate($appointmentId);
            $from = $appointment->status;

            $this->statusMachine->assertAllowedForPublicTransition($from, $to);

            return $this->apply($appointment, $from, $to, $reasonCode, $triggeredByUserId);
        });
    }

    /**
     * System-mirror path — used by the 4 MirrorAppointmentOn* listeners
     * subscribing to Plan B's WorkOrder lifecycle events. Bypasses the
     * public guardrail because the 3 system-mirrored targets (InProgress /
     * Completed / Closed) are by construction only reachable this way.
     *
     * @throws InvalidAppointmentTransitionException
     */
    public function transitionForSystemMirror(
        string $appointmentId,
        AppointmentStatus $to,
        ?string $reasonCode = null,
    ): Appointment {
        return DB::transaction(function () use ($appointmentId, $to, $reasonCode): Appointment {
            $appointment = $this->appointments->findForUpdate($appointmentId);
            $from = $appointment->status;

            $this->statusMachine->assertAllowed($from, $to);

            return $this->apply($appointment, $from, $to, $reasonCode, null);
        });
    }

    private function apply(
        Appointment $appointment,
        AppointmentStatus $from,
        AppointmentStatus $to,
        ?string $reasonCode,
        ?string $triggeredByUserId,
    ): Appointment {
        $appointment->status = $to;
        $this->appointments->save($appointment);

        $transition = new AppointmentStatusTransition;
        $transition->id = (string) Str::uuid();
        $transition->tenant_id = $appointment->tenant_id;
        $transition->appointment_id = $appointment->id;
        $transition->from_status = $from;
        $transition->to_status = $to;
        $transition->reason_code = $reasonCode;
        $transition->triggered_by_user_id = $triggeredByUserId;
        $transition->triggered_at = Carbon::now();
        $transition->context = null;
        $transition->save();

        if ($to === AppointmentStatus::Cancelled) {
            Event::dispatch(new AppointmentCancelled(
                appointment_id: $appointment->id,
                tenant_id: $appointment->tenant_id,
                company_id: $appointment->company_id,
                reason_code: $reasonCode,
                cancelled_by_user_id: $triggeredByUserId,
                occurred_at: new \DateTimeImmutable,
            ));
        }

        return $appointment;
    }
}
