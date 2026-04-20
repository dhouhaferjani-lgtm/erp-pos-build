<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Listeners;

use App\Modules\Scheduling\Application\Services\AppointmentTransitionService;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderClosed;
use Psr\Log\LoggerInterface;

/**
 * Mirrors a WorkOrder -> Closed transition onto the linked appointment.
 *
 * Subscribes DIRECTLY to Plan B's `WorkOrderClosed` event — Plan D MUST
 * NOT infer closure from invoice-paid or any other indirect signal (see
 * Plan D §7.3 and the WorkOrderClosed doc-block). Target state is
 * {@see AppointmentStatus::Closed} via the system-mirror path.
 *
 * If no appointment is linked (walk-in WO), logs-and-returns.
 */
final readonly class MirrorAppointmentOnWorkOrderClosed
{
    public function __construct(
        private AppointmentRepositoryInterface $appointments,
        private AppointmentTransitionService $transitions,
        private LoggerInterface $logger,
    ) {}

    public function handle(WorkOrderClosed $event): void
    {
        $appointment = $this->appointments->findByWorkOrderId($event->work_order_id);
        if ($appointment === null) {
            $this->logger->info(
                'MirrorAppointmentOnWorkOrderClosed: no appointment linked to work_order',
                ['work_order_id' => $event->work_order_id],
            );

            return;
        }

        if ($appointment->status === AppointmentStatus::Closed) {
            return; // Idempotent.
        }

        $this->transitions->transitionForSystemMirror(
            appointmentId: $appointment->id,
            to: AppointmentStatus::Closed,
            reasonCode: 'work_order_closed',
        );
    }
}
