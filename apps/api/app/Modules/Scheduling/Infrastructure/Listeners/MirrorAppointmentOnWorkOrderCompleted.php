<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Infrastructure\Listeners;

use App\Modules\Scheduling\Application\Services\AppointmentTransitionService;
use App\Modules\Scheduling\Domain\Contracts\AppointmentRepositoryInterface;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use Psr\Log\LoggerInterface;

/**
 * Mirrors a WorkOrder -> Completed transition onto the linked appointment.
 *
 * Subscribes directly to Plan B's versioned work-order completion events. Target
 * state is {@see AppointmentStatus::Completed} via the system-mirror path.
 *
 * If no appointment is linked (walk-in WO), logs-and-returns.
 */
final readonly class MirrorAppointmentOnWorkOrderCompleted
{
    public function __construct(
        private AppointmentRepositoryInterface $appointments,
        private AppointmentTransitionService $transitions,
        private LoggerInterface $logger,
    ) {}

    public function handle(object $event): void
    {
        /** @var string $workOrderId */
        $workOrderId = $event->work_order_id; // @phpstan-ignore property.notFound
        $appointment = $this->appointments->findByWorkOrderId($workOrderId);
        if ($appointment === null) {
            $this->logger->info(
                'MirrorAppointmentOnWorkOrderCompleted: no appointment linked to work_order',
                ['work_order_id' => $workOrderId],
            );

            return;
        }

        if ($appointment->status === AppointmentStatus::Completed) {
            return; // Idempotent.
        }

        $this->transitions->transitionForSystemMirror(
            appointmentId: $appointment->id,
            to: AppointmentStatus::Completed,
            reasonCode: 'work_order_completed',
        );
    }
}
