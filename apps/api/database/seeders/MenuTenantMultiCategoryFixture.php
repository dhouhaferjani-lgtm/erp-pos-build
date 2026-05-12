<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Menu\Domain\Entities\MenuCategory;
use App\Modules\Menu\Domain\Entities\MenuCategoryItem;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Seeder;

final class MenuTenantMultiCategoryFixture extends Seeder
{
    public function run(): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => 'pos-menu-multi-category-fixture'],
            [
                'name' => 'POS Menu Multi-category Fixture',
                'vertical' => Vertical::CoffeeShop,
                'enabled_extras' => [],
                'status' => TenantStatus::Active,
                'plan' => SubscriptionPlan::Professional,
                'country_code' => 'TN',
                'currency_code' => 'TND',
                'timezone' => 'Africa/Tunis',
                'date_format' => 'd/m/Y',
                'locale' => 'fr_TN',
                'settings' => [
                    'fixture' => 'pos-menu-multi-category',
                ],
            ]
        );

        /** @var Company $company */
        $company = Company::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'code' => 'POSMC',
            ],
            [
                'name' => 'POS Menu Multi-category Company',
                'legal_name' => 'POS Menu Multi-category Company SARL',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr_TN',
                'timezone' => 'Africa/Tunis',
                'date_format' => 'd/m/Y',
                'is_headquarters' => true,
                'status' => 'active',
                'fiscal_chain_seed' => hash('sha256', 'pos-menu-multi-category-fixture'),
            ]
        );

        /** @var Product $coca */
        $coca = Product::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'sku' => 'POS-COCA-MULTI-CAT',
            ],
            [
                'name' => 'Coca',
                'description' => 'Cross-listed POS fixture product',
                'is_physical' => true,
                'unit' => 'piece',
                'cost_price' => '0.8000',
                'sale_price' => '3.0000',
                'purchase_price' => '0.8000',
                'tax_rate' => '19.00',
                'is_active' => true,
                'barcode' => '6190000000012',
            ]
        );

        /** @var Menu $menu */
        $menu = Menu::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'name' => 'Multi-category POS fixture',
            ],
            [
                'description' => 'Fixture used to validate POS menu category cross-listing.',
                'is_default' => true,
                'is_active' => true,
                'display_order' => 0,
            ]
        );

        $drinks = $this->category($menu, 'Drinks', 'cup-soda', 0);
        $lunchCombos = $this->category($menu, 'Lunch combos', 'utensils', 1);

        $this->attachProduct($drinks, $coca, '1.5000', 0);
        $this->attachProduct($lunchCombos, $coca, '3.0000', 0);
    }

    private function category(Menu $menu, string $name, string $icon, int $displayOrder): MenuCategory
    {
        /** @var MenuCategory $category */
        $category = MenuCategory::query()->updateOrCreate(
            [
                'menu_id' => $menu->id,
                'name' => $name,
            ],
            [
                'icon' => $icon,
                'display_order' => $displayOrder,
                'is_active' => true,
            ]
        );

        return $category;
    }

    private function attachProduct(MenuCategory $category, Product $product, string $overridePrice, int $displayOrder): void
    {
        MenuCategoryItem::query()->updateOrCreate(
            [
                'menu_category_id' => $category->id,
                'product_id' => $product->id,
            ],
            [
                'composite_item_id' => null,
                'override_price' => $overridePrice,
                'display_order' => $displayOrder,
                'is_available' => true,
            ]
        );
    }
}
