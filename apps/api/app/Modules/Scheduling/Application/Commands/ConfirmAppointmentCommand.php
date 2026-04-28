<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Application\Commands;

final readonly class ConfirmAppointmentCommand
{
    public function __construct(
        public string $appointment_id,
        public ?string $confirmed_by_user_id,
    ) {}
}
