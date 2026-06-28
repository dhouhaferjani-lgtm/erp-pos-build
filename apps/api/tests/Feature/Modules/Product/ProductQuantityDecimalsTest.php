<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Application\DTOs\ProductData;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the per-unit quantity precision contract: ProductData exposes
 * quantity_decimals derived from the product's unit decimal_places when the
 * relation is loaded, and falls back to 4 (canonical storage scale) otherwise.
 *
 * This is the keystone of the "qty steps by its unit" feature — pieces (dp 0)
 * must yield 0 so the UI steps by 1; kg (dp 3) yields 3 for fractional steps.
 */
final class ProductQuantityDecimalsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Qty Decimals Tenant',
            'slug' => 'qty-decimals-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
            'enabled_extras' => [],
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Qty Decimals Company',
            'legal_name' => 'Qty Decimals Company LLC',
            'tax_id' => 'TAX-QD-001',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeUnit(string $code, int $decimalPlaces): Unit
    {
        $category = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'cat-'.$code,
            'name' => 'Category '.$code,
            'is_system' => true,
            'is_active' => true,
        ]);

        return Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => $code,
            'name' => $code,
            'symbol' => $code,
            'decimal_places' => $decimalPlaces,
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    private function makeProductOnUnit(Unit $unit): Product
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $unit->id,
        ]);

        return $product->load('unitOfMeasure');
    }

    public function test_pieces_unit_yields_zero_quantity_decimals(): void
    {
        $product = $this->makeProductOnUnit($this->makeUnit('pc', 0));

        $this->assertSame(0, ProductData::fromModel($product)->quantity_decimals);
    }

    public function test_kilogram_unit_yields_three_quantity_decimals(): void
    {
        $product = $this->makeProductOnUnit($this->makeUnit('kg', 3));

        $this->assertSame(3, ProductData::fromModel($product)->quantity_decimals);
    }

    public function test_unloaded_unit_relation_falls_back_to_four(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $this->makeUnit('pc2', 0)->id,
        ]);
        // Deliberately do NOT load unitOfMeasure → fallback path.
        $this->assertFalse($product->relationLoaded('unitOfMeasure'));

        $this->assertSame(4, ProductData::fromModel($product)->quantity_decimals);
    }
}
