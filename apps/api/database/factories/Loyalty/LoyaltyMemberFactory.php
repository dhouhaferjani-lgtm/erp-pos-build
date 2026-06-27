<?php

declare(strict_types=1);

namespace Database\Factories\Loyalty;

use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoyaltyMember> */
final class LoyaltyMemberFactory extends Factory
{
    protected $model = LoyaltyMember::class;

    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'customer_id' => null,
            'loyaltyable_type' => 'contact',
            'loyaltyable_id' => $this->faker->uuid(),
            'phone' => $this->faker->unique()->numerify('+216########'),
            'email' => null,
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ];
    }
}
