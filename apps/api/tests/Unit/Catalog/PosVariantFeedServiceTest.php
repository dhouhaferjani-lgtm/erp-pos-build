<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\PosVariantFeedReader;
use App\Shared\DTOs\PosVariantData;
use App\Shared\DTOs\PosVariantFeedPageDTO;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for PosVariantFeedService (BV1).
 *
 * Snapshot (no cursor) returns active, company-scoped variants only.
 * Delta (cursor) returns changed-active variants in ->variants and
 * tombstones (deactivated + soft-deleted) in ->deletedIds.
 */
final class PosVariantFeedServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function reader(): PosVariantFeedReader
    {
        return app(PosVariantFeedReader::class);
    }

    /**
     * Force a row's updated_at to a fixed value, bypassing model touch.
     */
    private function setUpdatedAt(ProductVariant $variant, string $timestamp): void
    {
        DB::table('product_variants')->where('id', $variant->id)->update(['updated_at' => $timestamp]);
    }

    public function test_snapshot_returns_active_variants_only_company_scoped(): void
    {
        // Active variant — this company.
        $active = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        // Inactive variant — this company (must be excluded).
        ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => false,
        ]);

        // Active variant — a DIFFERENT company (must be excluded).
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);
        ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'product_id' => $otherProduct->id,
            'is_active' => true,
        ]);

        $page = $this->reader()->read(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            updatedSince: null,
            page: 1,
            perPage: 50,
        );

        $this->assertInstanceOf(PosVariantFeedPageDTO::class, $page);
        $this->assertCount(1, $page->variants);
        $this->assertInstanceOf(PosVariantData::class, $page->variants[0]);
        $this->assertSame($active->id, $page->variants[0]->id);
        $this->assertSame($this->product->id, $page->variants[0]->productId);
        $this->assertSame([], $page->deletedIds);
        $this->assertSame(1, $page->total);
    }

    public function test_delta_returns_changed_and_tombstones_deactivated_and_softdeleted(): void
    {
        $cursor = CarbonImmutable::parse('2026-06-01T12:00:00+00:00');

        // A — active, updated AFTER the cursor → appears in ->variants.
        $a = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);
        $this->setUpdatedAt($a, '2026-06-02 00:00:00');

        // B — deactivated AFTER the cursor → tombstone in ->deletedIds.
        $b = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => false,
        ]);
        $this->setUpdatedAt($b, '2026-06-02 00:00:00');

        // C — soft-deleted AFTER the cursor → tombstone in ->deletedIds.
        $c = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);
        // delete() bumps both deleted_at and updated_at; pin both AFTER the cursor.
        $c->delete();
        DB::table('product_variants')->where('id', $c->id)->update([
            'updated_at' => '2026-06-02 00:00:00',
            'deleted_at' => '2026-06-02 00:00:00',
        ]);

        // D — active, unchanged BEFORE the cursor → must NOT appear.
        $d = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);
        $this->setUpdatedAt($d, '2026-05-01 00:00:00');

        $page = $this->reader()->read(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            updatedSince: $cursor,
            page: 1,
            perPage: 50,
        );

        $variantIds = array_map(static fn (PosVariantData $v): string => $v->id, $page->variants);
        $this->assertContains($a->id, $variantIds);
        $this->assertNotContains($d->id, $variantIds);

        $this->assertContains($b->id, $page->deletedIds);
        $this->assertContains($c->id, $page->deletedIds);
        $this->assertNotContains($a->id, $page->deletedIds);
    }

    public function test_reactivated_variant_appears_in_variants_not_deleted(): void
    {
        $cursor = CarbonImmutable::parse('2026-06-01T12:00:00+00:00');

        // Deactivated BEFORE the cursor, then reactivated AFTER the cursor.
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => false,
        ]);
        $this->setUpdatedAt($variant, '2026-05-01 00:00:00');

        // Reactivate after the cursor.
        $variant->update(['is_active' => true]);
        $this->setUpdatedAt($variant, '2026-06-02 00:00:00');

        $page = $this->reader()->read(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            updatedSince: $cursor,
            page: 1,
            perPage: 50,
        );

        $variantIds = array_map(static fn (PosVariantData $v): string => $v->id, $page->variants);
        $this->assertContains($variant->id, $variantIds);
        $this->assertNotContains($variant->id, $page->deletedIds);
    }
}
