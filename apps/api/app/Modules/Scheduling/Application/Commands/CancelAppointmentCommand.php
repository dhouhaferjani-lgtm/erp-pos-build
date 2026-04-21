<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Commands;

final readonly class CancelAppointmentCommand
{
    public function __construct(
        public string $appointment_id,
        public ?string $reason_code,
        public ?string $cancelled_by_user_id,
    ) {}
}
