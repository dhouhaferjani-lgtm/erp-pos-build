<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Exceptions;

use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use DomainException;

/**
 * Raised when AppointmentStatusMachine::transition() is asked to apply a
 * status change that is not in the allowed-transitions matrix, or that
 * targets a system-mirrored state via the public path.
 */
final class InvalidAppointmentTransitionException extends DomainException
{
    public function __construct(
        public readonly AppointmentStatus $from,
        public readonly AppointmentStatus $to,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : "Invalid appointment transition: {$from->value} -> {$to->value}",
        );
    }
}
