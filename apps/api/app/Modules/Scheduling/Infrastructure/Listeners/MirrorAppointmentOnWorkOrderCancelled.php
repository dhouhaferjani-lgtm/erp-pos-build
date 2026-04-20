<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Listeners;

use App\Modules\Scheduling\Application\Services\AppointmentTransitionService;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCancelled;
use Psr\Log\LoggerInterface;

/**
 * Mirrors a WorkOrder -> Cancelled transition onto the linked appointment.
 *
 * Subscribes directly to Plan B's `WorkOrderCancelled` event. Target state
 * is {@see AppointmentStatus::Cancelled} via the system-mirror path. The
 * WO's reason_code is propagated into the transition audit row.
 *
 * If no appointment is linked (walk-in WO), logs-and-returns.
 */
final readonly class MirrorAppointmentOnWorkOrderCancelled
{
    public function __construct(
        private AppointmentRepositoryInterface $appointments,
        private AppointmentTransitionService $transitions,
        private LoggerInterface $logger,
    ) {}

    public function handle(WorkOrderCancelled $event): void
    {
        $appointment = $this->appointments->findByWorkOrderId($event->work_order_id);
        if ($appointment === null) {
            $this->logger->info(
                'MirrorAppointmentOnWorkOrderCancelled: no appointment linked to work_order',
                ['work_order_id' => $event->work_order_id],
            );

            return;
        }

        if ($appointment->status === AppointmentStatus::Cancelled) {
            return; // Idempotent — already cancelled (operator-cancelled first).
        }

        $this->transitions->transitionForSystemMirror(
            appointmentId: $appointment->id,
            to: AppointmentStatus::Cancelled,
            reasonCode: $event->reason_code,
        );
    }
}
