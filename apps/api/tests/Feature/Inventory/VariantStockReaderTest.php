<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\VariantStockReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cross-module read of on-hand stock for a single variant (D1).
 *
 * Inventory implements the Shared VariantStockReader contract; Catalog's
 * variant delete guard consumes it. Real DB (RefreshDatabase), no mocks.
 */
class VariantStockReaderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_returns_total_on_hand_quantity_for_variant(): void
    {
        $locationA = Location::factory()->create(['company_id' => $this->company->id]);
        $locationB = Location::factory()->create(['company_id' => $this->company->id]);

        StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'location_id' => $locationA->id,
            'quantity' => '3.0000',
            'reserved' => '0.0000',
        ]);
        StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'location_id' => $locationB->id,
            'quantity' => '2.5000',
            'reserved' => '0.0000',
        ]);

        $reader = app(VariantStockReader::class);

        $this->assertSame(
            '5.5000',
            $reader->variantOnHandQuantity($this->tenant->id, $this->company->id, $this->variant->id),
        );
    }

    public function test_returns_zero_when_no_stock(): void
    {
        $reader = app(VariantStockReader::class);

        $this->assertSame(
            '0.0000',
            $reader->variantOnHandQuantity($this->tenant->id, $this->company->id, $this->variant->id),
        );
    }
}
