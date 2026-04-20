<?php

declare(strict_types=1);

namespace Database\Factories\Workshop;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Domain\Enums\EmploymentStatus;
use App\Modules\Workshop\Technician\Domain\Enums\SkillLevel;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TechnicianProfile>
 */
final class TechnicianProfileFactory extends Factory
{
    /** @var class-string<TechnicianProfile> */
    protected $model = TechnicianProfile::class;

    /**
     * Default state: active general technician with a standard 5-day schedule and 2 specialties.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'skill_level' => SkillLevel::General->value,
            'specialties' => [
                SpecialtyCode::GeneralService->value,
                SpecialtyCode::Brakes->value,
            ],
            'hourly_cost_rate' => '25.000',
            'hourly_billing_rate' => '60.000',
            'currency' => 'EUR',
            'weekly_schedule' => [
                'mon' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'tue' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'wed' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'thu' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'fri' => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']],
                'sat' => [],
                'sun' => [],
            ],
            'hire_date' => $this->faker->dateTimeBetween('-3 years', '-6 months')->format('Y-m-d'),
            'employment_status' => EmploymentStatus::Active->value,
            'employee_code' => strtoupper(Str::random(6)),
            'notes' => null,
            'national_id' => null,
            'personal_address' => null,
            'personal_phone' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function onLeave(): self
    {
        return $this->state(fn (): array => [
            'employment_status' => EmploymentStatus::OnLeave->value,
        ]);
    }

    public function senior(): self
    {
        return $this->state(fn (): array => [
            'skill_level' => SkillLevel::Senior->value,
            'hourly_cost_rate' => '35.000',
            'hourly_billing_rate' => '85.000',
        ]);
    }
}
