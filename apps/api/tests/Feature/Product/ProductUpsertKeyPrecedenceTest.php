<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Application\Services\ProductService;
use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductUpsertKeyPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $service;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(ProductService::class);

        $this->tenant = Tenant::create([
            'name' => 'Product Upsert Test Tenant',
            'slug' => 'product-upsert-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Product Upsert Test Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'UTC',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
            'default_tax_rate' => '19.00',
        ]);
    }

    public function test_blank_sku_matches_existing_product_by_barcode_and_keeps_existing_sku(): void
    {
        $existing = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Old Name',
            'sku' => 'SKU-Y',
            'barcode' => '3216549870123',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
        ]);

        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'New Name',
            'sku' => '',
            'barcode' => '3216549870123',
        ]);

        $this->assertSame($existing->id, $id);
        $existing->refresh();
        $this->assertSame('New Name', $existing->name);
        $this->assertSame('SKU-Y', $existing->sku);
        $this->assertSame(1, Product::where('company_id', $this->company->id)->count());
    }

    public function test_blank_sku_with_barcode_creates_product_using_barcode_as_sku(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Barcode Product',
            'sku' => '',
            'barcode' => '1234567890123',
        ]);

        $product = Product::findOrFail($id);
        $this->assertSame('1234567890123', $product->sku);
    }

    public function test_blank_sku_without_barcode_uses_name_slug_and_reimport_is_idempotent(): void
    {
        $firstId = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Crème Solaire 50',
            'sku' => '',
        ]);

        $secondId = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Crème Solaire 50',
            'sku' => '',
        ]);

        $product = Product::findOrFail($firstId);
        $this->assertSame($firstId, $secondId);
        $this->assertSame('CREME-SOLAIRE-50', $product->sku);
        $this->assertSame(1, Product::where('company_id', $this->company->id)->count());
    }

    public function test_missing_type_defaults_to_part(): void
    {
        $id = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Default Type Product',
            'sku' => 'DEF-TYPE',
        ]);

        $product = Product::findOrFail($id);
        $this->assertSame(ProductType::Part, $product->type);
    }

    public function test_brand_name_is_reused_and_linked_to_products(): void
    {
        $firstId = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Nivea Cream',
            'sku' => 'NIVEA-1',
            'brand' => 'Nivea',
        ]);

        $secondId = $this->service->upsert($this->tenant->id, $this->company->id, [
            'name' => 'Nivea Lotion',
            'sku' => 'NIVEA-2',
            'brand' => 'Nivea',
        ]);

        $first = Product::findOrFail($firstId);
        $second = Product::findOrFail($secondId);

        $this->assertSame(1, Brand::where('tenant_id', $this->tenant->id)->count());
        $this->assertNotNull($first->brand_id);
        $this->assertSame($first->brand_id, $second->brand_id);
    }
}
