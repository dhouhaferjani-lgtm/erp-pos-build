<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Enums\SellerType;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceSeller>
 */
class MarketplaceSellerFactory extends Factory
{
    protected $model = MarketplaceSeller::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'company_id' => null,
            'seller_type' => SellerType::ErpTenant,
            'seller_status' => SellerStatus::Active,
            'display_name' => fake()->company(),
            'country_code' => fake()->randomElement(['TN', 'FR', 'IT']),
            'currency' => 'TND',
            'commission_rate' => 5.00,
            'total_gmv' => '0.000',
            'total_orders' => 0,
            'total_items_sold' => 0,
            'gmv_current_month' => '0.000',
            'orders_current_month' => 0,
        ];
    }

    public function erpTenant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'seller_type' => SellerType::ErpTenant,
        ]);
    }

    public function external(): static
    {
        return $this->state(fn (array $attributes): array => [
            'seller_type' => SellerType::External,
            'tenant_id' => null,
            'company_id' => null,
        ]);
    }

    public function syneriva(): static
    {
        return $this->state(fn (array $attributes): array => [
            'seller_type' => SellerType::Syneriva,
            'tenant_id' => null,
            'company_id' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'seller_status' => SellerStatus::Suspended,
        ]);
    }
}
