<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Vehicle\Domain\Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $brands = [
            'Peugeot' => ['208', '308', '508', '2008', '3008', '5008'],
            'Renault' => ['Clio', 'Megane', 'Captur', 'Kadjar', 'Scenic'],
            'Citroën' => ['C3', 'C4', 'C5 Aircross', 'Berlingo'],
            'Volkswagen' => ['Golf', 'Polo', 'Tiguan', 'Passat', 'T-Roc'],
            'BMW' => ['Serie 1', 'Serie 3', 'Serie 5', 'X1', 'X3', 'X5'],
            'Mercedes' => ['Classe A', 'Classe C', 'Classe E', 'GLA', 'GLC'],
            'Audi' => ['A3', 'A4', 'A6', 'Q3', 'Q5', 'Q7'],
            'Ford' => ['Fiesta', 'Focus', 'Kuga', 'Puma'],
            'Toyota' => ['Yaris', 'Corolla', 'RAV4', 'C-HR'],
            'Nissan' => ['Micra', 'Qashqai', 'Juke', 'X-Trail'],
        ];

        $brand = $this->faker->randomElement(array_keys($brands));
        $models = $brands[$brand];
        $model = $this->faker->randomElement($models);

        $year = $this->faker->numberBetween(2010, 2024);

        $colors = [
            'Black', 'White', 'Silver', 'Gray', 'Blue', 'Red',
            'Green', 'Beige', 'Brown', 'Yellow', 'Orange',
        ];

        return [
            'id' => Str::uuid()->toString(),
            'partner_id' => null, // Will be set by seeder
            'license_plate' => $this->generateFrenchLicensePlate(),
            'brand' => $brand,
            'model' => $model,
            'year' => $year,
            'color' => $this->faker->randomElement($colors),
            'vin' => $this->faker->optional(0.8)->bothify('VF?##########????'),
            'engine_code' => $this->faker->optional(0.5)->bothify('??####??'),
            'mileage' => $this->faker->numberBetween(5000, 250000),
            'fuel_type' => $this->faker->randomElement(['Gasoline', 'Diesel', 'Electric', 'Hybrid', 'LPG']),
            'transmission' => $this->faker->randomElement(['Manual', 'Automatic']),
            'notes' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    /**
     * Generate a realistic French license plate.
     * Old format: 123-ABC-45 or new format: AB-123-CD
     */
    private function generateFrenchLicensePlate(): string
    {
        $useNewFormat = $this->faker->boolean(70); // 70% new format

        if ($useNewFormat) {
            // New format: AB-123-CD
            $letters1 = strtoupper($this->faker->bothify('??'));
            $numbers = $this->faker->numerify('###');
            $letters2 = strtoupper($this->faker->bothify('??'));

            return "{$letters1}-{$numbers}-{$letters2}";
        } else {
            // Old format: 123-ABC-45
            $numbers1 = $this->faker->numerify('###');
            $letters = strtoupper($this->faker->bothify('???'));
            $numbers2 = $this->faker->numerify('##');

            return "{$numbers1}-{$letters}-{$numbers2}";
        }
    }

    /**
     * Indicate that the vehicle is electric.
     */
    public function electric(): static
    {
        return $this->state(fn (array $attributes) => [
            'fuel_type' => 'Electric',
        ]);
    }

    /**
     * Indicate that the vehicle is a hybrid.
     */
    public function hybrid(): static
    {
        return $this->state(fn (array $attributes) => [
            'fuel_type' => 'Hybrid',
        ]);
    }

    /**
     * Indicate that the vehicle is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
