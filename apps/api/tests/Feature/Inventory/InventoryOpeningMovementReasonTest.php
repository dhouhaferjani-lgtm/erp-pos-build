<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryOpeningService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E1: InventoryOpeningService::postBatch must persist MovementReason::OpeningBalance
 * on every stock_movements row it creates. Previously the `reason` column was left NULL.
 */
final class InventoryOpeningMovementReasonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Opening Reason Tenant',
            'slug' => 'opening-reason-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Reason Company',
            'legal_name' => 'Opening Reason Company LLC',
            'tax_id' => 'OBR-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening User',
            'email' => 'opening-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-OBR-01',
            'name' => 'Opening Reason Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'OBR-PROD-'.uniqid(),
            'name' => 'Opening Reason Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '10.000',
        ]);

        // GL accounts required by InventoryOpeningService::postBatch
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3100',
            'name' => 'Inventory',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /**
     * After posting an inventory opening batch, every resulting stock_movements row
     * must have reason = 'opening_balance'.
     */
    public function test_post_batch_persists_opening_balance_reason_on_stock_movements(): void
    {
        $batch = $this->makeInventoryBatch();
        $this->addValidRow($batch);

        app(InventoryOpeningService::class)->postBatch($batch, (string) $this->user->id);

        $movements = StockMovement::where('company_id', $this->company->id)->get();

        $this->assertNotEmpty($movements, 'Expected at least one stock movement to be created.');

        foreach ($movements as $movement) {
            $this->assertSame(
                MovementReason::OpeningBalance,
                $movement->reason,
                "stock_movements row {$movement->id} has reason={$movement->reason?->value}, expected opening_balance"
            );
        }

        $this->assertDatabaseHas('stock_movements', [
            'company_id' => $this->company->id,
            'reason' => MovementReason::OpeningBalance->value,
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeInventoryBatch(): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => OpeningBatchType::Inventory,
            'name' => 'Inventory OB '.uniqid(),
            'cutover_date' => Carbon::parse('2026-01-01'),
            'status' => OpeningBatchStatus::Draft,
            'created_by' => (string) $this->user->id,
        ]);
    }

    private function addValidRow(OpeningBalanceBatch $batch): void
    {
        OpeningBalanceImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'row_type' => 'INVENTORY',
            'status' => OpeningImportRowStatus::Valid,
            'raw_data' => [
                'product_code' => $this->product->sku,
                'location_code' => $this->warehouse->code,
                'quantity' => '50.0000',
                'unit_cost' => '10.000',
            ],
            'mapped_data' => [
                'product_id' => $this->product->id,
                'product_sku' => $this->product->sku,
                'product_name' => $this->product->name,
                'location_id' => $this->warehouse->id,
                'location_code' => $this->warehouse->code,
                'location_name' => $this->warehouse->name,
                'quantity' => '50.0000',
                'unit_cost' => '10.000',
            ],
        ]);
    }
}
