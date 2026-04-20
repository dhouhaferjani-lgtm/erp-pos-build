<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Commands;

final readonly class CheckInAppointmentCommand
{
    public function __construct(
        public string $appointment_id,
        public \DateTimeImmutable $actual_arrival_at,
        public ?string $checked_in_by_user_id,
    ) {}
}
