<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: BatchWriteOffService must decrement lot stock EXACTLY ONCE.
 *
 * Bug: writeOff() called StockAdjustmentService::issue() WITH batchId (which
 * already decremented inventory_batch_stock via recordBatchMovement) AND then
 * BatchStockService::issueBatchStock() (which decremented it again). With a lot
 * seeded at exactly the write-off quantity, the first decrement zeroes it and
 * the second throws InsufficientBatchStockException — i.e. you cannot write off
 * a lot's full on-hand quantity. After the fix, issueBatchStock() is the single
 * batch-stock authority and the write-off succeeds with one decrement.
 */
final class BatchWriteOffDoubleDecrementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'WriteOff Tenant',
            'slug' => 'writeoff-dd-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WriteOff Company',
            'legal_name' => 'WriteOff Company LLC',
            'tax_id' => 'WO-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WriteOff User',
            'email' => 'writeoff-dd-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-DD-01',
            'name' => 'WriteOff Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'WO-DD-001',
            'name' => 'WriteOff Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.234',
        ]);

        // Seed EXACTLY the write-off quantity at both the aggregate and lot level.
        // A correct single-decrement write-off of the full on-hand must succeed.
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '100.5000',
            'reserved' => '0.0000',
        ]);

        $this->batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-DD-001',
            'expiry_date' => now()->addYear(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $this->batch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '100.5000',
            'reserved_quantity' => '0.0000',
        ]);

        // GL accounts so the (posted) write-off journal entry can persist.
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '601',
            'name' => 'Cost of Goods Sold',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::CostOfGoodsSold,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '311',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
        ]);
    }

    public function test_write_off_of_full_lot_stock_decrements_exactly_once(): void
    {
        app(BatchWriteOffService::class)->writeOff(
            batch: $this->batch,
            locationId: $this->warehouse->id,
            quantity: '100.5000',
            reason: 'expiry',
            userId: $this->user->id,
        );

        // Lot stock fully consumed — exactly once, not driven negative or blocked.
        $this->assertSame('0.0000', (string) BatchStock::query()
            ->where('batch_id', $this->batch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));

        // Aggregate stock decremented exactly once.
        $this->assertSame('0.0000', (string) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));

        // Exactly one batch movement row for this write-off (bug created two).
        $this->assertSame(1, BatchMovement::query()
            ->where('batch_id', $this->batch->id)
            ->count());
    }

    public function test_write_off_persists_the_typed_movement_reason(): void
    {
        $movement = app(BatchWriteOffService::class)->writeOff(
            batch: $this->batch,
            locationId: $this->warehouse->id,
            quantity: '100.5000',
            reason: 'expiry',
            userId: $this->user->id,
        );

        $this->assertSame(MovementReason::Expiry, $movement->reason);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'reason' => 'expiry',
        ]);
    }
}
