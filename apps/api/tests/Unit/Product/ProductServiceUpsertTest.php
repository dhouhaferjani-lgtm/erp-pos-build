<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Product\Application\Services\ProductService;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductServiceUpsertTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $service;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProductService();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'product-upsert-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Co',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);
    }

    public function test_upsert_creates_product_with_basic_fields(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Test Product',
            'sku' => 'TST-001',
            'type' => 'part',
            'sale_price' => '10.00',
        ]);

        $product = Product::find($id);
        $this->assertNotNull($product);
        $this->assertEquals('Test Product', $product->name);
        $this->assertEquals('TST-001', $product->sku);
    }

    public function test_upsert_sets_tax_rate(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Taxed Product',
            'sku' => 'TAX-001',
            'type' => 'part',
            'tax_rate' => '19',
        ]);

        $product = Product::find($id);
        $this->assertNotNull($product);
        $this->assertEquals('19', $product->tax_rate);
    }

    public function test_upsert_sets_unit(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Unit Product',
            'sku' => 'UNIT-001',
            'type' => 'part',
            'unit' => 'kg',
        ]);

        $product = Product::find($id);
        $this->assertNotNull($product);
        $this->assertEquals('kg', $product->unit);
    }

    public function test_upsert_sets_is_active_true(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Active Product',
            'sku' => 'ACT-001',
            'type' => 'part',
            'is_active' => 'true',
        ]);

        $product = Product::find($id);
        $this->assertNotNull($product);
        $this->assertTrue($product->is_active);
    }

    public function test_upsert_sets_is_active_false(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Inactive Product',
            'sku' => 'INACT-001',
            'type' => 'part',
            'is_active' => 'false',
        ]);

        $product = Product::find($id);
        $this->assertNotNull($product);
        $this->assertFalse($product->is_active);
    }

    public function test_upsert_resolves_category_by_name(): void
    {
        $category = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Beverages',
        ]);

        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Coffee',
            'sku' => 'CAT-001',
            'type' => 'part',
            'category_name' => 'Beverages',
        ]);

        $product = Product::find($id);
        $this->assertNotNull($product);
        $this->assertEquals($category->id, $product->category_id);
    }

    public function test_upsert_ignores_nonexistent_category_name(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Orphan Product',
            'sku' => 'NOCAT-001',
            'type' => 'part',
            'category_name' => 'NonExistentCategory',
        ]);

        $product = Product::find($id);
        $this->assertNotNull($product);
        $this->assertNull($product->category_id);
    }

    public function test_upsert_converts_empty_strings_to_null(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Empty Fields',
            'sku' => 'EMPTY-001',
            'type' => 'part',
            'tax_rate' => '',
            'unit' => '',
            'barcode' => '',
        ]);

        $product = Product::find($id);
        $this->assertNotNull($product);
        $this->assertNull($product->tax_rate);
        $this->assertNull($product->unit);
        $this->assertNull($product->barcode);
    }
}
