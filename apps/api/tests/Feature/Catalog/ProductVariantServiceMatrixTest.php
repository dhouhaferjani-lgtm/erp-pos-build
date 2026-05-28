<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Services\ProductVariantService;
use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Database\Factories\CompanyFactory;
use Database\Factories\ProductFactory;
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

        // Attribute: taille — 6 values (36–41)
        $tailleAttr = ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'taille',
        ]);
        $tailleCodes = ['36', '37', '38', '39', '40', '41'];
        foreach ($tailleCodes as $code) {
            ProductAttributeValueFactory::new()->create([
                'tenant_id' => $tenantId,
                'attribute_id' => $tailleAttr->id,
                'code' => $code,
                'label' => $code,
            ]);
        }

        // Attribute: couleur — 3 values
        $couleurAttr = ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'couleur',
        ]);
        $couleurCodes = ['noir', 'blanc', 'beige'];
        foreach ($couleurCodes as $code) {
            ProductAttributeValueFactory::new()->create([
                'tenant_id' => $tenantId,
                'attribute_id' => $couleurAttr->id,
                'code' => $code,
                'label' => $code,
            ]);
        }

        $variants = $this->service->generateMatrix(
            productId: (string) $product->id,
            attributeIds: [$tailleAttr->id, $couleurAttr->id],
        );

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
    }
}
