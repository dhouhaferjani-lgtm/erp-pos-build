<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Services\ProductVariantService;
use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\Exceptions\MatrixGenerationLimitException;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Database\Factories\CompanyFactory;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task A3 — value-subset, junction-keyed idempotent matrix with restore-on-regenerate.
 *
 * These tests REQUIRE real PostgreSQL: the partial-unique indexes and the
 * hard UNIQUE(product_id, variant_code) constraint that drive the restore /
 * conflict behaviour are not honoured by SQLite. Run with phpunit-pgsql.xml.
 */
class ProductVariantMatrixGenerationTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariantService $service;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (\DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL: relies on partial unique indexes / SQLSTATE 23505 conflict mapping not reproduced by SQLite.');
        }

        $this->service = app(ProductVariantService::class);
    }

    /**
     * Seed a product + a "size" attribute (36/37/38) and "color" attribute
     * (noir/blanc). Returns everything keyed for axis construction.
     *
     * @return array{product: Product, size: ProductAttribute, color: ProductAttribute, sizeValues: array<string, ProductAttributeValue>, colorValues: array<string, ProductAttributeValue>}
     */
    private function seedFixture(): array
    {
        $this->tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $this->tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $company->id,
            'sku' => 'SHOE-001',
        ]);

        $size = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenantId,
            'code' => 'taille',
        ]);
        $sizeValues = [];
        foreach ([['36', 'Taille 36'], ['37', 'Taille 37'], ['38', 'Taille 38']] as [$code, $label]) {
            $sizeValues[$code] = ProductAttributeValueFactory::new()->create([
                'tenant_id' => $this->tenantId,
                'attribute_id' => $size->id,
                'code' => $code,
                'label' => $label,
            ]);
        }

        $color = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenantId,
            'code' => 'couleur',
        ]);
        $colorValues = [];
        foreach ([['noir', 'Noir'], ['blanc', 'Blanc']] as [$code, $label]) {
            $colorValues[$code] = ProductAttributeValueFactory::new()->create([
                'tenant_id' => $this->tenantId,
                'attribute_id' => $color->id,
                'code' => $code,
                'label' => $label,
            ]);
        }

        return compact('product', 'size', 'color', 'sizeValues', 'colorValues');
    }

    public function test_generates_only_selected_value_subset(): void
    {
        $f = $this->seedFixture();

        $result = $this->service->generateMatrix((string) $f['product']->id, [
            ['attributeId' => $f['size']->id, 'valueIds' => [$f['sizeValues']['36']->id, $f['sizeValues']['37']->id]],
            ['attributeId' => $f['color']->id, 'valueIds' => [$f['colorValues']['noir']->id, $f['colorValues']['blanc']->id]],
        ]);

        // 2 sizes × 2 colors = 4; the unselected size 38 is excluded.
        $this->assertCount(4, $result['created']);
        $this->assertCount(0, $result['restored']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame(4, ProductVariant::where('product_id', $f['product']->id)->count());
    }

    public function test_regenerate_same_selection_is_idempotent(): void
    {
        $f = $this->seedFixture();
        $axes = [
            ['attributeId' => $f['size']->id, 'valueIds' => [$f['sizeValues']['36']->id, $f['sizeValues']['37']->id]],
            ['attributeId' => $f['color']->id, 'valueIds' => [$f['colorValues']['noir']->id, $f['colorValues']['blanc']->id]],
        ];

        $this->service->generateMatrix((string) $f['product']->id, $axes);
        $second = $this->service->generateMatrix((string) $f['product']->id, $axes);

        $this->assertCount(0, $second['created']);
        $this->assertCount(0, $second['restored']);
        $this->assertSame(4, $second['skipped_count']);
        $this->assertSame(4, ProductVariant::where('product_id', $f['product']->id)->count());
    }

    public function test_adding_a_value_creates_only_new_combos(): void
    {
        $f = $this->seedFixture();

        // First generation: 2 sizes × 2 colors = 4.
        $this->service->generateMatrix((string) $f['product']->id, [
            ['attributeId' => $f['size']->id, 'valueIds' => [$f['sizeValues']['36']->id, $f['sizeValues']['37']->id]],
            ['attributeId' => $f['color']->id, 'valueIds' => [$f['colorValues']['noir']->id, $f['colorValues']['blanc']->id]],
        ]);

        // Add size 38 → 3 sizes × 2 colors = 6 total; only the 2 new combos created.
        $second = $this->service->generateMatrix((string) $f['product']->id, [
            ['attributeId' => $f['size']->id, 'valueIds' => [$f['sizeValues']['36']->id, $f['sizeValues']['37']->id, $f['sizeValues']['38']->id]],
            ['attributeId' => $f['color']->id, 'valueIds' => [$f['colorValues']['noir']->id, $f['colorValues']['blanc']->id]],
        ]);

        $this->assertCount(2, $second['created']);
        $this->assertCount(0, $second['restored']);
        $this->assertSame(4, $second['skipped_count']);
        $this->assertSame(6, ProductVariant::where('product_id', $f['product']->id)->count());
    }

    public function test_regenerate_restores_a_soft_deleted_combo(): void
    {
        $f = $this->seedFixture();
        $axes = [
            ['attributeId' => $f['size']->id, 'valueIds' => [$f['sizeValues']['36']->id]],
            ['attributeId' => $f['color']->id, 'valueIds' => [$f['colorValues']['noir']->id]],
        ];

        $first = $this->service->generateMatrix((string) $f['product']->id, $axes);
        $this->assertCount(1, $first['created']);

        /** @var ProductVariant $variant */
        $variant = $first['created']->first();
        $variant->barcode = '1234567890';
        $variant->save();
        $variant->delete();

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);

        $second = $this->service->generateMatrix((string) $f['product']->id, $axes);

        $this->assertCount(0, $second['created']);
        $this->assertCount(1, $second['restored']);
        $this->assertSame(0, $second['skipped_count']);

        $restored = $second['restored']->first();
        $this->assertSame($variant->id, $restored->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('1234567890', $restored->barcode);

        // Exactly one physical row (active) — the restore reused the trashed one.
        $this->assertSame(1, ProductVariant::withTrashed()->where('product_id', $f['product']->id)->count());
    }

    public function test_idempotency_survives_value_code_rename(): void
    {
        $f = $this->seedFixture();
        $axes = [
            ['attributeId' => $f['size']->id, 'valueIds' => [$f['sizeValues']['36']->id]],
            ['attributeId' => $f['color']->id, 'valueIds' => [$f['colorValues']['noir']->id]],
        ];

        $this->service->generateMatrix((string) $f['product']->id, $axes);

        // Rename the value's code — the combo is keyed by ID, so it must still match.
        $f['sizeValues']['36']->code = 'EU36';
        $f['sizeValues']['36']->save();

        $second = $this->service->generateMatrix((string) $f['product']->id, $axes);

        $this->assertCount(0, $second['created']);
        $this->assertCount(0, $second['restored']);
        $this->assertSame(1, $second['skipped_count']);
        $this->assertSame(1, ProductVariant::where('product_id', $f['product']->id)->count());
    }

    public function test_generate_matrix_throws_when_gross_exceeds_cap(): void
    {
        $this->tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $this->tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $company->id,
            'sku' => 'BIG',
        ]);

        // One axis with 201 values → exceeds MAX_VARIANTS_PER_GENERATE (200).
        $attr = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenantId,
            'code' => 'huge',
        ]);
        $valueIds = [];
        for ($i = 1; $i <= 201; $i++) {
            $valueIds[] = ProductAttributeValueFactory::new()->create([
                'tenant_id' => $this->tenantId,
                'attribute_id' => $attr->id,
                'code' => "v{$i}",
                'label' => "Value {$i}",
            ])->id;
        }

        try {
            $this->service->generateMatrix((string) $product->id, [
                ['attributeId' => $attr->id, 'valueIds' => $valueIds],
            ]);
            $this->fail('Expected MatrixGenerationLimitException');
        } catch (MatrixGenerationLimitException $e) {
            $this->assertSame(0, ProductVariant::where('product_id', $product->id)->count());
        }
    }

    public function test_restore_with_conflicting_barcode_maps_to_422_not_500(): void
    {
        $f = $this->seedFixture();
        $axes = [
            ['attributeId' => $f['size']->id, 'valueIds' => [$f['sizeValues']['36']->id]],
            ['attributeId' => $f['color']->id, 'valueIds' => [$f['colorValues']['noir']->id]],
        ];

        $first = $this->service->generateMatrix((string) $f['product']->id, $axes);
        /** @var ProductVariant $variant */
        $variant = $first['created']->first();
        $variant->barcode = 'CONFLICT-BC';
        $variant->save();
        $variant->delete();

        // A DIFFERENT active variant now owns barcode CONFLICT-BC.
        ProductVariant::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $f['product']->company_id,
            'product_id' => $f['product']->id,
            'variant_code' => 'OTHER-VARIANT',
            'sku' => 'OTHER-VARIANT',
            'name_suffix' => 'Other',
            'barcode' => 'CONFLICT-BC',
        ]);

        // Regenerating the soft-deleted combo attempts a restore that violates the
        // partial barcode unique → must surface as a 422-mappable ValidationException.
        $this->expectException(ValidationException::class);

        $this->service->generateMatrix((string) $f['product']->id, $axes);
    }
}
