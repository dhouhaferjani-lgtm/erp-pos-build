<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Repositories\ProductVariantRepository;
use Database\Factories\Catalog\ProductVariantFactory;
use Database\Factories\CompanyFactory;
use Database\Factories\ProductFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariantRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariantRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(ProductVariantRepository::class);
    }

    public function test_only_one_default_variant_per_product(): void
    {
        $this->expectException(QueryException::class);

        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $product->company_id,
            'tenant_id' => $tenantId,
            'is_default' => true,
            'variant_code' => 'VAR-001',
            'sku' => 'SKU-A-001',
        ]);

        // Second default for same product — must violate the partial unique index
        ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $product->company_id,
            'tenant_id' => $tenantId,
            'is_default' => true,
            'variant_code' => 'VAR-002',
            'sku' => 'SKU-A-002',
        ]);
    }

    public function test_sku_unique_per_tenant(): void
    {
        $this->expectException(QueryException::class);

        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product1 = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);
        $product2 = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        ProductVariantFactory::new()->create([
            'product_id' => $product1->id,
            'company_id' => $product1->company_id,
            'tenant_id' => $tenantId,
            'sku' => 'DUP-SKU-001',
            'variant_code' => 'VAR-001',
        ]);

        // Same tenant, same sku, different product — violates partial unique on (tenant_id, sku)
        ProductVariantFactory::new()->create([
            'product_id' => $product2->id,
            'company_id' => $product2->company_id,
            'tenant_id' => $tenantId,
            'sku' => 'DUP-SKU-001',
            'variant_code' => 'VAR-001',
        ]);
    }

    public function test_barcode_unique_when_present(): void
    {
        $this->expectException(QueryException::class);

        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product1 = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);
        $product2 = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        ProductVariantFactory::new()->create([
            'product_id' => $product1->id,
            'company_id' => $product1->company_id,
            'tenant_id' => $tenantId,
            'sku' => 'SKU-BC-001',
            'barcode' => '9782070360024',
            'variant_code' => 'VAR-001',
        ]);

        ProductVariantFactory::new()->create([
            'product_id' => $product2->id,
            'company_id' => $product2->company_id,
            'tenant_id' => $tenantId,
            'sku' => 'SKU-BC-002',
            'barcode' => '9782070360024',
            'variant_code' => 'VAR-001',
        ]);
    }

    public function test_null_barcode_allowed_multiple(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);

        for ($i = 1; $i <= 3; $i++) {
            $product = ProductFactory::new()->create([
                'tenant_id' => $tenantId,
                'company_id' => $company->id,
            ]);

            ProductVariantFactory::new()->create([
                'product_id' => $product->id,
                'company_id' => $company->id,
                'tenant_id' => $tenantId,
                'sku' => "SKU-NULL-BC-{$i}",
                'barcode' => null,
                'variant_code' => 'VAR-001',
            ]);
        }

        $this->assertSame(3, ProductVariant::count());
    }

    public function test_sku_reusable_after_soft_delete(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product1 = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);
        $product2 = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $first = ProductVariantFactory::new()->create([
            'product_id' => $product1->id,
            'company_id' => $product1->company_id,
            'tenant_id' => $tenantId,
            'sku' => 'ABC-001',
            'variant_code' => 'VAR-001',
        ]);

        $this->repository->softDelete($first->id);

        // After soft-delete the partial unique excludes the deleted row; reuse must succeed
        $second = ProductVariantFactory::new()->create([
            'product_id' => $product2->id,
            'company_id' => $product2->company_id,
            'tenant_id' => $tenantId,
            'sku' => 'ABC-001',
            'variant_code' => 'VAR-001',
        ]);

        $this->assertNotNull($second->id);
        $this->assertSame('ABC-001', $second->sku);
    }

    public function test_default_reusable_after_soft_delete_of_default(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $first = ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $product->company_id,
            'tenant_id' => $tenantId,
            'is_default' => true,
            'variant_code' => 'VAR-001',
            'sku' => 'SKU-DEF-001',
        ]);

        $this->repository->softDelete($first->id);

        // The partial unique on (product_id) WHERE is_default IS TRUE is now clear
        $second = ProductVariantFactory::new()->create([
            'product_id' => $product->id,
            'company_id' => $product->company_id,
            'tenant_id' => $tenantId,
            'is_default' => true,
            'variant_code' => 'VAR-002',
            'sku' => 'SKU-DEF-002',
        ]);

        $this->assertNotNull($second->id);
        $this->assertTrue($second->is_default);
    }
}
