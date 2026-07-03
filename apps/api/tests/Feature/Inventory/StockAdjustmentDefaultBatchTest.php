<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StockAdjustmentDefaultBatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    private User $user;

    private StockAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Default Batch Adjustment Tenant',
            'slug' => 'default-batch-adjustment',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Default Batch Adjustment Company',
            'legal_name' => 'Default Batch Adjustment Company LLC',
            'tax_id' => 'DBA-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-DBA',
            'name' => 'Default Batch Adjustment Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'DBA-001',
            'name' => 'Default Batch Adjustment Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 30,
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Default Batch Adjustment User',
            'email' => 'default-batch-adjustment@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->service = app(StockAdjustmentService::class);
    }

    public function test_receive_without_explicit_batch_routes_batch_tracked_stock_into_default_lot(): void
    {
        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '7.1250',
            reference: 'PO-DEFAULT-BATCH',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $batch = $this->defaultBatch();
        $this->assertNotNull($batch, 'implicit receipt must mint or reuse the DEFAULT lot');
        if ($batch === null) {
            return;
        }
        $stock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame('7.1250', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(0, bccomp('7.1250', (string) $stock->quantity, 4));
    }

    public function test_receive_without_explicit_batch_tops_up_default_lot_when_only_lotless_stock_already_exists(): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '4.0000',
            'reserved' => '0.0000',
        ]);

        $this->service->receive(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '2.5000',
            reference: 'PO-DEFAULT-BATCH-LOTLESS',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $batch = $this->defaultBatch();
        $this->assertNotNull($batch, 'implicit receipt must backfill lotless existing stock into the DEFAULT lot');
        if ($batch === null) {
            return;
        }
        $stock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame('6.5000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(0, bccomp('6.5000', (string) $stock->quantity, 4));
    }

    public function test_adjust_upward_without_explicit_batch_routes_positive_delta_into_default_lot(): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '5.0000',
            'reserved' => '0.0000',
        ]);

        $this->service->adjust(
            productId: $this->product->id,
            locationId: $this->location->id,
            newQuantity: '8.7500',
            reason: 'Physical count found more stock',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $batch = $this->defaultBatch();
        $this->assertNotNull($batch, 'upward adjustment must mint or reuse the DEFAULT lot');
        if ($batch === null) {
            return;
        }
        $stock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();

        $this->assertSame('8.7500', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(0, bccomp('8.7500', (string) $stock->quantity, 4));
    }

    private function defaultBatch(): ?Batch
    {
        return Batch::where('product_id', $this->product->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->first();
    }
}
