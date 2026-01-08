<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'tenant_id' => \App\Modules\Tenant\Domain\Tenant::factory(),
            'company_id' => fn (array $attributes) => \App\Modules\Company\Domain\Company::factory(['tenant_id' => $attributes['tenant_id']]),
            'name' => $this->faker->company().' - '.$this->faker->city(),
            'code' => strtoupper($this->faker->lexify('LOC-???')),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'postal_code' => $this->faker->postcode(),
            'country_code' => $this->faker->randomElement(['TN', 'FR', 'US']),
            'is_active' => true,
        ];
    }
}
