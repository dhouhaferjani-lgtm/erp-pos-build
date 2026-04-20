<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Listeners;

use App\Modules\Scheduling\Application\Services\AppointmentTransitionService;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderStarted;
use Psr\Log\LoggerInterface;

/**
 * Mirrors a WorkOrder -> InProgress transition onto the linked appointment.
 *
 * Subscribes directly to Plan B's `WorkOrderStarted` event (per Plan D §7.3).
 * Transition is performed via the authorized system-mirror path, which
 * bypasses the public guardrail for the 3 system-mirrored target states
 * (InProgress / Completed / Closed). The target for this listener is
 * {@see AppointmentStatus::InProgress}.
 *
 * If no appointment is linked to the given WorkOrder (e.g. walk-in WO
 * created without a prior booking) the listener logs-and-returns silently
 * — not every WO has an appointment ancestor.
 */
final readonly class MirrorAppointmentOnWorkOrderStarted
{
    public function __construct(
        private AppointmentRepositoryInterface $appointments,
        private AppointmentTransitionService $transitions,
        private LoggerInterface $logger,
    ) {}

    public function handle(WorkOrderStarted $event): void
    {
        $appointment = $this->appointments->findByWorkOrderId($event->work_order_id);
        if ($appointment === null) {
            $this->logger->info(
                'MirrorAppointmentOnWorkOrderStarted: no appointment linked to work_order',
                ['work_order_id' => $event->work_order_id],
            );

            return;
        }

        if ($appointment->status === AppointmentStatus::InProgress) {
            return; // Idempotent — already mirrored (e.g. WO paused/resumed cycle).
        }

        $this->transitions->transitionForSystemMirror(
            appointmentId: $appointment->id,
            to: AppointmentStatus::InProgress,
            reasonCode: 'work_order_started',
        );
    }
}
