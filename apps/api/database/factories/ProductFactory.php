<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * ISO 4217 currency that drives the monetary decimal scale.
     *
     * Defaults to TND (scale 3), the codebase's primary vertical currency.
     * Override per-instance via {@see currency()}.
     */
    private const DEFAULT_CURRENCY = 'TND';

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

        // Generate realistic pricing using bcmath (no float arithmetic) so the
        // emitted values are canonical numeric strings at the currency scale.
        $scale = CurrencyScale::for(self::DEFAULT_CURRENCY);
        $costPrice = CurrencyScale::bcformat($this->faker->randomFloat(2, 5, 500), $scale);
        $marginPercent = CurrencyScale::bcformat($this->faker->randomFloat(2, 15, 50), 2); // 15-50% margin
        $salePrice = bcmul($costPrice, bcadd('1', bcdiv($marginPercent, '100', $scale + 2), $scale + 2), $scale);
        $purchasePrice = bcmul($costPrice, '0.95', $scale); // Slightly lower than cost_price

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => Str::uuid()->toString(), // Falls back to a stub UUID; override via ->for($tenant)
            'company_id' => Str::uuid()->toString(), // Stub UUID — overridden by ->for($company); does not cascade a Company insert
            'sku' => 'PRD-'.strtoupper(Str::random(8)),
            'name' => $item,
            'description' => $this->faker->optional(0.7)->sentence(10),
            'type' => null,
            'is_physical' => true,
            'unit' => $this->faker->randomElement(['piece', 'liter', 'set', 'pair', 'meter']),
            'cost_price' => $costPrice,
            'sale_price' => $salePrice,
            'purchase_price' => $purchasePrice,
            'max_discount_percent' => null,
            'tax_rate' => CurrencyScale::bcformat($this->faker->randomElement([20.0, 10.0, 5.5, 0.0]), 2), // VAT rates
            'is_active' => $this->faker->boolean(95), // 95% active
            'barcode' => $this->faker->optional(0.8)->ean13(),
            'oem_numbers' => null,
            'cross_references' => null,
        ];
    }

    /**
     * Re-scale the monetary fields to the decimal scale of the given currency.
     *
     * Default monetary values are emitted at TND scale (3). This state
     * re-formats the already-generated prices to the requested currency's
     * ISO 4217 scale, keeping every monetary attribute a canonical
     * numeric string (e.g. EUR -> 2dp, JPY -> 0dp, TND -> 3dp).
     */
    public function currency(string $currencyCode): static
    {
        $scale = CurrencyScale::for($currencyCode);

        return $this->state(fn (array $attributes): array => [
            'cost_price' => CurrencyScale::bcformat($attributes['cost_price'] ?? '0', $scale),
            'sale_price' => CurrencyScale::bcformat($attributes['sale_price'] ?? '0', $scale),
            'purchase_price' => CurrencyScale::bcformat($attributes['purchase_price'] ?? '0', $scale),
        ]);
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
