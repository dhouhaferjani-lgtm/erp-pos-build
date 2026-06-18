<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\DTOs\VariantLabelData;
use App\Modules\Catalog\Application\Services\VariantLabelService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task B2 — VariantLabelService::prepare.
 *
 * Turns {variantId, quantity} items into ready-to-print VariantLabelData,
 * lazily assigning a barcode (= the variant sku) when none is set, and skipping
 * items that cannot be resolved or whose sku would collide.
 *
 * Real Postgres (barcode assign goes through updateVariant → saveBarcodeSafe).
 */
class PrepareLabelsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    private function service(): VariantLabelService
    {
        return app(VariantLabelService::class);
    }

    public function test_assigns_sku_as_barcode_and_resolves_price(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-TS',
            'sale_price' => '12.500',
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'TS-S-BLK',
            'barcode' => null,
        ]);

        $result = $this->service()->prepare($this->company, [
            ['variantId' => $variant->id, 'quantity' => 3],
        ]);

        $this->assertCount(1, $result['ready']);
        $this->assertCount(0, $result['skipped']);

        /** @var VariantLabelData $label */
        $label = $result['ready'][0];
        $this->assertInstanceOf(VariantLabelData::class, $label);
        $this->assertSame('TS-S-BLK', $label->barcode_value);
        $this->assertSame(3, $label->quantity);
        $this->assertSame('12.500', $label->effective_price);

        $this->assertSame('TS-S-BLK', ProductVariant::find($variant->id)->barcode);
    }

    public function test_skips_when_sku_collides_with_existing_product_and_does_not_persist(): void
    {
        // A product whose sku equals the variant's sku → barcode assign must be refused.
        ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'CLASH-SKU',
        ]);

        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-OWNER',
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'CLASH-SKU',
            'barcode' => null,
        ]);

        $result = $this->service()->prepare($this->company, [
            ['variantId' => $variant->id, 'quantity' => 1],
        ]);

        $this->assertCount(0, $result['ready']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame($variant->id, $result['skipped'][0]['variant_id']);
        $this->assertSame('barcode_conflict', $result['skipped'][0]['reason']);

        $this->assertNull(ProductVariant::find($variant->id)->barcode);
    }

    public function test_existing_barcode_is_untouched_and_included(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-EXIST',
            'sale_price' => '5.000',
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'V-EXIST',
            'barcode' => '6191234567890',
        ]);

        $result = $this->service()->prepare($this->company, [
            ['variantId' => $variant->id, 'quantity' => 2],
        ]);

        $this->assertCount(1, $result['ready']);
        $this->assertCount(0, $result['skipped']);
        $this->assertSame('6191234567890', $result['ready'][0]->barcode_value);
        $this->assertSame('6191234567890', ProductVariant::find($variant->id)->barcode);
    }

    public function test_existing_barcode_colliding_with_product_code_is_skipped_barcode_conflict(): void
    {
        // A product whose barcode is later (illegally) also held by a variant.
        ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-CODE',
            'barcode' => 'PROD-COLLIDE',
        ]);

        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-OWNER2',
            'sale_price' => '5.000',
        ]);

        // Variant already OWNS a barcode that equals the other product's barcode.
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'V-OWNS',
            'barcode' => 'PROD-COLLIDE',
        ]);

        $result = $this->service()->prepare($this->company, [
            ['variantId' => $variant->id, 'quantity' => 1],
        ]);

        $this->assertCount(0, $result['ready']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame($variant->id, $result['skipped'][0]['variant_id']);
        $this->assertSame('barcode_conflict', $result['skipped'][0]['reason']);
    }

    public function test_clean_existing_barcode_not_colliding_with_product_code_is_ready(): void
    {
        ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-CODE2',
            'barcode' => 'PROD-OTHER',
        ]);

        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-OWNER3',
            'sale_price' => '7.000',
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'V-CLEAN',
            'barcode' => 'VARIANT-CLEAN',
        ]);

        $result = $this->service()->prepare($this->company, [
            ['variantId' => $variant->id, 'quantity' => 1],
        ]);

        $this->assertCount(1, $result['ready']);
        $this->assertCount(0, $result['skipped']);
        $this->assertSame('VARIANT-CLEAN', $result['ready'][0]->barcode_value);
    }

    public function test_unencodable_non_ascii_barcode_value_is_skipped(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-ACCENT',
            'sale_price' => '4.000',
        ]);

        // Empty barcode + non-ASCII sku: valueIsUsable may pass, but canEncode fails.
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'CAFÉ-1',
            'barcode' => null,
        ]);

        $result = $this->service()->prepare($this->company, [
            ['variantId' => $variant->id, 'quantity' => 1],
        ]);

        $this->assertCount(0, $result['ready']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame($variant->id, $result['skipped'][0]['variant_id']);
        $this->assertSame('unencodable_barcode', $result['skipped'][0]['reason']);
    }

    public function test_variant_with_soft_deleted_parent_product_is_skipped_product_unavailable(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'P-GONE',
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'V-ORPHAN',
            'barcode' => '6199999999999',
        ]);

        // Soft-delete the parent product but keep the variant row.
        $product->delete();
        $this->assertSoftDeleted($product);

        $result = $this->service()->prepare($this->company, [
            ['variantId' => $variant->id, 'quantity' => 1],
        ]);

        // No 500 — the orphaned variant degrades to a skipped entry.
        $this->assertCount(0, $result['ready']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame($variant->id, $result['skipped'][0]['variant_id']);
        $this->assertSame('product_unavailable', $result['skipped'][0]['reason']);
    }

    public function test_missing_or_cross_tenant_variant_is_skipped_not_found(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->for($otherTenant)->create();
        $foreignProduct = ProductFactory::new()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
        ]);
        $foreignVariant = ProductVariant::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'product_id' => $foreignProduct->id,
            'barcode' => null,
        ]);

        $result = $this->service()->prepare($this->company, [
            ['variantId' => $foreignVariant->id, 'quantity' => 1],
        ]);

        $this->assertCount(0, $result['ready']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame($foreignVariant->id, $result['skipped'][0]['variant_id']);
        $this->assertSame('not_found', $result['skipped'][0]['reason']);
    }
}
