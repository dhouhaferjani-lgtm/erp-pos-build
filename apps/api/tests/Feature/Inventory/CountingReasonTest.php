<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

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

/**
 * A2: a count-correction adjustment must be distinguishable by a typed reason
 * (MovementReason::CountCorrection = 'count_correction'), set by the counting
 * listener. Previously the only discriminator was the free-text 'COUNTING:'
 * reference prefix; reason was left NULL.
 */
final class CountingReasonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Count Tenant',
            'slug' => 'count-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Count Company',
            'legal_name' => 'Count Company LLC',
            'tax_id' => 'CNT-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Count User',
            'email' => 'count-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-CNT-01',
            'name' => 'Count Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'CNT-001',
            'name' => 'Count Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);
    }

    public function test_counting_adjustment_persists_count_correction_reason(): void
    {
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'counting_number' => 'COUNT-RSN-001',
            'counting_date' => now(),
            'status' => CountingStatus::Finalized,
            'is_blind' => false,
            'created_by_user_id' => $this->user->id,
        ]);

        // Discrepancy: theoretical 10, counted 12 → +2 correction.
        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '10.0000',
            'final_qty' => '12.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        $event = new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            countingNumber: 'COUNT-RSN-001',
            itemsCount: 1,
            totalVariance: '2.0000',
            completedBy: $this->user->id,
            completedAt: now()->toIso8601String(),
        );

        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle($event);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => 'adjustment',
            'reason' => 'count_correction',
        ]);
    }
}
