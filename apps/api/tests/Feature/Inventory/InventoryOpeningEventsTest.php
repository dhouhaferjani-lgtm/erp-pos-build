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
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * E2: InventoryOpeningService::postBatch must dispatch StockMovementRecorded
 * after delegating to OpeningBalancePostingService.
 */
final class InventoryOpeningEventsTest extends TestCase
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
            'name' => 'Opening Events Tenant',
            'slug' => 'opening-events-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Events Company',
            'legal_name' => 'Opening Events Company LLC',
            'tax_id' => 'OBE-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Events User',
            'email' => 'opening-events-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-OBE-01',
            'name' => 'Opening Events Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'OBE-PROD-'.uniqid(),
            'name' => 'Opening Events Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '10.000',
        ]);

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
     * After posting an inventory opening batch via the import path,
     * StockMovementRecorded must be dispatched (one per movement line).
     */
    public function test_post_batch_dispatches_stock_movement_recorded_event(): void
    {
        Event::fake([StockMovementRecorded::class]);

        $batch = $this->makeInventoryBatch();
        $this->addValidRow($batch);

        app(InventoryOpeningService::class)->postBatch($batch, (string) $this->user->id);

        Event::assertDispatched(StockMovementRecorded::class);
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
            'name' => 'Inventory OB Events '.uniqid(),
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
