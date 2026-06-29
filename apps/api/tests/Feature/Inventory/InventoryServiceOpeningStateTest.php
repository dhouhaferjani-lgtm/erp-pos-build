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
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\InventoryServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryServiceOpeningStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_opening_and_downstream_flags(): void
    {
        [$tenant, $company, $product, $location, $user] = $this->seedOpeningContext();

        $svc = app(InventoryServiceInterface::class);

        // Both flags false before any movement.
        $this->assertFalse($svc->hasActiveOpening($company->id, $product->id));
        $this->assertFalse($svc->hasDownstreamMovements($company->id, $product->id));

        // Post one opening balance.
        app(OpeningBalancePostingService::class)->post(new OpeningBalancePosting(
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

        // Active opening now exists; no downstream movements yet.
        $this->assertTrue($svc->hasActiveOpening($company->id, $product->id));
        $this->assertFalse($svc->hasDownstreamMovements($company->id, $product->id));
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
            'name' => 'IS Opening State Tenant',
            'slug' => 'is-os-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'IS Opening State Company',
            'legal_name' => 'IS Opening State Company LLC',
            'tax_id' => 'IS-OS-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'IS OS User',
            'email' => 'is-os-user-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'WH-IS-OS-01',
            'name' => 'IS OS Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sku' => 'IS-OS-PROD-'.uniqid(),
            'name' => 'IS OS Product',
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
}
