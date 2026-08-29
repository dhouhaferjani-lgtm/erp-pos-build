<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Commands\CreateVariantCommand;
use App\Modules\Catalog\Application\Services\ProductVariantService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\VariantIndexNames;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VariantIndexScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private Product $productA;

    private Product $productB;

    private ProductVariantService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL partial unique indexes and named 23505 constraints.');
        }

        $this->tenant = Tenant::factory()->create();
        $this->companyA = Company::factory()->for($this->tenant)->create();
        $this->companyB = Company::factory()->for($this->tenant)->create();
        $this->productA = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'sku' => 'PRODUCT-A',
        ]);
        $this->productB = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'sku' => 'PRODUCT-B',
        ]);
        $this->service = $this->app->make(ProductVariantService::class);
    }

    public function test_same_variant_sku_is_allowed_in_sibling_companies(): void
    {
        $this->makeVariant($this->productA, 'SHARED-VARIANT-SKU', null, 'A');
        $this->makeVariant($this->productB, 'SHARED-VARIANT-SKU', null, 'B');

        $this->assertSame(2, ProductVariant::where('sku', 'SHARED-VARIANT-SKU')->count());
    }

    public function test_same_variant_barcode_is_refused_in_sibling_companies(): void
    {
        $this->makeVariant($this->productA, 'BARCODE-SKU-A', 'EAN-SHARED', 'A');

        try {
            $this->makeVariant($this->productB, 'BARCODE-SKU-B', 'EAN-SHARED', 'B');
            $this->fail('Variant barcode must remain tenant-wide across sibling companies.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(VariantIndexNames::TENANT_BARCODE_UNIQUE, $exception->getMessage());
        }
    }

    public function test_create_barcode_collision_maps_to_422(): void
    {
        $this->makeVariant($this->productA, 'CREATE-BC-A', 'CREATE-BARCODE', 'A');

        try {
            $this->service->createVariant($this->command($this->productA, 'CREATE-BC-B', 'B', 'CREATE-BARCODE'));
            $this->fail('A create-time barcode race must map to validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('barcode', $exception->errors());
            $this->assertSame(422, $exception->status);
        }
    }

    public function test_create_company_sku_collision_maps_to_422(): void
    {
        $this->makeVariant($this->productA, 'CREATE-SKU-COLLISION', null, 'A');

        try {
            $this->service->createVariant($this->command($this->productA, 'CREATE-SKU-COLLISION', 'B'));
            $this->fail('A create-time company SKU race must map to validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
            $this->assertSame(422, $exception->status);
        }
    }

    public function test_restore_barcode_collision_maps_to_422(): void
    {
        [$product, $axes, $deleted] = $this->seedDeletedMatrixVariant('RESTORE-BARCODE');
        $this->makeVariant($this->otherProductInCompanyA(), 'RESTORE-BC-HOLDER', 'RESTORE-BARCODE', 'BC-HOLDER');

        try {
            $this->service->generateMatrix($product->id, $axes);
            $this->fail('A restore-time barcode race must map to validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('barcode', $exception->errors());
            $this->assertSame(422, $exception->status);
        }

        $this->assertTrue($deleted->fresh()?->trashed() ?? false);
    }

    public function test_restore_company_sku_collision_maps_to_422(): void
    {
        [$product, $axes, $deleted] = $this->seedDeletedMatrixVariant(null);
        $this->makeVariant($this->otherProductInCompanyA(), $deleted->sku, null, 'SKU-HOLDER');

        try {
            $this->service->generateMatrix($product->id, $axes);
            $this->fail('A restore-time company SKU race must map to validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
            $this->assertSame(422, $exception->status);
        }

        $this->assertTrue($deleted->fresh()?->trashed() ?? false);
    }

    private function command(Product $product, string $sku, string $variantCode, ?string $barcode = null): CreateVariantCommand
    {
        return new CreateVariantCommand(
            tenantId: $product->tenant_id,
            companyId: $product->company_id,
            productId: $product->id,
            variantCode: $variantCode,
            sku: $sku,
            nameSuffix: $variantCode,
            barcode: $barcode,
        );
    }

    private function makeVariant(
        Product $product,
        string $sku,
        ?string $barcode,
        string $variantCode,
    ): ProductVariant {
        return ProductVariant::factory()->create([
            'tenant_id' => $product->tenant_id,
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'variant_code' => $variantCode,
            'sku' => $sku,
            'barcode' => $barcode,
        ]);
    }

    private function otherProductInCompanyA(): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
        ]);
    }

    /**
     * @return array{Product, array<int, array{attributeId: string, valueIds: list<string>}>, ProductVariant}
     */
    private function seedDeletedMatrixVariant(?string $barcode): array
    {
        $product = $this->otherProductInCompanyA();
        $attribute = ProductAttributeFactory::new()->createOne([
            'tenant_id' => $this->tenant->id,
            'code' => 'size',
        ]);
        $value = ProductAttributeValueFactory::new()->createOne([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $attribute->id,
            'code' => 'large',
            'label' => 'Large',
        ]);
        $axes = [[
            'attributeId' => $attribute->id,
            'valueIds' => [$value->id],
        ]];

        $first = $this->service->generateMatrix($product->id, $axes);
        /** @var ProductVariant $deleted */
        $deleted = $first['created']->firstOrFail();
        $deleted->barcode = $barcode;
        $deleted->save();
        $deleted->delete();

        return [$product, $axes, $deleted];
    }
}
