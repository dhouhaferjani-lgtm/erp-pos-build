<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEntityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_product_has_uuid_primary_key(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TST-001',
        ]);

        $this->assertIsString($product->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $product->id
        );
    }

    public function test_product_belongs_to_tenant(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TST-001',
        ]);

        $this->assertEquals($this->tenant->id, $product->tenant_id);
        $this->assertEquals($this->tenant->id, $product->tenant->id);
    }

    public function test_product_type_is_nullable(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TST-001',
        ]);

        $this->assertNull($product->type);
    }

    public function test_product_type_can_be_set_to_enum_value(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TST-001',
            'type' => ProductType::Part,
        ]);

        $this->assertInstanceOf(ProductType::class, $product->type);
        $this->assertEquals(ProductType::Part, $product->type);
    }

    public function test_product_defaults_to_physical(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Default Product',
            'sku' => 'DEF-001',
        ]);

        $this->assertTrue($product->is_physical);
        $this->assertTrue($product->isPhysical());
        $this->assertTrue($product->isStockTracked());
    }

    public function test_product_can_be_non_physical(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Digital Product',
            'sku' => 'DIG-001',
            'is_physical' => false,
        ]);

        $this->assertFalse($product->is_physical);
        $this->assertFalse($product->isPhysical());
        $this->assertFalse($product->isStockTracked());
    }

    public function test_product_has_fillable_fields(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Full Product',
            'sku' => 'FUL-001',
            'description' => 'A complete product description',
            'sale_price' => '99.99',
            'purchase_price' => '50.00',
            'tax_rate' => '20.00',
            'unit' => 'piece',
            'barcode' => '1234567890123',
            'is_active' => true,
            'is_physical' => true,
        ]);

        $this->assertEquals('Full Product', $product->name);
        $this->assertEquals('FUL-001', $product->sku);
        $this->assertEquals('A complete product description', $product->description);
        $this->assertEquals('99.99', $product->sale_price);
        $this->assertEquals('50.00', $product->purchase_price);
        $this->assertEquals('20.00', $product->tax_rate);
        $this->assertEquals('piece', $product->unit);
        $this->assertEquals('1234567890123', $product->barcode);
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->is_physical);
    }

    public function test_product_uses_soft_deletes(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Delete Me',
            'sku' => 'DEL-001',
        ]);

        $productId = $product->id;
        $product->delete();

        $this->assertNull(Product::find($productId));
        $this->assertNotNull(Product::withTrashed()->find($productId));
    }

    public function test_product_has_oem_numbers_as_array(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'OEM Product',
            'sku' => 'OEM-001',
            'oem_numbers' => ['OEM123', 'OEM456', 'OEM789'],
        ]);

        $this->assertIsArray($product->oem_numbers);
        $this->assertCount(3, $product->oem_numbers);
        $this->assertContains('OEM123', $product->oem_numbers);
    }

    public function test_product_has_cross_references_as_array(): void
    {
        $crossRefs = [
            ['brand' => 'Bosch', 'reference' => 'F026407022'],
            ['brand' => 'Mann', 'reference' => 'W712/80'],
        ];

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cross Ref Product',
            'sku' => 'CRF-001',
            'cross_references' => $crossRefs,
        ]);

        $this->assertIsArray($product->cross_references);
        $this->assertCount(2, $product->cross_references);
        $this->assertEquals('Bosch', $product->cross_references[0]['brand']);
    }

    public function test_product_default_active_state(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Default Active Product',
            'sku' => 'DEF-001',
        ]);

        $this->assertTrue($product->is_active);
    }

    public function test_product_scope_active(): void
    {
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Active Product',
            'sku' => 'ACT-001',
            'is_active' => true,
        ]);

        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Inactive Product',
            'sku' => 'INA-001',
            'is_active' => false,
        ]);

        $activeProducts = Product::active()->get();

        $this->assertCount(1, $activeProducts);
        $this->assertEquals('Active Product', $activeProducts->first()->name);
    }

    public function test_is_physical_controls_stock_tracking(): void
    {
        $physicalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Physical Product',
            'sku' => 'PHY-001',
            'is_physical' => true,
        ]);

        $nonPhysicalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Non-Physical Product',
            'sku' => 'NPH-001',
            'is_physical' => false,
        ]);

        $this->assertTrue($physicalProduct->isStockTracked());
        $this->assertFalse($nonPhysicalProduct->isStockTracked());
    }

    public function test_sellable_contract_implementation(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Sellable Product',
            'sku' => 'SLL-001',
            'sale_price' => '29.99',
            'unit' => 'piece',
            'is_physical' => true,
        ]);

        $this->assertEquals($product->id, $product->getSellableId());
        $this->assertEquals('product', $product->getSellableType());
        $this->assertEquals('Sellable Product', $product->getSellableName());
        $this->assertEquals('29.99', $product->getSellableBasePrice());
        $this->assertEquals('piece', $product->getSellableUnit());
        $this->assertTrue($product->isAvailable());
    }
}
