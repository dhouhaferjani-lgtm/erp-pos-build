<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\DTOs\ProductVariantSummary;
use Database\Factories\Catalog\ProductVariantFactory;
use Database\Factories\CompanyFactory;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariantLookupTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariantLookup $lookup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lookup = app(ProductVariantLookup::class);
    }

    public function test_lookup_finds_variant_by_id(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $variant = ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'tenant_id' => $tenantId,
            'sku' => 'TEST-001',
            'variant_code' => 'VAR-TEST-001',
        ]);

        $summary = $this->lookup->findById($variant->id);

        $this->assertInstanceOf(ProductVariantSummary::class, $summary);
        $this->assertSame('TEST-001', $summary->sku);
        $this->assertSame($variant->id, $summary->id);
    }

    public function test_lookup_finds_by_sku_per_company(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $variant = ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'tenant_id' => $tenantId,
            'sku' => 'COMP-1',
            'variant_code' => 'VAR-COMP-1',
        ]);

        $summary = $this->lookup->findBySku('COMP-1', (string) $company->id);

        $this->assertInstanceOf(ProductVariantSummary::class, $summary);
        $this->assertSame($variant->id, $summary->id);
    }

    public function test_lookup_finds_by_barcode_per_company(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $variant = ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'tenant_id' => $tenantId,
            'sku' => 'BC-SKU-001',
            'barcode' => '9782070360024',
            'variant_code' => 'VAR-BC-001',
        ]);

        $summary = $this->lookup->findByBarcode('9782070360024', (string) $company->id);

        $this->assertInstanceOf(ProductVariantSummary::class, $summary);
        $this->assertSame($variant->id, $summary->id);
    }

    public function test_lookup_lists_active_variants_for_product(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        // 2 active variants
        ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'tenant_id' => $tenantId,
            'sku' => 'ACTIVE-001',
            'variant_code' => 'VAR-A001',
            'is_active' => true,
            'display_order' => 1,
        ]);

        ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'tenant_id' => $tenantId,
            'sku' => 'ACTIVE-002',
            'variant_code' => 'VAR-A002',
            'is_active' => true,
            'display_order' => 2,
        ]);

        // 1 inactive variant
        ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'tenant_id' => $tenantId,
            'sku' => 'INACTIVE-001',
            'variant_code' => 'VAR-I001',
            'is_active' => false,
            'display_order' => 3,
        ]);

        $activeOnly = $this->lookup->listForProduct((string) $product->id, onlyActive: true);
        $this->assertCount(2, $activeOnly);
        $activeOnly->each(fn (ProductVariantSummary $s) => $this->assertInstanceOf(ProductVariantSummary::class, $s));

        $all = $this->lookup->listForProduct((string) $product->id, onlyActive: false);
        $this->assertCount(3, $all);
    }

    public function test_find_by_id_returns_null_when_missing(): void
    {
        $result = $this->lookup->findById((string) Str::uuid());

        $this->assertNull($result);
    }
}
