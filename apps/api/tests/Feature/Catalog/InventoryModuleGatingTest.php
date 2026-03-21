<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\Vertical;
use App\Services\CompanyConfigService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryModuleGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_coffee_shop_without_extras_does_not_have_inventory(): void
    {
        $tenant = Tenant::create([
            'name' => 'Coffee Shop',
            'slug' => 'coffee-no-inv',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => [],
        ]);

        $configService = app(CompanyConfigService::class);
        $config = $configService->getConfigForTenant($tenant);

        $this->assertNotContains('Inventory', $config->defaultModules);
        $this->assertContains('Inventory', $config->compatibleExtras);
        $this->assertNotContains('Inventory', $config->allEnabledModules);
    }

    public function test_coffee_shop_with_inventory_extra_has_inventory(): void
    {
        $tenant = Tenant::create([
            'name' => 'Coffee Shop Pro',
            'slug' => 'coffee-with-inv',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);

        $configService = app(CompanyConfigService::class);
        $config = $configService->getConfigForTenant($tenant);

        $this->assertContains('Inventory', $config->allEnabledModules);
    }

    public function test_restaurant_without_extras_does_not_have_inventory(): void
    {
        $tenant = Tenant::create([
            'name' => 'Restaurant',
            'slug' => 'restaurant-no-inv',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Restaurant,
            'enabled_extras' => [],
        ]);

        $configService = app(CompanyConfigService::class);
        $config = $configService->getConfigForTenant($tenant);

        $this->assertNotContains('Inventory', $config->defaultModules);
        $this->assertContains('Inventory', $config->compatibleExtras);
    }
}
