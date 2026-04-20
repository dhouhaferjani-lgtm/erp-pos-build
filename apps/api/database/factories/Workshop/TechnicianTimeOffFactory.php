<?php

declare(strict_types=1);

namespace Database\Factories\Workshop;

use App\Modules\Workshop\Technician\Domain\Enums\TimeOffReason;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeOff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TechnicianTimeOff>
 */
final class TechnicianTimeOffFactory extends Factory
{
    /** @var class-string<TechnicianTimeOff> */
    protected $model = TechnicianTimeOff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('+1 day', '+60 days');
        $end = (clone $start)->modify('+3 days');

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => fn (array $attrs): string => (string) TechnicianProfile::query()
                ->whereKey($attrs['technician_profile_id'])
                ->value('tenant_id'),
            'technician_profile_id' => TechnicianProfile::factory(),
            'starts_at' => $start,
            'ends_at' => $end,
            'reason_code' => TimeOffReason::Vacation->value,
            'is_full_day' => true,
            'is_approved' => false,
            'approved_by_user_id' => null,
            'notes' => null,
        ];
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [
            'is_approved' => true,
        ]);
    }
}
