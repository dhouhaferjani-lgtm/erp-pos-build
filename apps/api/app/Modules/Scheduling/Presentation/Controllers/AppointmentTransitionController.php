<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Scheduling\Application\Commands\CancelAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\CheckInAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\ConfirmAppointmentCommand;
use App\Modules\Scheduling\Application\Commands\RescheduleAppointmentCommand;
use App\Modules\Scheduling\Application\Services\AppointmentAuthoringService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Exceptions\AppointmentConflictException;
use App\Modules\Scheduling\Domain\Exceptions\InvalidAppointmentTransitionException;
use App\Modules\Scheduling\Presentation\Requests\CancelAppointmentRequest;
use App\Modules\Scheduling\Presentation\Requests\CheckInAppointmentRequest;
use App\Modules\Scheduling\Presentation\Requests\RescheduleAppointmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Transition endpoints for an Appointment:
 *   - confirm   → Scheduled|Scheduled-with-token → Confirmed
 *   - reschedule → re-evaluates conflict + audit row
 *   - check-in  → Confirmed|Scheduled → CheckedIn (stamps actual_arrival_at)
 *   - cancel    → any non-terminal → Cancelled
 *
 * Each endpoint maps {@see AppointmentConflictException} to HTTP 409 and
 * {@see InvalidAppointmentTransitionException} to HTTP 422. The 4 system-
 * mirrored states (InProgress / Completed / Closed / Cancelled-from-WO) are
 * unreachable here; those go through the mirror listeners.
 */
final class AppointmentTransitionController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly AppointmentRepositoryInterface $appointments,
        private readonly AppointmentAuthoringService $authoring,
    ) {}

    public function confirm(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('scheduling.appointments.update')) {
            abort(403);
        }
        $appt = $this->requireAppointment($id);

        try {
            $appointment = $this->authoring->confirm(new ConfirmAppointmentCommand(
                appointment_id: $appt->id,
                confirmed_by_user_id: (string) $user->id,
            ));
        } catch (InvalidAppointmentTransitionException $e) {
            return $this->invalidTransition($e);
        }

        return response()->json(['data' => ['id' => $appointment->id, 'status' => $appointment->status->value]]);
    }

    public function reschedule(RescheduleAppointmentRequest $request, string $id): JsonResponse
    {
        $appt = $this->requireAppointment($id);
        $user = $request->user();
        $userId = $user !== null ? (string) $user->id : null;

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        try {
            $appointment = $this->authoring->reschedule(new RescheduleAppointmentCommand(
                appointment_id: $appt->id,
                new_bay_id: isset($data['new_bay_id']) && is_string($data['new_bay_id']) ? $data['new_bay_id'] : null,
                new_scheduled_start: new \DateTimeImmutable((string) $data['new_scheduled_start']),
                new_scheduled_end: new \DateTimeImmutable((string) $data['new_scheduled_end']),
                rescheduled_by_user_id: $userId,
            ));
        } catch (AppointmentConflictException $e) {
            return $this->conflict($e);
        } catch (InvalidAppointmentTransitionException $e) {
            return $this->invalidTransition($e);
        }

        return response()->json([
            'data' => [
                'id' => $appointment->id,
                'status' => $appointment->status->value,
                'scheduled_start' => $appointment->scheduled_start->toIso8601String(),
                'scheduled_end' => $appointment->scheduled_end->toIso8601String(),
                'bay_id' => $appointment->bay_id,
            ],
        ]);
    }

    public function checkIn(CheckInAppointmentRequest $request, string $id): JsonResponse
    {
        $appt = $this->requireAppointment($id);
        $user = $request->user();
        $userId = $user !== null ? (string) $user->id : null;

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $arrival = isset($data['actual_arrival_at']) && is_string($data['actual_arrival_at'])
            ? new \DateTimeImmutable($data['actual_arrival_at'])
            : new \DateTimeImmutable;

        try {
            $appointment = $this->authoring->checkIn(new CheckInAppointmentCommand(
                appointment_id: $appt->id,
                actual_arrival_at: $arrival,
                checked_in_by_user_id: $userId,
            ));
        } catch (InvalidAppointmentTransitionException $e) {
            return $this->invalidTransition($e);
        }

        return response()->json([
            'data' => [
                'id' => $appointment->id,
                'status' => $appointment->status->value,
                'actual_arrival_at' => $appointment->actual_arrival_at?->toIso8601String(),
            ],
        ]);
    }

    public function cancel(CancelAppointmentRequest $request, string $id): JsonResponse
    {
        $appt = $this->requireAppointment($id);
        $user = $request->user();
        $userId = $user !== null ? (string) $user->id : null;

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        try {
            $appointment = $this->authoring->cancel(new CancelAppointmentCommand(
                appointment_id: $appt->id,
                reason_code: isset($data['reason_code']) && is_string($data['reason_code']) ? $data['reason_code'] : null,
                cancelled_by_user_id: $userId,
            ));
        } catch (InvalidAppointmentTransitionException $e) {
            return $this->invalidTransition($e);
        }

        return response()->json([
            'data' => [
                'id' => $appointment->id,
                'status' => $appointment->status->value,
            ],
        ]);
    }

    private function requireAppointment(string $id): Appointment
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $appt = $this->appointments->findById($id);
        if ($appt === null || $appt->company_id !== $this->companyContext->requireCompanyId()) {
            abort(404);
        }

        return $appt;
    }

    private function conflict(AppointmentConflictException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error_code' => 'appointment_conflict',
            'conflict' => $e->detail->toArray(),
        ], 409);
    }

    private function invalidTransition(InvalidAppointmentTransitionException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error_code' => 'invalid_transition',
        ], 422);
    }
}
