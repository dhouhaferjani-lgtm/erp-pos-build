<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
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
 * B2: the write-off stock_movements row must carry unit_cost and total_cost.
 *
 * A future Phase C "reverse write-off" entry must recover the ORIGINAL cost of
 * the written-off stock from the movement row itself — not by recomputing from a
 * now-changed WAC.  Today the StockAdjustmentService::issue() path never writes
 * the cost columns (they are left NULL).
 *
 * Gold values: product cost_price = 1.234 TND, quantity = 100.5000 units.
 *   unit_cost  = 1.234000  (cost column at COST_SCALE=6)
 *   total_cost = 124.017000  (unit_cost × quantity at COST_SCALE=6; 1.234×100.5 = 124.017)
 *
 * Note: 1.234000 × 100.5000 = 124.017000 (exact, no rounding needed at scale 6)
 */
final class BatchWriteOffCostPersistenceTest extends TestCase
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
            'name' => 'Cost Persistence Tenant',
            'slug' => 'cost-persist-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // TND company — scale 3; cost columns use COST_SCALE=6
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cost Persistence Company',
            'legal_name' => 'Cost Persistence Company LLC',
            'tax_id' => 'CP-TAX-'.uniqid(),
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
            'name' => 'Cost Persistence User',
            'email' => 'cost-persist-'.uniqid().'@example.com',
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
            'code' => 'WH-CP-01',
            'name' => 'Cost Persistence Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        // cost_price = 1.234 TND (the WAC fallback used by calculateWriteOffAmount)
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'CP-PROD-001',
            'name' => 'Cost Persistence Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.234',
        ]);

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
            'batch_number' => 'BATCH-CP-001',
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

        // GL accounts required for the journal entry
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

    /**
     * After a batch write-off the resulting stock_movements row must carry
     * non-null unit_cost (= the product's WAC / cost_price) and total_cost
     * (= unit_cost × quantity), both at COST_SCALE=6.
     *
     * Gold: unit_cost = '1.234000', total_cost = '124.017000'
     *   (1.234000 × 100.5000 = 124.017000 — exact at scale 6)
     */
    public function test_write_off_movement_persists_unit_cost_and_total_cost(): void
    {
        $movement = app(BatchWriteOffService::class)->writeOff(
            batch: $this->batch,
            locationId: $this->warehouse->id,
            quantity: '100.5000',
            reason: 'expiry',
            userId: $this->user->id,
        );

        // Both cost columns must be non-null after the write-off
        $this->assertNotNull($movement->unit_cost, 'unit_cost must not be NULL on a write-off movement');
        $this->assertNotNull($movement->total_cost, 'total_cost must not be NULL on a write-off movement');

        // unit_cost = product cost_price at COST_SCALE=6
        $this->assertSame(
            '1.234000',
            (string) $movement->unit_cost,
            'unit_cost must equal the product WAC (cost_price) at COST_SCALE=6'
        );

        // total_cost = unit_cost × quantity at COST_SCALE=6
        // 1.234 × 100.5 = 124.017 (exact, no rounding)
        $this->assertSame(
            '124.017000',
            (string) $movement->total_cost,
            'total_cost must equal unit_cost × quantity at COST_SCALE=6'
        );

        // Cross-check: DB row matches the returned model
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'unit_cost' => '1.234000',
            'total_cost' => '124.017000',
        ]);
    }

    /**
     * B2-review fix: movement unit_cost and GL write-off amount must come from
     * the SAME per-unit cost source (resolveUnitCost).
     *
     * If BatchWriteOffService::writeOff() ever reads unit cost from a DIFFERENT
     * expression than calculateWriteOffAmount() uses, the GL debit and the
     * persisted movement unit_cost diverge — a Phase C reversal would then
     * reconstruct the wrong journal entry.
     *
     * Guard: set cost_price = 1.234, write off 100.5000 units.
     *   - movement.unit_cost = 1.234000  (resolveUnitCost)
     *   - GL debit            = 124.017  (resolveUnitCost × qty, at TND scale 3)
     *   - GL debit / qty      = 1.234    (round-trip back to the per-unit basis)
     *
     * A divergence (e.g. writeOff reads cost_price='1.234', calculateWriteOffAmount
     * reads a hypothetical weighted_average_cost='2.000') would produce:
     *   movement.unit_cost = '1.234000' but GL debit / qty ≠ '1.234' → test fails.
     */
    public function test_movement_unit_cost_and_gl_amount_share_the_same_per_unit_basis(): void
    {
        // Seed a fresh StockLevel (this test reuses the same product/batch from setUp).
        // setUp already seeded 100.5000; reset is not needed — we write off all 100.5000.
        $movement = app(BatchWriteOffService::class)->writeOff(
            batch: $this->batch,
            locationId: $this->warehouse->id,
            quantity: '100.5000',
            reason: 'expiry',
            userId: $this->user->id,
        );

        // 1. movement.unit_cost from the service (resolveUnitCost path)
        $movementUnitCost = (string) $movement->unit_cost;
        $this->assertNotNull($movementUnitCost, 'unit_cost must be set');

        // 2. GL journal line debit (the calculateWriteOffAmount path)
        $debitLine = JournalLine::query()
            ->where('debit', '>', '0')
            ->first();
        $this->assertNotNull($debitLine, 'A GL debit line must exist after write-off');
        $glDebit = (string) $debitLine->debit; // e.g. '124.017' (TND scale 3)

        // 3. Round-trip: GL debit ÷ quantity should equal the per-unit cost basis.
        //    We use scale 6 (cost scale) for the division so the result is precise.
        //    Expected: bcdiv('124.017', '100.5000', 6) = '1.234000'
        $impliedUnitCost = bcdiv($glDebit, '100.5000', 6);

        // Normalise movement.unit_cost to cost scale 6 (it is already stored at scale 6).
        $storedUnitCostNormalised = bcadd($movementUnitCost, '0', 6);

        $this->assertSame(
            $storedUnitCostNormalised,
            $impliedUnitCost,
            sprintf(
                'movement.unit_cost (%s) and GL amount ÷ qty (%s ÷ 100.5000 = %s) must share the same per-unit cost basis. '
                .'If they differ, resolveUnitCost() is not being used consistently in both paths.',
                $movementUnitCost,
                $glDebit,
                $impliedUnitCost,
            ),
        );
    }

    /**
     * Direct StockAdjustmentService::issue() with an explicit unitCost must
     * persist unit_cost and total_cost on the movement row.
     *
     * This tests the threading seam independently of BatchWriteOffService so the
     * two concerns (threading + caller) can be debugged separately.
     */
    public function test_issue_with_explicit_unit_cost_persists_cost_columns(): void
    {
        // Seed fresh stock for this independent test
        StockLevel::where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->update(['quantity' => '50.0000', 'reserved' => '0.0000']);

        $movement = app(StockAdjustmentService::class)->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '10.0000',
            reference: 'B2 unit-cost test',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            unitCost: '2.500000',
        );

        $this->assertNotNull($movement->unit_cost);
        $this->assertNotNull($movement->total_cost);

        $this->assertSame('2.500000', (string) $movement->unit_cost);
        // total_cost = 2.500000 × 10.0000 = 25.000000
        $this->assertSame('25.000000', (string) $movement->total_cost);

        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'unit_cost' => '2.500000',
            'total_cost' => '25.000000',
        ]);
    }
}
