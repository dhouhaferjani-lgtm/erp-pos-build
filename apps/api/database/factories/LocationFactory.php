<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
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
            'company_id' => Company::factory(),
            'name' => $this->faker->company().' - '.$this->faker->city(),
            'code' => strtoupper($this->faker->lexify('LOC-???')),
            'type' => 'shop',
            'address_street' => $this->faker->streetAddress(),
            'address_city' => $this->faker->city(),
            'address_postal_code' => $this->faker->postcode(),
            'address_country' => $this->faker->randomElement(['TN', 'FR', 'US']),
            'is_active' => true,
        ];
    }
}
