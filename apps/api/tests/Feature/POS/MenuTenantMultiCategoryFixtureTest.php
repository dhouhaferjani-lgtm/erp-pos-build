<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Enums\Vertical;
use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Menu\Domain\Entities\MenuCategory;
use App\Modules\Menu\Domain\Entities\MenuCategoryItem;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\MenuTenantMultiCategoryFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MenuTenantMultiCategoryFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_creates_menu_tenant_with_same_product_in_two_categories_at_distinct_prices(): void
    {
        $this->seed(MenuTenantMultiCategoryFixture::class);

        $tenant = Tenant::query()
            ->where('slug', 'pos-menu-multi-category-fixture')
            ->firstOrFail();

        $this->assertSame(Vertical::CoffeeShop, $tenant->vertical);
        $this->assertContains('Menu', $tenant->vertical->defaultModules());

        $menu = Menu::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Multi-category POS fixture')
            ->firstOrFail();

        $this->assertTrue($menu->is_default);
        $this->assertTrue($menu->is_active);

        $categories = MenuCategory::query()
            ->where('menu_id', $menu->id)
            ->orderBy('display_order')
            ->pluck('name')
            ->all();

        $this->assertSame(['Drinks', 'Lunch combos'], $categories);

        $coca = Product::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Coca')
            ->firstOrFail();

        $categoryItems = MenuCategoryItem::query()
            ->where('product_id', $coca->id)
            ->orderBy('override_price')
            ->get();

        $this->assertCount(2, $categoryItems);
        $this->assertEquals(['1.5000', '3.0000'], $categoryItems->pluck('override_price')->all());
        $this->assertTrue($categoryItems->every(
            fn (MenuCategoryItem $item): bool => $item->is_available && $item->composite_item_id === null
        ));

        $this->assertDatabaseMissing('tenants', ['slug' => 'demo-tenant']);
        $this->assertDatabaseMissing('tenants', ['slug' => 'coffee-shop']);
        $this->assertDatabaseMissing('tenants', ['slug' => 'parapharmacy']);
    }
}
