<?php

declare(strict_types=1);

namespace Database\Factories\Scheduling;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentStatusTransition;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppointmentStatusTransition>
 */
final class AppointmentStatusTransitionFactory extends Factory
{
    /** @var class-string<AppointmentStatusTransition> */
    protected $model = AppointmentStatusTransition::class;

    /**
     * Default: a Scheduled → Confirmed transition fired "now".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $appointment = Appointment::factory()->create();

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $appointment->tenant_id,
            'appointment_id' => $appointment->id,
            'from_status' => AppointmentStatus::Scheduled->value,
            'to_status' => AppointmentStatus::Confirmed->value,
            'reason_code' => null,
            'triggered_by_user_id' => null,
            'triggered_at' => Carbon::now(),
            'context' => null,
        ];
    }
}
