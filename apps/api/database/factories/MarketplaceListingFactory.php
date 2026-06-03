<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Marketplace\Domain\Enums\ListingStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceListing>
 */
class MarketplaceListingFactory extends Factory
{
    protected $model = MarketplaceListing::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $brands = ['Bosch', 'TRW', 'Valeo', 'Continental', 'Denso', 'NGK', 'Mann-Filter', 'Sachs', 'Brembo'];

        return [
            'seller_id' => MarketplaceSeller::factory(),
            'country_code' => 'TN',
            'platform_article_id' => fake()->optional(0.5)->uuid(),
            'article_number' => fake()->regexify('[A-Z0-9]{2,4}[- ]?[0-9]{4,8}'),
            'barcode' => fake()->optional(0.7)->ean13(),
            'product_name' => fake()->randomElement(['Brake Pads', 'Oil Filter', 'Spark Plug', 'Air Filter', 'Timing Belt']),
            'supplier_brand' => fake()->randomElement($brands),
            'quality_tier' => fake()->randomElement(['oe', 'oem', 'aftermarket', null]),
            // price: money cast decimal:3 (default TND). quantity: cast decimal:4.
            'price' => CurrencyScale::bcformat(fake()->randomFloat(3, 5, 500), 3),
            'currency' => 'TND',
            'quantity_available' => CurrencyScale::bcformat(fake()->randomFloat(2, 1, 100), 4),
            'min_order_quantity' => CurrencyScale::bcformat(1, 4),
            'listing_status' => ListingStatus::Active,
        ];
    }

    /**
     * Re-scale the listing price to the decimal scale of the given currency.
     */
    public function currency(string $currencyCode): static
    {
        $scale = CurrencyScale::for($currencyCode);

        return $this->state(fn (array $attributes): array => [
            'currency' => $currencyCode,
            'price' => CurrencyScale::bcformat($attributes['price'] ?? '0', $scale),
        ]);
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes): array => [
            'listing_status' => ListingStatus::Active,
            'quantity_available' => CurrencyScale::bcformat(fake()->randomFloat(2, 10, 100), 4),
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'listing_status' => ListingStatus::OutOfStock,
            'quantity_available' => CurrencyScale::bcformat(0, 4),
        ]);
    }

    public function tire(): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_name' => 'Summer Tire 205/55R16',
            'quality_tier' => 'oe',
        ]);
    }

    public function glass(): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_name' => 'Windshield Glass',
            'quality_tier' => 'oem',
        ]);
    }
}
