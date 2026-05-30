<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Cart\Domain\Enums\CartItemSource;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogCartItem>
 */
class CatalogCartItemFactory extends Factory
{
    protected $model = CatalogCartItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cart_id' => null,
            'article_name' => fake()->randomElement(['Brake Pads', 'Oil Filter', 'Spark Plug', 'Air Filter']),
            'article_number' => fake()->regexify('[A-Z0-9]{2,4}[- ]?[0-9]{4,8}'),
            'supplier_brand' => fake()->randomElement(['Bosch', 'TRW', 'Valeo', 'Continental']),
            // quantity: cast decimal:4. unit_price: money cast decimal:3 (default TND).
            'quantity' => CurrencyScale::bcformat(fake()->randomFloat(2, 1, 10), 4),
            'unit_price' => CurrencyScale::bcformat(fake()->randomFloat(3, 5, 200), 3),
            'currency' => 'TND',
            'source' => CartItemSource::Catalog,
            'sort_order' => 0,
        ];
    }

    /**
     * Re-scale the unit price to the decimal scale of the given currency.
     */
    public function currency(string $currencyCode): static
    {
        $scale = CurrencyScale::for($currencyCode);

        return $this->state(fn (array $attributes): array => [
            'currency' => $currencyCode,
            'unit_price' => CurrencyScale::bcformat($attributes['unit_price'] ?? '0', $scale),
        ]);
    }

    public function marketplace(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => CartItemSource::Marketplace,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => CartItemSource::Manual,
            'product_id' => null,
            'marketplace_listing_id' => null,
        ]);
    }
}
