<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Services\ProductVariantService;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Database\Factories\Catalog\ProductVariantFactory;
use Database\Factories\CompanyFactory;
use Database\Factories\ProductFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariantServiceMatrixTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariantService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductVariantService::class);
    }

    public function test_generate_matrix_persists_18_variants_for_3x6(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'sku' => 'SHOE-001',
        ]);

        // Attribute: taille — 6 values (36–41).
        // label differs from code: code is lowercase digit string, label is "Taille <N>" for clarity.
        $tailleAttr = ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'taille',
        ]);
        $tailleValues = [
            ['code' => '36', 'label' => 'Taille 36'],
            ['code' => '37', 'label' => 'Taille 37'],
            ['code' => '38', 'label' => 'Taille 38'],
            ['code' => '39', 'label' => 'Taille 39'],
            ['code' => '40', 'label' => 'Taille 40'],
            ['code' => '41', 'label' => 'Taille 41'],
        ];
        foreach ($tailleValues as $v) {
            ProductAttributeValueFactory::new()->create([
                'tenant_id' => $tenantId,
                'attribute_id' => $tailleAttr->id,
                'code' => $v['code'],
                'label' => $v['label'],
            ]);
        }

        // Attribute: couleur — 3 values.
        // code is lowercase ('noir'), label is capitalised ('Noir') to distinguish the two.
        $couleurAttr = ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'couleur',
        ]);
        $couleurValues = [
            ['code' => 'noir',  'label' => 'Noir'],
            ['code' => 'blanc', 'label' => 'Blanc'],
            ['code' => 'beige', 'label' => 'Beige'],
        ];
        foreach ($couleurValues as $v) {
            ProductAttributeValueFactory::new()->create([
                'tenant_id' => $tenantId,
                'attribute_id' => $couleurAttr->id,
                'code' => $v['code'],
                'label' => $v['label'],
            ]);
        }

        $result = $this->service->generateMatrix(
            productId: (string) $product->id,
            axes: [
                ['attributeId' => $tailleAttr->id, 'valueIds' => ProductAttributeValue::where('attribute_id', $tailleAttr->id)->pluck('id')->all()],
                ['attributeId' => $couleurAttr->id, 'valueIds' => ProductAttributeValue::where('attribute_id', $couleurAttr->id)->pluck('id')->all()],
            ],
        );

        $variants = $result['created'];

        // 18 variants persisted (6 × 3)
        $this->assertCount(18, $variants);

        // All SKUs are unique
        $skus = $variants->pluck('sku')->all();
        $this->assertCount(18, array_unique($skus), 'All 18 SKUs must be unique');

        // All variant_codes are unique within the product
        $codes = $variants->pluck('variant_code')->all();
        $this->assertCount(18, array_unique($codes), 'All 18 variant_codes must be unique');

        // Each variant persisted in DB
        foreach ($variants as $variant) {
            $this->assertDatabaseHas('product_variants', [
                'id' => $variant->id,
                'product_id' => $product->id,
            ]);
        }

        // Each variant has exactly 2 attribute-value junction rows
        foreach ($variants as $variant) {
            $junctionCount = ProductVariantAttributeValue::where('variant_id', $variant->id)->count();
            $this->assertSame(2, $junctionCount, "Variant {$variant->variant_code} must have 2 junction rows");
        }

        // Exactly one variant is_default among the 18
        $defaultCount = $variants->where('is_default', true)->count();
        $this->assertSame(1, $defaultCount, 'Exactly one variant must be the default');

        // Fix 2 verification: name_suffix must use LABELS, not codes.
        // variant_code uses codes (uppercase), name_suffix uses labels.
        // Check the first variant (taille=36, couleur=noir combination).
        // variant_code should contain 'NOIR' (code uppercased), not 'Noir'.
        // name_suffix should contain 'Noir' (label), not 'NOIR' or 'noir'.
        $firstVariant = $variants->first();
        $this->assertNotNull($firstVariant);
        // SKU / variant_code uses codes uppercased
        $this->assertStringContainsString('SHOE-001', $firstVariant->variant_code);
        // name_suffix uses labels — it must NOT be all-caps (which would indicate codes were used instead)
        // and must match one of the expected label patterns
        $nameSuffix = $firstVariant->name_suffix;
        // Labels contain spaces ("Taille 36") whereas codes do not ("36").
        // Verify the taille axis uses the label by checking the first taille variant's suffix
        // contains a space-containing label portion (e.g. "Taille 36").
        $this->assertStringContainsString(' / ', $nameSuffix, 'name_suffix must use " / " separator');
        $parts = explode(' / ', $nameSuffix);
        $this->assertCount(2, $parts, 'name_suffix must have exactly 2 parts for a 2-axis matrix');
        // The taille part should be the label (e.g. "Taille 36"), not just the code ("36")
        $this->assertStringStartsWith('Taille ', $parts[0], 'name_suffix taille part must use the label, not the code');
        // The couleur part must be a capitalised label ("Noir"/"Blanc"/"Beige"), not lowercase code
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+$/', $parts[1], 'name_suffix couleur part must be the capitalised label');
    }

    /**
     * Fix 1 atomicity test: if any createVariant call fails mid-loop, the entire
     * matrix generation must roll back — zero new variants committed.
     *
     * Failure is induced by pre-inserting a variant whose (product_id, variant_code)
     * matches the variant_code the matrix would generate for one of the combos.
     * generateMatrix derives variant_code as "{PRODUCT_SKU}-{CODE1}-{CODE2}…" (uppercased).
     * We pre-insert with variant_code = "BASE-A-X" which matches the second combo
     * (index 1), so the first combo succeeds inside createVariant before the collision
     * triggers a QueryException — proving the outer transaction rolled back both.
     */
    public function test_generate_matrix_is_atomic_on_failure(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'sku' => 'BASE',
        ]);

        // Attribute: axis1 — 2 values (a, b)
        $axis1Attr = ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'axis1',
        ]);
        foreach ([['code' => 'a', 'label' => 'A Label'], ['code' => 'b', 'label' => 'B Label']] as $v) {
            ProductAttributeValueFactory::new()->create([
                'tenant_id' => $tenantId,
                'attribute_id' => $axis1Attr->id,
                'code' => $v['code'],
                'label' => $v['label'],
            ]);
        }

        // Attribute: axis2 — 1 value (x)
        $axis2Attr = ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'axis2',
        ]);
        ProductAttributeValueFactory::new()->create([
            'tenant_id' => $tenantId,
            'attribute_id' => $axis2Attr->id,
            'code' => 'x',
            'label' => 'X Label',
        ]);

        // Matrix will produce combos: [a,x] → variant_code "BASE-A-X" and [b,x] → "BASE-B-X".
        // Pre-insert "BASE-B-X" so the second createVariant call hits the unique constraint
        // on (product_id, variant_code) and throws a QueryException mid-loop.
        // The first combo "BASE-A-X" will have been created (within its own inner transaction)
        // before the collision hits; the outer transaction must roll it back too.
        ProductVariantFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'variant_code' => 'BASE-B-X',
            'sku' => 'BASE-B-X',
        ]);

        $variantCountBefore = ProductVariant::where('product_id', $product->id)->count();
        // Pre-existing count is 1 (the collision row we just inserted).
        $this->assertSame(1, $variantCountBefore);

        // generateMatrix must throw (propagating the QueryException from the collision).
        $this->expectException(QueryException::class);

        try {
            $this->service->generateMatrix(
                productId: (string) $product->id,
                axes: [
                    ['attributeId' => $axis1Attr->id, 'valueIds' => ProductAttributeValue::where('attribute_id', $axis1Attr->id)->pluck('id')->all()],
                    ['attributeId' => $axis2Attr->id, 'valueIds' => ProductAttributeValue::where('attribute_id', $axis2Attr->id)->pluck('id')->all()],
                ],
            );
        } finally {
            // Regardless of exception, assert that NO new variants were committed.
            // Only the pre-inserted collision row should remain.
            $variantCountAfter = ProductVariant::where('product_id', $product->id)->count();
            $this->assertSame(
                $variantCountBefore,
                $variantCountAfter,
                'Outer transaction must have rolled back all new matrix variants on failure'
            );
        }
    }
}
