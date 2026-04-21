<?php

declare(strict_types=1);

namespace Database\Factories\Scheduling;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppointmentService>
 */
final class AppointmentServiceFactory extends Factory
{
    /** @var class-string<AppointmentService> */
    protected $model = AppointmentService::class;

    /**
     * Default state: a single 60-minute service reference with a placeholder
     * estimated price formatted to NUMERIC(14,3).
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
            'service_ref_type' => AppointmentService::REF_TYPE_SERVICE,
            'service_ref_id' => (string) Str::uuid(),
            'display_name' => $this->faker->words(3, true),
            'estimated_duration_minutes' => 60,
            'estimated_price' => '45.000',
            'display_order' => 0,
        ];
    }

    public function bundle(): self
    {
        return $this->state(fn (): array => [
            'service_ref_type' => AppointmentService::REF_TYPE_BUNDLE,
        ]);
    }
}
