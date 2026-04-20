<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Domain\Exceptions;

use App\Modules\Scheduling\Domain\ValueObjects\ConflictDetail;

/**
 * Thrown by the authoring service when an appointment cannot be created /
 * rescheduled due to a bay overlap, out-of-hours slot, or technician-busy
 * conflict. The API layer maps this to HTTP 409.
 */
final class AppointmentConflictException extends \DomainException
{
    public function __construct(
        public readonly ConflictDetail $detail,
    ) {
        parent::__construct($detail->message);
    }
}
