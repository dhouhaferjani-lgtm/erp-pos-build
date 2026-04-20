<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Application\Commands\BookAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\CancelAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\CheckInAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\ConfirmAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\RescheduleAppointmentCommand;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentService;
use App\Modules\Scheduling\Domain\AppointmentStatusTransition;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Contracts\AppointmentSequenceInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Events\AppointmentCancelled;
use App\Modules\Scheduling\Domain\Events\AppointmentCheckedIn;
use App\Modules\Scheduling\Domain\Events\AppointmentConfirmed;
use App\Modules\Scheduling\Domain\Events\AppointmentRescheduled;
use App\Modules\Scheduling\Domain\Events\AppointmentScheduled;
use App\Modules\Scheduling\Domain\Exceptions\AppointmentConflictException;
use App\Modules\Scheduling\Domain\Exceptions\InvalidAppointmentTransitionException;
use App\Modules\Scheduling\Domain\Services\AppointmentStatusMachine;
use App\Modules\Scheduling\Domain\ValueObjects\ConflictDetail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Application service orchestrating all appointment authoring flows:
 *
 *   create     — validates overlap, persists appointment + planned services.
 *   reschedule — revalidates overlap (with self-excluded), records transition.
 *   cancel     — marks cancelled, emits AppointmentCancelled.
 *   confirm    — Scheduled → Confirmed (operator / customer SMS).
 *   checkIn    — Confirmed|Scheduled → CheckedIn, stamps actual_arrival_at.
 *
 * All mutations happen inside DB transactions. Bay-overlap conflicts are
 * caught from the repository AND from PostgreSQL's 23P01 exclusion
 * violation (belt-and-braces) and translated to a 409-mappable
 * {@see AppointmentConflictException}.
 */
final class AppointmentAuthoringService
{
    /**
     * PostgreSQL exclusion_violation SQLSTATE. Raised by the GiST exclusion
     * constraint when a concurrent insert sneaks past the pre-flight
     * {@see AppointmentRepositoryInterface::findOverlapping()} check.
     */
    private const PG_EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly AppointmentStatusMachine $statusMachine,
        private readonly AppointmentSequenceInterface $appointmentSequence,
    ) {}

    public function create(BookAppointmentCommand $command): Appointment
    {
        if ($command->scheduled_end <= $command->scheduled_start) {
            throw new \InvalidArgumentException('scheduled_end must be after scheduled_start.');
        }

        $this->assertNoOverlap(
            bayId: $command->bay_id,
            start: $command->scheduled_start,
            end: $command->scheduled_end,
            excludeAppointmentId: null,
        );

        return DB::transaction(function () use ($command): Appointment {
            $appointment = new Appointment;
            $appointment->id = (string) Str::uuid();
            $appointment->tenant_id = $command->tenant_id;
            $appointment->company_id = $command->company_id;
            $appointment->location_id = $command->location_id;
            $appointment->appointment_number = $this->appointmentSequence->nextNumber(
                $command->company_id,
                (int) Carbon::instance($command->scheduled_start)->format('Y'),
            );
            $appointment->bay_id = $command->bay_id;
            $appointment->primary_technician_profile_id = $command->primary_technician_profile_id;
            $appointment->customer_partner_id = $command->customer_partner_id;
            $appointment->vehicle_id = $command->vehicle_id;
            $appointment->customer_name = $command->customer_name;
            $appointment->customer_phone = $command->customer_phone;
            $appointment->customer_email = $command->customer_email;
            $appointment->vehicle_plate = $command->vehicle_plate;
            $appointment->vehicle_description = $command->vehicle_description;
            $appointment->appointment_type = $command->appointment_type;
            $appointment->wait_type = $command->wait_type;
            $appointment->status = AppointmentStatus::Scheduled;
            $appointment->scheduled_start = Carbon::instance($command->scheduled_start);
            $appointment->scheduled_end = Carbon::instance($command->scheduled_end);
            $appointment->estimated_duration_minutes = $command->estimated_duration_minutes;
            $appointment->services_summary = $command->services_summary;
            $appointment->customer_notes = $command->customer_notes;
            $appointment->internal_notes = $command->internal_notes;
            $appointment->source = $command->source;
            $appointment->is_auto_confirmed = false;

            try {
                $this->appointments->save($appointment);
            } catch (QueryException $e) {
                if ($this->isExclusionViolation($e)) {
                    throw new AppointmentConflictException(
                        ConflictDetail::overlap([], 'Bay is already booked for the requested window.'),
                    );
                }
                throw $e;
            }

            foreach ($command->planned_services as $planned) {
                $line = new AppointmentService;
                $line->id = (string) Str::uuid();
                $line->tenant_id = $command->tenant_id;
                $line->appointment_id = $appointment->id;
                $line->service_ref_type = $planned['service_ref_type'];
                $line->service_ref_id = $planned['service_ref_id'];
                $line->display_name = $planned['display_name'];
                $line->estimated_duration_minutes = $planned['estimated_duration_minutes'];
                if (isset($planned['estimated_price'])) {
                    $line->estimated_price = $planned['estimated_price'];
                }
                $line->display_order = $planned['display_order'] ?? 0;
                $line->save();
            }

            Event::dispatch(new AppointmentScheduled(
                appointment_id: $appointment->id,
                tenant_id: $appointment->tenant_id,
                company_id: $appointment->company_id,
                location_id: $appointment->location_id,
                bay_id: $appointment->bay_id,
                customer_partner_id: $appointment->customer_partner_id,
                vehicle_id: $appointment->vehicle_id,
                scheduled_start: \DateTimeImmutable::createFromInterface($appointment->scheduled_start),
                scheduled_end: \DateTimeImmutable::createFromInterface($appointment->scheduled_end),
                source: $appointment->source->value,
                occurred_at: new \DateTimeImmutable,
            ));

            return $appointment;
        });
    }

    public function reschedule(RescheduleAppointmentCommand $command): Appointment
    {
        if ($command->new_scheduled_end <= $command->new_scheduled_start) {
            throw new \InvalidArgumentException('new_scheduled_end must be after new_scheduled_start.');
        }

        return DB::transaction(function () use ($command): Appointment {
            $appointment = $this->appointments->findForUpdate($command->appointment_id);

            $this->assertReschedulable($appointment);

            $this->assertNoOverlap(
                bayId: $command->new_bay_id,
                start: $command->new_scheduled_start,
                end: $command->new_scheduled_end,
                excludeAppointmentId: $appointment->id,
            );

            $previousBayId = $appointment->bay_id;
            $previousStart = \DateTimeImmutable::createFromInterface($appointment->scheduled_start);
            $previousEnd = \DateTimeImmutable::createFromInterface($appointment->scheduled_end);

            $appointment->bay_id = $command->new_bay_id;
            $appointment->scheduled_start = Carbon::instance($command->new_scheduled_start);
            $appointment->scheduled_end = Carbon::instance($command->new_scheduled_end);
            $appointment->estimated_duration_minutes = (int) (
                ($command->new_scheduled_end->getTimestamp() - $command->new_scheduled_start->getTimestamp()) / 60
            );

            try {
                $this->appointments->save($appointment);
            } catch (QueryException $e) {
                if ($this->isExclusionViolation($e)) {
                    throw new AppointmentConflictException(
                        ConflictDetail::overlap([], 'Bay is already booked for the requested window.'),
                    );
                }
                throw $e;
            }

            Event::dispatch(new AppointmentRescheduled(
                appointment_id: $appointment->id,
                tenant_id: $appointment->tenant_id,
                company_id: $appointment->company_id,
                previous_bay_id: $previousBayId,
                new_bay_id: $appointment->bay_id,
                previous_scheduled_start: $previousStart,
                previous_scheduled_end: $previousEnd,
                new_scheduled_start: $command->new_scheduled_start,
                new_scheduled_end: $command->new_scheduled_end,
                rescheduled_by_user_id: $command->rescheduled_by_user_id,
                occurred_at: new \DateTimeImmutable,
            ));

            return $appointment;
        });
    }

    public function cancel(CancelAppointmentCommand $command): Appointment
    {
        return DB::transaction(function () use ($command): Appointment {
            $appointment = $this->appointments->findForUpdate($command->appointment_id);
            $from = $appointment->status;
            $this->statusMachine->assertAllowed($from, AppointmentStatus::Cancelled);

            $appointment->status = AppointmentStatus::Cancelled;
            $this->appointments->save($appointment);
            $this->writeTransition(
                $appointment,
                $from,
                AppointmentStatus::Cancelled,
                $command->reason_code,
                $command->cancelled_by_user_id,
            );

            Event::dispatch(new AppointmentCancelled(
                appointment_id: $appointment->id,
                tenant_id: $appointment->tenant_id,
                company_id: $appointment->company_id,
                reason_code: $command->reason_code,
                cancelled_by_user_id: $command->cancelled_by_user_id,
                occurred_at: new \DateTimeImmutable,
            ));

            return $appointment;
        });
    }

    public function confirm(ConfirmAppointmentCommand $command): Appointment
    {
        return DB::transaction(function () use ($command): Appointment {
            $appointment = $this->appointments->findForUpdate($command->appointment_id);
            $from = $appointment->status;
            $this->statusMachine->assertAllowed($from, AppointmentStatus::Confirmed);

            $appointment->status = AppointmentStatus::Confirmed;
            $appointment->is_auto_confirmed = false;
            $this->appointments->save($appointment);
            $this->writeTransition(
                $appointment,
                $from,
                AppointmentStatus::Confirmed,
                null,
                $command->confirmed_by_user_id,
            );

            Event::dispatch(new AppointmentConfirmed(
                appointment_id: $appointment->id,
                tenant_id: $appointment->tenant_id,
                company_id: $appointment->company_id,
                confirmed_by_user_id: $command->confirmed_by_user_id,
                occurred_at: new \DateTimeImmutable,
            ));

            return $appointment;
        });
    }

    public function checkIn(CheckInAppointmentCommand $command): Appointment
    {
        return DB::transaction(function () use ($command): Appointment {
            $appointment = $this->appointments->findForUpdate($command->appointment_id);
            $from = $appointment->status;
            $this->statusMachine->assertAllowed($from, AppointmentStatus::CheckedIn);

            $appointment->status = AppointmentStatus::CheckedIn;
            $appointment->actual_arrival_at = Carbon::instance($command->actual_arrival_at);
            $this->appointments->save($appointment);
            $this->writeTransition(
                $appointment,
                $from,
                AppointmentStatus::CheckedIn,
                null,
                $command->checked_in_by_user_id,
            );

            Event::dispatch(new AppointmentCheckedIn(
                appointment_id: $appointment->id,
                tenant_id: $appointment->tenant_id,
                company_id: $appointment->company_id,
                vehicle_id: $appointment->vehicle_id,
                checked_in_by_user_id: $command->checked_in_by_user_id,
                actual_arrival_at: $command->actual_arrival_at,
                occurred_at: new \DateTimeImmutable,
            ));

            return $appointment;
        });
    }

    /**
     * Reschedule is only permitted while the appointment has not yet been
     * checked in and is not in a terminal or system-mirrored state. Public
     * callers cannot move a CheckedIn / InProgress / Completed / Closed /
     * Cancelled / NoShow appointment.
     *
     * @throws InvalidAppointmentTransitionException
     */
    private function assertReschedulable(Appointment $appointment): void
    {
        $allowed = [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed];
        if (! in_array($appointment->status, $allowed, true)) {
            throw new InvalidAppointmentTransitionException(
                from: $appointment->status,
                to: $appointment->status,
                message: "Appointment cannot be rescheduled while in status '{$appointment->status->value}'."
                    .' Reschedule is only permitted before check-in (Scheduled or Confirmed).',
            );
        }
    }

    private function assertNoOverlap(
        ?string $bayId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?string $excludeAppointmentId,
    ): void {
        if ($bayId === null) {
            return; // Unassigned appointments cannot bay-overlap; operator resolves later.
        }
        $overlapping = $this->appointments->findOverlapping($bayId, $start, $end, $excludeAppointmentId);
        if ($overlapping->isNotEmpty()) {
            /** @var list<string> $ids */
            $ids = $overlapping->pluck('id')->values()->all();
            throw new AppointmentConflictException(
                ConflictDetail::overlap($ids, 'Bay is already booked for the requested window.'),
            );
        }
    }

    private function isExclusionViolation(QueryException $e): bool
    {
        return $e->getCode() === self::PG_EXCLUSION_VIOLATION
            || (isset($e->errorInfo[0]) && $e->errorInfo[0] === self::PG_EXCLUSION_VIOLATION);
    }

    private function writeTransition(
        Appointment $appointment,
        AppointmentStatus $from,
        AppointmentStatus $to,
        ?string $reasonCode,
        ?string $triggeredByUserId,
    ): void {
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
    }
}
