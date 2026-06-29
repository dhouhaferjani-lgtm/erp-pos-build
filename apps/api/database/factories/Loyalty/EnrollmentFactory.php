<?php

declare(strict_types=1);

namespace Database\Factories\Loyalty;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Enrollment> */
final class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'program_id' => $this->faker->uuid(),
            'member_id' => $this->faker->uuid(),
            'current_balance' => '0.000',
            'lifetime_earned' => '0.000',
            'lifetime_redeemed' => '0.000',
            'current_tier_id' => null,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ];
    }
}
