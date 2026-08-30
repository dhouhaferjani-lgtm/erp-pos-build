<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class OpeningBatchNumberingCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sibling_companies_receive_the_same_first_ar_and_stock_opening_numbers(): void
    {
        $tenant = Tenant::create([
            'name' => 'Opening Number Scope Tenant',
            'slug' => 'opening-number-scope',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Opening Number Scope User',
            'email' => 'opening-number-scope@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $companyA = $this->createCompany($tenant, 'A');
        $companyB = $this->createCompany($tenant, 'B');

        $this->postArAndStockOpenings($tenant, $companyA, $user, 'A');
        $this->postArAndStockOpenings($tenant, $companyB, $user, 'B');

        $year = date('Y');
        foreach ([$companyA, $companyB] as $company) {
            $this->assertSame(
                1,
                JournalEntry::query()
                    ->where('company_id', $company->id)
                    ->where('entry_number', "OB-{$year}-000001")
                    ->count(),
            );
            $this->assertSame(
                1,
                JournalEntry::query()
                    ->where('company_id', $company->id)
                    ->where('entry_number', "INV-OB-{$year}-000001")
                    ->count(),
            );
        }
    }

    private function createCompany(Tenant $tenant, string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Opening Number Scope Company {$suffix}",
            'legal_name' => "Opening Number Scope Company {$suffix} LLC",
            'tax_id' => "OB-SCOPE-TAX-{$suffix}",
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function postArAndStockOpenings(
        Tenant $tenant,
        Company $company,
        User $user,
        string $suffix,
    ): void {
        app(CompanyContext::class)->setCompanyId($company->id);

        $this->createAccount($tenant, $company, '4110', SystemAccountPurpose::CustomerReceivable, AccountType::Asset);
        $this->createAccount($tenant, $company, '3100', SystemAccountPurpose::Inventory, AccountType::Asset);
        $this->createAccount($tenant, $company, '3900', SystemAccountPurpose::OpeningBalanceEquity, AccountType::Equity);

        Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => "OB-SCOPE-CUST-{$suffix}",
            'type' => 'customer',
        ]);

        $batchService = app(OpeningBalanceBatchService::class);
        $batch = $batchService->createBatch(
            $company,
            OpeningBatchType::ArOpenItems,
            Carbon::parse('2026-01-01'),
            "Opening AR {$suffix}",
            (string) $user->id,
            'phpunit',
        );
        $batchService->addImportRows($batch, [[
            'partner_code' => "OB-SCOPE-CUST-{$suffix}",
            'external_invoice_number' => "LEGACY-{$suffix}-1",
            'document_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'total' => '100.000',
            'open_amount' => '100.000',
            'currency' => 'TND',
            'document_type' => 'invoice',
            'notes' => null,
        ]]);

        $arOpening = app(ArApOpeningService::class);
        $arOpening->validateBatch($batch->refresh());
        $arOpening->postBatch($batch->refresh(), (string) $user->id);

        $location = Location::create([
            'company_id' => $company->id,
            'code' => "WH-OB-SCOPE-{$suffix}",
            'name' => "Opening Scope Warehouse {$suffix}",
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sku' => "OB-SCOPE-PROD-{$suffix}",
            'name' => "Opening Scope Product {$suffix}",
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '0.000000',
        ]);

        app(OpeningBalancePostingService::class)->post(new OpeningBalancePosting(
            tenantId: $tenant->id,
            companyId: $company->id,
            userId: $user->id,
            entryDate: Carbon::parse('2026-01-01'),
            isHistorical: true,
            sourceType: 'opening_balance',
            sourceId: $product->id,
            reference: "Opening stock {$suffix}",
            notes: null,
            lines: [OpeningBalanceLine::make($product->id, null, $location->id, '10', '5.000', 3)],
        ));
    }

    private function createAccount(
        Tenant $tenant,
        Company $company,
        string $code,
        SystemAccountPurpose $purpose,
        AccountType $type,
    ): void {
        Account::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => $code,
            'name' => $purpose->value,
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => true,
            'is_system' => true,
        ]);
    }
}
