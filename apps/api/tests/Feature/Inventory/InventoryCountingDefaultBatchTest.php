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
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryCountingDefaultBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_counting_positive_delta_for_batch_tracked_product_reconciles_default_lot(): void
    {
        $tenant = Tenant::create([
            'name' => 'Default Batch Counting Tenant',
            'slug' => 'default-batch-counting',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Default Batch Counting Company',
            'legal_name' => 'Default Batch Counting Company LLC',
            'tax_id' => 'DBC-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'WH-DBC',
            'name' => 'Default Batch Counting Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sku' => 'DBC-001',
            'name' => 'Default Batch Counting Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 75,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Default Batch Counting User',
            'email' => 'default-batch-counting@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        StockLevel::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '5.0000',
            'reserved' => '0.0000',
        ]);
        $counting = InventoryCounting::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $location->id],
            'counting_number' => 'CNT-DEFAULT-BATCH',
            'status' => CountingStatus::Finalized,
            'created_by_user_id' => $user->id,
        ]);
        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'theoretical_qty' => '5.0000',
            'final_qty' => '7.0000',
            'resolution_method' => ItemResolutionMethod::AutoCountersAgree,
        ]);

        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $tenant->id,
            companyId: $company->id,
            locationId: $location->id,
            countingNumber: 'CNT-DEFAULT-BATCH',
            itemsCount: 1,
            totalVariance: '2.0000',
            completedBy: $user->id,
            completedAt: now()->toIso8601String(),
        ));

        $batch = Batch::where('product_id', $product->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->first();

        $this->assertNotNull($batch, 'positive counting delta must mint or reuse the DEFAULT lot');
        if ($batch === null) {
            return;
        }

        $batchStock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $location->id)
            ->firstOrFail();

        $this->assertSame('7.0000', StockLevel::where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, bccomp('7.0000', (string) $batchStock->quantity, 4));
    }
}
