<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrIrChartSeedTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ChartOfAccountsService::class);
    }

    /**
     * Create a tenant + TN company and seed the Tunisia chart of accounts.
     *
     * @return array{string, Company}
     */
    private function seedTenantWithTunisiaChart(): array
    {
        $tenant = Tenant::create([
            'name' => 'GR-IR Test Tenant',
            'slug' => 'grir-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'GR-IR Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $this->service->seedForCompany($company);

        return [$company->id, $company];
    }

    public function test_grir_and_timbre_accounts_are_purpose_mapped(): void
    {
        [$companyId] = $this->seedTenantWithTunisiaChart();

        $grir = Account::findByPurpose($companyId, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $this->assertNotNull($grir);
        $this->assertSame('408', $grir->code);
        $this->assertSame(AccountType::Liability, $grir->type);

        $timbre = Account::findByPurpose($companyId, SystemAccountPurpose::PurchaseStampDuty);
        $this->assertNotNull($timbre);
        $this->assertSame(AccountType::Expense, $timbre->type);
    }
}
