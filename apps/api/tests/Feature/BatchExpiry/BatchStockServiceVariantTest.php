<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Exceptions\MissingVariantException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1.2.16 — BatchStockService::findOrCreateBatch variant-aware.
 *
 * Verifies that:
 *  - variant_id is stored on batch creation when passed.
 *  - the same (company, product, batchNumber) finds the correct row when
 *    variant_id is part of the match key (partial unique indexes from Task 7).
 *  - calling without variantId on a variant-bearing product throws MissingVariantException.
 *  - calling without variantId on a product with NO variants creates a product-level batch.
 */
class BatchStockServiceVariantTest extends TestCase
{
    use RefreshDatabase;

    private BatchStockService $service;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BatchStockService::class);
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    /** Passing a variantId stores variant_id on the created batch. */
    public function test_creates_variant_scoped_batch(): void
    {
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $batch = $this->service->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-001',
            expiryDate: '2027-12-31',
            variantId: $variant->id,
        );

        $this->assertNotNull($batch->id);
        $this->assertSame($variant->id, $batch->variant_id);
        $this->assertSame($this->product->id, $batch->product_id);
        $this->assertSame('LOT-001', $batch->batch_number);
    }

    /** A second call with the same (company, product, batchNumber, variantId) returns the existing batch. */
    public function test_find_returns_existing_variant_scoped_batch(): void
    {
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $first = $this->service->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-FIND',
            expiryDate: '2027-12-31',
            variantId: $variant->id,
        );

        $second = $this->service->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-FIND',
            expiryDate: '2027-12-31',
            variantId: $variant->id,
        );

        $this->assertSame($first->id, $second->id);
    }

    /**
     * A product WITH at least one active variant must not accept a product-level
     * (variant_id = null) batch. Must throw MissingVariantException.
     */
    public function test_rejects_product_level_batch_for_variant_bearing_product(): void
    {
        // Give the product an active variant.
        ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $this->expectException(MissingVariantException::class);
        $this->expectExceptionMessage('variant_id is required');

        $this->service->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-NVAR',
            expiryDate: '2027-12-31',
            // No variantId passed.
        );
    }

    /**
     * A product with NO active variants must create a product-level batch
     * (variant_id = null) without any exception.
     */
    public function test_product_without_variants_creates_product_level_batch(): void
    {
        // No variants — product is non-variant.
        $batch = $this->service->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-PLAIN',
            expiryDate: '2027-12-31',
        );

        $this->assertNotNull($batch->id);
        $this->assertNull($batch->variant_id);
    }

    /**
     * Variant batch and product-level batch can coexist for the same
     * (company, product, batchNumber) — different partial index partitions.
     */
    public function test_variant_and_product_level_batches_coexist(): void
    {
        // First, create the product-level batch (product has no variants yet).
        $productBatch = $this->service->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-SHARED',
            expiryDate: '2027-12-31',
        );

        // Now create a variant (the product becomes variant-bearing after the fact,
        // but the pre-existing product-level batch already exists in the DB).
        // The find-or-create for the variant branch should create a SEPARATE row.
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $variantBatch = $this->service->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-SHARED',
            expiryDate: '2027-12-31',
            variantId: $variant->id,
        );

        $this->assertNotSame($productBatch->id, $variantBatch->id);
        $this->assertNull($productBatch->variant_id);
        $this->assertSame($variant->id, $variantBatch->variant_id);
    }
}
