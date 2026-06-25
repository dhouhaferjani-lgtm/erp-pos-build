<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Exceptions\WriteOffAlreadyReversedException;
use App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService;
use App\Modules\BatchExpiry\Domain\Services\ReverseWriteOffService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
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
 * Task C2 — ReverseWriteOffService: reverse a posted write-off, restoring
 * aggregate stock, batch (lot) stock, AND posting a balanced reversing JE — in
 * one transaction, idempotently, never more than once per original.
 *
 * Gold values (mirrors BatchWriteOffCostPersistenceTest):
 *   cost_price = 1.234 TND, quantity = 100.5000 units.
 *   write-off amount (TND scale 3) = 1.234 × 100.5 = 124.017
 */
final class ReverseWriteOffServiceTest extends TestCase
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
            'name' => 'Reverse WriteOff Tenant',
            'slug' => 'rev-wo-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reverse WriteOff Company',
            'legal_name' => 'Reverse WriteOff Company LLC',
            'tax_id' => 'RW-TAX-'.uniqid(),
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
            'name' => 'Reverse WriteOff User',
            'email' => 'rev-wo-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust', 'batches.write-off']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-RW-01',
            'name' => 'Reverse WriteOff Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RW-PROD-001',
            'name' => 'Reverse WriteOff Product',
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
            'batch_number' => 'BATCH-RW-001',
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

    private function service(): ReverseWriteOffService
    {
        return app(ReverseWriteOffService::class);
    }

    private function writeOffAll(): StockMovement
    {
        return app(BatchWriteOffService::class)->writeOff(
            batch: $this->batch,
            locationId: $this->warehouse->id,
            quantity: '100.5000',
            reason: 'expiry',
            userId: $this->user->id,
        );
    }

    /** Gate 1: aggregate stock_levels AND inventory_batch_stock restored to pre-write-off values. */
    public function test_reversal_restores_aggregate_and_batch_stock(): void
    {
        $original = $this->writeOffAll();

        // After write-off both ledgers are drained to zero.
        $this->assertSame('0.0000', (string) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));
        $this->assertSame('0.0000', (string) BatchStock::query()
            ->where('batch_id', $this->batch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));

        $this->service()->reverse($original, $this->user->id);

        $this->assertSame('100.5000', (string) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));
        $this->assertSame('100.5000', (string) BatchStock::query()
            ->where('batch_id', $this->batch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));
    }

    /** Gate 2: exactly ONE inverse inventory_batch_movements row is created by the reversal. */
    public function test_reversal_creates_exactly_one_inverse_batch_movement(): void
    {
        $original = $this->writeOffAll();

        $beforeCount = BatchMovement::query()->where('batch_id', $this->batch->id)->count();

        $inverse = $this->service()->reverse($original, $this->user->id);

        $afterCount = BatchMovement::query()->where('batch_id', $this->batch->id)->count();
        $this->assertSame($beforeCount + 1, $afterCount, 'exactly one new batch movement row');

        // The single new row links to the inverse movement and is a positive (restoring) qty.
        $this->assertSame(1, BatchMovement::query()->where('movement_id', $inverse->id)->count());
        $inverseRow = BatchMovement::query()->where('movement_id', $inverse->id)->firstOrFail();
        $this->assertSame('100.5000', (string) $inverseRow->quantity);
    }

    /** Gate 3: reversing JE balances, references the original, and its amount equals the original. */
    public function test_reversing_journal_entry_balances_and_equals_original(): void
    {
        $original = $this->writeOffAll();

        $originalEntry = JournalEntry::query()
            ->where('source_type', 'batch_write_off')
            ->where('source_id', $original->id)
            ->with('lines')
            ->firstOrFail();

        $originalDebit = $originalEntry->lines->reduce(
            fn (string $carry, $line): string => bcadd($carry, (string) $line->debit, 3),
            '0'
        );
        $this->assertSame('124.017', $originalDebit);

        $inverse = $this->service()->reverse($original, $this->user->id);

        $reversalEntry = JournalEntry::query()
            ->where('source_type', 'batch_write_off_reversal')
            ->where('source_id', $inverse->id)
            ->with('lines')
            ->firstOrFail();

        $revDebit = $reversalEntry->lines->reduce(
            fn (string $carry, $line): string => bcadd($carry, (string) $line->debit, 3),
            '0'
        );
        $revCredit = $reversalEntry->lines->reduce(
            fn (string $carry, $line): string => bcadd($carry, (string) $line->credit, 3),
            '0'
        );

        // Balances.
        $this->assertSame(0, bccomp($revDebit, $revCredit, 3), 'reversing JE must balance');
        // Amount EQUALS the original write-off amount exactly.
        $this->assertSame($originalDebit, $revDebit, 'reversal amount must equal original write-off amount');
        // References the original via the inverse movement -> reverses_movement_id chain.
        $this->assertSame($original->id, $inverse->reverses_movement_id);
    }

    /** Gate 3b: when the original JE was Posted, the reversal mirrors Posted status. */
    public function test_reversal_mirrors_posted_status_of_original(): void
    {
        $original = $this->writeOffAll();

        // Ensure the original write-off JE is fiscally sealed (Posted). The
        // write-off path posts it; if it is still Draft, post it explicitly.
        $originalEntry = JournalEntry::query()
            ->where('source_type', 'batch_write_off')
            ->where('source_id', $original->id)
            ->firstOrFail();
        if ($originalEntry->status !== JournalEntryStatus::Posted) {
            app(GeneralLedgerService::class)->postEntry($originalEntry, $this->user, 'TND');
        }
        $originalEntry->refresh();
        $this->assertSame(JournalEntryStatus::Posted, $originalEntry->status);

        $inverse = $this->service()->reverse($original, $this->user->id);

        $reversalEntry = JournalEntry::query()
            ->where('source_type', 'batch_write_off_reversal')
            ->where('source_id', $inverse->id)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Posted, $reversalEntry->status);
    }

    /** Gate 4: reverses_movement_id is set on the inverse movement. */
    public function test_reverses_movement_id_is_set_on_inverse(): void
    {
        $original = $this->writeOffAll();

        $inverse = $this->service()->reverse($original, $this->user->id);

        $this->assertSame($original->id, $inverse->reverses_movement_id);
        $original->refresh();
        $this->assertSame($inverse->id, $original->reversalOf?->id);
    }

    /** Gate 5: a second reverse of the same original is rejected at the app layer. */
    public function test_double_reverse_is_rejected(): void
    {
        $original = $this->writeOffAll();

        $this->service()->reverse($original, $this->user->id);

        $original->refresh();
        $this->expectException(WriteOffAlreadyReversedException::class);
        $this->service()->reverse($original, $this->user->id);
    }

    /** Gate 6: a cross-company original is rejected. */
    public function test_cross_company_original_is_rejected(): void
    {
        $original = $this->writeOffAll();

        // Switch the bound company to a different one in the same tenant.
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'OC-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        app(CompanyContext::class)->setCompanyId($otherCompany->id);

        $this->expectException(\DomainException::class);
        $this->service()->reverse($original, $this->user->id);
    }

    /** Gate 7: reversing a non-write-off movement (e.g. a delivery/issue) is rejected. */
    public function test_non_write_off_movement_is_rejected(): void
    {
        // A plain issue with a non-write-off reason.
        $issue = app(StockAdjustmentService::class)->issue(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'Delivery issue',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
            reason: MovementReason::Delivery,
            unitCost: '1.234000',
        );

        $this->expectException(\DomainException::class);
        $this->service()->reverse($issue, $this->user->id);
    }

    /** Gate 8: the ABSENT-JE path — reversal still restores stock and does not throw. */
    public function test_absent_journal_entry_still_restores_stock(): void
    {
        // A zero-cost product produces a non-positive write-off amount, so the
        // write-off path creates NO journal entry (createInventoryWriteOffEntry
        // returns null for amount <= 0).
        $zeroProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'RW-ZERO-001',
            'name' => 'Zero Cost Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '0',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $zeroProduct->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '20.0000',
            'reserved' => '0.0000',
        ]);

        $zeroBatch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $zeroProduct->id,
            'batch_number' => 'BATCH-RW-ZERO',
            'expiry_date' => now()->addYear(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $zeroBatch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '20.0000',
            'reserved_quantity' => '0.0000',
        ]);

        $original = app(BatchWriteOffService::class)->writeOff(
            batch: $zeroBatch,
            locationId: $this->warehouse->id,
            quantity: '20.0000',
            reason: 'damage',
            userId: $this->user->id,
        );

        // Sanity: no JE was created for the zero-amount write-off.
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'batch_write_off')
            ->where('source_id', $original->id)
            ->count());

        $inverse = $this->service()->reverse($original, $this->user->id);

        // Stock restored despite no JE.
        $this->assertSame('20.0000', (string) StockLevel::query()
            ->where('product_id', $zeroProduct->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));
        $this->assertSame('20.0000', (string) BatchStock::query()
            ->where('batch_id', $zeroBatch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity'));

        // No reversal JE either.
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'batch_write_off_reversal')
            ->where('source_id', $inverse->id)
            ->count());
    }
}
