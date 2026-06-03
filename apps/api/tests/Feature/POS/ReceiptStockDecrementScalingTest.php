<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 3.10 — POS stock decrement must use the canonical quantity scale (4).
 *
 * `stock_levels.quantity` and `pos_receipt_lines.quantity` are stored at scale
 * 4. The stock-decrement subtraction in ReceiptCreationService::decrementStock
 * previously used bcsub(..., 2), silently truncating sub-centi quantities to
 * zero — a parapharma scenario (e.g. 0.0010 of a bulk product) would never
 * decrement stock. This test pins the scale-4 behaviour.
 *
 * NOTE: stock quantity is NOT part of the canonical fiscal hash payload, so
 * this change does not touch any fiscal golden fixture or receipt-hash value.
 */
final class ReceiptStockDecrementScalingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        // Tunisia → TND (money scale 3); quantity scale is fixed at 4.
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        /** @var CompanyContext $ctx */
        $ctx = app(CompanyContext::class);
        $ctx->setCompanyId($this->company->id);
    }

    public function test_sub_centi_quantity_decrements_stock_at_scale_four(): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '5.0000',
            'reserved' => '0.0000',
        ]);

        $service = app(ReceiptCreationService::class);
        $decrement = new ReflectionMethod($service, 'decrementStock');

        $receiptId = Str::uuid()->toString();

        $movement = $decrement->invoke(
            $service,
            $this->tenant->id,
            $this->company->id,
            $this->location->id,
            $this->product->id,
            '0.0010', // canonical quantity scale 4 — would be lost at scale 2
            $receiptId,
            $this->cashier->id,
        );

        $this->assertNotNull($movement, 'decrementStock should return a movement when stock exists');

        $stockLevel = StockLevel::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertNotNull($stockLevel);

        // 5.0000 - 0.0010 = 4.9990 (scale 4). At the old scale-2 bcsub this
        // would have stayed 5.00.
        $this->assertSame('4.9990', $stockLevel->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'reference_id' => $receiptId,
            'quantity_before' => '5.0000',
            'quantity_after' => '4.9990',
        ]);
    }
}
