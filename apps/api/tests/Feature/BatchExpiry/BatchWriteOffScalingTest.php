<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
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
 * Precision regression: BatchWriteOffService::calculateWriteOffAmount
 *
 * Before the fix: bcmul($quantity, $unitCost, 2) — hardcoded scale 2.
 * After  the fix: bcmul($quantity, $unitCost, $this->scaleResolver->getScale()) — dynamic.
 *
 * Gold assertion: 100.5000 units × 1.234 TND (scale 3) → GL debit '124.017'.
 * With the old code the GL debit would be '124.01' (truncated to scale 2).
 */
final class BatchWriteOffScalingTest extends TestCase
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
            'name' => 'TND Write-Off Tenant',
            'slug' => 'tnd-writeoff-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // TND company — scale 3
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TND Write-Off Company',
            'legal_name' => 'TND Write-Off Company LLC',
            'tax_id' => 'TND-TAX-'.uniqid(),
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
            'name' => 'Write-Off User',
            'email' => 'writeoff-'.uniqid().'@example.com',
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
            'code' => 'WH-WO-01',
            'name' => 'Write-Off Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        // Product with cost_price = 1.234 TND
        // Note: BatchWriteOffService accesses $product->weighted_average_cost ?? $product->cost_price
        // weighted_average_cost is not a DB column — cost_price is the WAC fallback.
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'WO-PROD-001',
            'name' => 'Write-Off Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.234',
        ]);

        // Seed aggregate stock so the write-off of 100.5000 can deduct (single decrement).
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '100.5000',
            'reserved' => '0.0000',
        ]);

        // Create the batch
        $this->batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'BATCH-WO-001',
            'expiry_date' => now()->addYear(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        // Seed batch stock at the warehouse (single decrement of 100.5000).
        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $this->batch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '100.5000',
            'reserved_quantity' => '0.0000',
        ]);

        // Create GL accounts so the journal entry can be persisted
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
     * 100.5000 units × 1.234 TND (scale 3) must produce GL debit '124.017'.
     *
     * Old: bcmul($quantity, $unitCost, 2) → GL debit '124.01'
     * New: bcmul($quantity, $unitCost, getScale()) → GL debit '124.017' (scale 3, TND)
     */
    public function test_write_off_amount_uses_currency_scale_from_resolver(): void
    {
        // Sanity: GL accounts must exist before we call writeOff
        $this->assertDatabaseHas('accounts', [
            'company_id' => $this->company->id,
            'system_purpose' => SystemAccountPurpose::CostOfGoodsSold->value,
        ]);
        $this->assertDatabaseHas('accounts', [
            'company_id' => $this->company->id,
            'system_purpose' => SystemAccountPurpose::Inventory->value,
        ]);

        $service = app(BatchWriteOffService::class);

        $service->writeOff(
            batch: $this->batch,
            locationId: $this->warehouse->id,
            quantity: '100.5000',
            reason: 'damage',
            userId: $this->user->id,
            notes: 'scale-3 regression test',
        );

        // At least one JournalLine must exist after writeOff
        $allDebitLines = JournalLine::query()->where('debit', '>', '0')->get();
        $this->assertNotEmpty(
            $allDebitLines,
            'No JournalLines with positive debit found after writeOff. '
            .'GL entry creation is failing silently (catch(\RuntimeException)).'
        );

        // The debit must be '124.017' (scale 3 TND), NOT '124.01' (scale 2 hardcoded).
        $debitAmounts = $allDebitLines->pluck('debit')->map(fn ($v) => (string) $v)->all();
        $this->assertContains(
            '124.017',
            $debitAmounts,
            'Expected GL debit "124.017" (scale-3 TND). '
            .'Found: ['.implode(', ', $debitAmounts).']. '
            .'If "124.01" is present, BatchWriteOffService still uses hardcoded bcmul scale 2.'
        );

        $entry = JournalEntry::query()
            ->where('source_type', 'batch_write_off')
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($this->user->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);
        $this->assertNotNull($entry->fiscal_hash);
    }
}
