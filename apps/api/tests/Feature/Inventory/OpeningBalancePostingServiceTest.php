<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Exceptions\OpeningAlreadyExistsException;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class OpeningBalancePostingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_one_opening_movement_stock_level_cost_and_balanced_gl(): void
    {
        Event::fake([StockMovementRecorded::class]);
        [$tenant, $company, $product, $location, $user] = $this->seedOpeningContext();

        $service = app(OpeningBalancePostingService::class);
        $result = $service->post(new OpeningBalancePosting(
            tenantId: $tenant->id,
            companyId: $company->id,
            userId: $user->id,
            entryDate: now(),
            isHistorical: true,
            sourceType: 'opening_balance',
            sourceId: $product->id,
            reference: 'Opening balance: '.$product->sku,
            notes: null,
            lines: [OpeningBalanceLine::make($product->id, null, $location->id, '10', '5.000', 3)],
        ));

        $movement = StockMovement::findOrFail($result->movementIdsInInputOrder[0]);
        $this->assertSame(MovementType::Opening, $movement->movement_type);
        $this->assertSame(MovementReason::OpeningBalance, $movement->reason);
        $this->assertTrue($movement->is_historical);

        $this->assertSame('10.0000', StockLevel::where('product_id', $product->id)->where('location_id', $location->id)->value('quantity'));
        $this->assertSame('5.000000', (string) $product->fresh()->cost_price);

        $entry = $result->entry;
        $debit = $entry->lines->firstWhere('account_id', $this->accountId($company->id, SystemAccountPurpose::Inventory));
        $credit = $entry->lines->firstWhere('account_id', $this->accountId($company->id, SystemAccountPurpose::OpeningBalanceEquity));
        $this->assertSame('50.000', $debit->debit);
        $this->assertSame('50.000', $credit->credit);

        Event::assertDispatched(StockMovementRecorded::class);
    }

    public function test_rejects_second_active_opening_for_same_product_location(): void
    {
        [$tenant, $company, $product, $location, $user] = $this->seedOpeningContext();
        $service = app(OpeningBalancePostingService::class);
        $posting = fn () => new OpeningBalancePosting($tenant->id, $company->id, $user->id, now(), true, 'opening_balance', $product->id, 'x', null, [OpeningBalanceLine::make($product->id, null, $location->id, '10', '5.000', 3)]);

        $service->post($posting());

        $this->expectException(OpeningAlreadyExistsException::class);
        $service->post($posting());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return array{0: Tenant, 1: Company, 2: Product, 3: Location, 4: User}
     */
    private function seedOpeningContext(): array
    {
        $tenant = Tenant::create([
            'name' => 'OB Posting Tenant',
            'slug' => 'ob-posting-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'OB Posting Company',
            'legal_name' => 'OB Posting Company LLC',
            'tax_id' => 'OB-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'OB User',
            'email' => 'ob-user-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'WH-OB-01',
            'name' => 'OB Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sku' => 'OB-PROD-'.uniqid(),
            'name' => 'OB Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '0.000000',
        ]);

        Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '3100',
            'name' => 'Inventory',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        return [$tenant, $company, $product, $location, $user];
    }

    private function accountId(string $companyId, SystemAccountPurpose $purpose): string
    {
        return (string) Account::where('company_id', $companyId)
            ->where('system_purpose', $purpose)
            ->value('id');
    }
}
