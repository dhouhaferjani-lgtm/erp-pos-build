<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = [
            'Engine Parts' => ['Oil Filter', 'Air Filter', 'Spark Plug', 'Timing Belt', 'Water Pump', 'Fuel Pump', 'Alternator', 'Starter Motor'],
            'Brake System' => ['Brake Pads', 'Brake Discs', 'Brake Fluid', 'Brake Caliper', 'Brake Lines', 'ABS Sensor'],
            'Suspension' => ['Shock Absorber', 'Coil Spring', 'Control Arm', 'Ball Joint', 'Sway Bar Link', 'Strut Mount'],
            'Electrical' => ['Battery', 'Headlight Bulb', 'Tail Light', 'Fuse', 'Relay', 'Wiring Harness', 'Sensor'],
            'Body Parts' => ['Bumper', 'Hood', 'Fender', 'Door Panel', 'Mirror', 'Grill', 'Trim'],
            'Fluids' => ['Engine Oil', 'Coolant', 'Transmission Fluid', 'Power Steering Fluid', 'Windshield Washer'],
            'Tires' => ['Summer Tire', 'Winter Tire', 'All-Season Tire', 'Performance Tire'],
            'Interior' => ['Seat Cover', 'Floor Mat', 'Steering Wheel Cover', 'Air Freshener'],
        ];

        $category = $this->faker->randomElement(array_keys($categories));
        $items = $categories[$category];
        $item = $this->faker->randomElement($items);

        // Generate realistic pricing
        $costPrice = $this->faker->randomFloat(2, 5, 500);
        $marginPercent = $this->faker->randomFloat(2, 15, 50); // 15-50% margin
        $salePrice = $costPrice * (1 + ($marginPercent / 100));

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => null, // Will be set when creating
            'company_id' => null, // Will be set by seeder
            'sku' => 'PRD-'.strtoupper(Str::random(8)),
            'name' => $item,
            'description' => $this->faker->optional(0.7)->sentence(10),
            'type' => null,
            'is_physical' => true,
            'unit' => $this->faker->randomElement(['piece', 'liter', 'set', 'pair', 'meter']),
            'cost_price' => round($costPrice, 2),
            'sale_price' => round($salePrice, 2),
            'purchase_price' => round($costPrice * 0.95, 2), // Slightly lower than cost_price
            'tax_rate' => $this->faker->randomElement([20.0, 10.0, 5.5, 0.0]), // French VAT rates
            'is_active' => $this->faker->boolean(95), // 95% active
            'barcode' => $this->faker->optional(0.8)->ean13(),
            'oem_numbers' => null,
            'cross_references' => null,
        ];
    }

    /**
     * Indicate that the product is a service (non-physical).
     */
    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => null,
            'is_physical' => false,
            'sku' => 'SVC-'.strtoupper(Str::random(6)),
            'barcode' => null,
        ]);
    }

    /**
     * Indicate that the product is goods (physical items).
     */
    public function goods(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => null,
            'is_physical' => true,
            'sku' => 'PRD-'.strtoupper(Str::random(8)),
        ]);
    }

    /**
     * Indicate that the product is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
