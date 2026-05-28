<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Commands\CreateVariantCommand;
use App\Modules\Catalog\Application\Exceptions\MissingVariantException;
use App\Modules\Catalog\Application\Services\ProductVariantService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use App\Modules\Catalog\Domain\Events\ProductVariantCreated;
use App\Modules\Company\Domain\Location;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Database\Factories\CompanyFactory;
use Database\Factories\LocationFactory;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariantServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariantService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductVariantService::class);
    }

    public function test_create_variant_persists_with_attribute_values_and_emits_event(): void
    {
        Event::fake([ProductVariantCreated::class]);

        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $colorAttr = ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'color',
        ]);
        $colorRed = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $tenantId,
            'attribute_id' => $colorAttr->id,
            'code' => 'red',
        ]);

        $sizeAttr = ProductAttributeFactory::new()->create([
            'tenant_id' => $tenantId,
            'code' => 'size',
        ]);
        $sizeXl = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $tenantId,
            'attribute_id' => $sizeAttr->id,
            'code' => 'xl',
        ]);

        $command = new CreateVariantCommand(
            tenantId: $tenantId,
            companyId: (string) $company->id,
            productId: (string) $product->id,
            variantCode: 'RED-XL',
            sku: 'SKU-RED-XL-001',
            nameSuffix: 'Red / XL',
            isDefault: true,
            barcode: null,
            priceOverride: null,
            costOverride: null,
            imageUrl: null,
            attributeValues: [
                ['attributeId' => $colorAttr->id, 'attributeValueId' => $colorRed->id],
                ['attributeId' => $sizeAttr->id, 'attributeValueId' => $sizeXl->id],
            ],
        );

        $variant = $this->service->createVariant($command);

        // Variant persisted
        $this->assertInstanceOf(ProductVariant::class, $variant);
        $this->assertNotEmpty($variant->id);
        $this->assertSame('SKU-RED-XL-001', $variant->sku);
        $this->assertSame('RED-XL', $variant->variant_code);
        $this->assertTrue($variant->is_default);

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'sku' => 'SKU-RED-XL-001',
            'product_id' => $product->id,
        ]);

        // 2 junction rows persisted
        $junctionRows = ProductVariantAttributeValue::where('variant_id', $variant->id)->get();
        $this->assertCount(2, $junctionRows);

        $attributeIds = $junctionRows->pluck('attribute_id')->all();
        $this->assertContains($colorAttr->id, $attributeIds);
        $this->assertContains($sizeAttr->id, $attributeIds);

        $valueIds = $junctionRows->pluck('attribute_value_id')->all();
        $this->assertContains($colorRed->id, $valueIds);
        $this->assertContains($sizeXl->id, $valueIds);

        // Event dispatched
        Event::assertDispatched(ProductVariantCreated::class, function (ProductVariantCreated $event) use ($variant, $tenantId, $company, $product): bool {
            return $event->variantId === $variant->id
                && $event->tenantId === $tenantId
                && $event->companyId === (string) $company->id
                && $event->productId === (string) $product->id
                && $event->sku === 'SKU-RED-XL-001'
                && $event->isDefault === true;
        });
    }

    public function test_create_variant_without_attribute_values(): void
    {
        Event::fake([ProductVariantCreated::class]);

        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $command = new CreateVariantCommand(
            tenantId: $tenantId,
            companyId: (string) $company->id,
            productId: (string) $product->id,
            variantCode: 'DEFAULT',
            sku: 'SKU-NOATTR-001',
            nameSuffix: 'Default',
            isDefault: false,
            attributeValues: [],
        );

        $variant = $this->service->createVariant($command);

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
        $this->assertSame(0, ProductVariantAttributeValue::where('variant_id', $variant->id)->count());
        Event::assertDispatched(ProductVariantCreated::class);
    }

    public function test_set_default_clears_previous_default(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $variantA = ProductVariant::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'variant_code' => 'VAR-A',
            'sku' => 'SKU-A-SETDEF',
            'is_default' => true,
        ]);

        $variantB = ProductVariant::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'variant_code' => 'VAR-B',
            'sku' => 'SKU-B-SETDEF',
            'is_default' => false,
        ]);

        $result = $this->service->setDefault($variantB->id);

        $this->assertTrue($result->is_default);
        $this->assertSame($variantB->id, $result->id);

        // Previous default should be cleared in DB
        $this->assertDatabaseHas('product_variants', [
            'id' => $variantA->id,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variantB->id,
            'is_default' => true,
        ]);
    }

    public function test_set_default_throws_when_variant_not_found(): void
    {
        $this->expectException(MissingVariantException::class);

        $this->service->setDefault((string) Str::uuid());
    }

    public function test_resolve_barcode_returns_variant(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'variant_code' => 'VAR-BC',
            'sku' => 'SKU-BC-SVC',
            'barcode' => '9782070360024',
        ]);

        $found = $this->service->resolveBarcode('9782070360024', (string) $company->id);

        $this->assertNotNull($found);
        $this->assertSame($variant->id, $found->id);
    }

    public function test_resolve_sku_returns_variant(): void
    {
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'variant_code' => 'VAR-SKU',
            'sku' => 'SKU-RESOLVE-001',
        ]);

        $found = $this->service->resolveSku('SKU-RESOLVE-001', (string) $company->id);

        $this->assertNotNull($found);
        $this->assertSame($variant->id, $found->id);
    }

    /**
     * Wire-in integration test: creating the first default variant for a product
     * must trigger StockLevelMigrationService and migrate the product's pre-existing
     * product-level stock_levels rows to the new variant.
     */
    public function test_create_first_default_variant_migrates_existing_product_stock(): void
    {
        Event::fake([ProductVariantCreated::class]);

        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);
        $product = ProductFactory::new()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        // Create a location belonging to the same company.
        $location = LocationFactory::new()->create(['company_id' => $company->id]);

        // Insert a product-level stock_levels row (variant_id NULL) representing
        // pre-existing stock before variants were introduced.
        DB::table('stock_levels')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'variant_id' => null,
            'quantity' => '10.00',
            'reserved' => '0.00',
        ]);

        // Confirm the product-level row exists before the call.
        $this->assertSame(1, DB::table('stock_levels')
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->count());

        $command = new CreateVariantCommand(
            tenantId: $tenantId,
            companyId: (string) $company->id,
            productId: (string) $product->id,
            variantCode: 'DEFAULT',
            sku: 'SKU-WIREIN-001',
            nameSuffix: 'Default',
            isDefault: true,
            attributeValues: [],
        );

        $variant = $this->service->createVariant($command);

        // After the call there must be zero product-level (null variant) rows.
        $this->assertSame(
            0,
            DB::table('stock_levels')
                ->where('product_id', $product->id)
                ->whereNull('variant_id')
                ->count(),
            'Product-level stock_levels row should have been migrated to the new variant.'
        );

        // The row must now point to the newly-created default variant.
        $this->assertSame(
            1,
            DB::table('stock_levels')
                ->where('product_id', $product->id)
                ->where('variant_id', $variant->id)
                ->count(),
            'Migrated stock_levels row must reference the new default variant.'
        );

        // Quantity must be preserved.
        $row = DB::table('stock_levels')
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('10.00', $row->quantity);

        // ProductVariantCreated event must still be dispatched.
        Event::assertDispatched(ProductVariantCreated::class, fn (ProductVariantCreated $e): bool => $e->variantId === $variant->id && $e->isDefault === true
        );
    }
}
