<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Exceptions;

use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;

/**
 * Thrown by {@see AppointmentConversionService::convertToWorkOrder()} when:
 *
 *   - the appointment's current status is not in {confirmed, checked_in}, or
 *   - the appointment is already linked to a WorkOrder.
 *
 * API layer should map to HTTP 409 Conflict with the offending status.
 */
final class AppointmentNotConvertibleException extends \DomainException
{
    public static function forStatus(string $appointmentId, AppointmentStatus $status): self
    {
        return new self(
            "Appointment {$appointmentId} cannot be converted from status '{$status->value}'. "
            ."Only 'confirmed' and 'checked_in' appointments are convertible."
        );
    }

    public static function alreadyConverted(string $appointmentId, string $workOrderId): self
    {
        return new self(
            "Appointment {$appointmentId} has already been converted to work order {$workOrderId}."
        );
    }
}
