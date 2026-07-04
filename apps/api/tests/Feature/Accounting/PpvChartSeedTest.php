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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PpvChartSeedTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ChartOfAccountsService::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function countryProvider(): iterable
    {
        yield 'tunisia' => ['TN'];
        yield 'france' => ['FR'];
        yield 'generic' => ['US'];
    }

    #[Test]
    #[DataProvider('countryProvider')]
    public function ppv_accounts_are_seeded_with_system_purposes(string $countryCode): void
    {
        $company = $this->seedCompany($countryCode);

        $expense = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::PurchasePriceVarianceExpense);
        $income = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::PurchasePriceVarianceIncome);

        $this->assertSame('6585', $expense->code);
        $this->assertSame('Écart sur prix d\'achat', $expense->name);
        $this->assertSame(AccountType::Expense, $expense->type);
        $this->assertTrue($expense->is_system);

        $this->assertSame('7585', $income->code);
        $this->assertSame('Écart sur prix d\'achat', $income->name);
        $this->assertSame(AccountType::Revenue, $income->type);
        $this->assertTrue($income->is_system);
    }

    private function seedCompany(string $countryCode): Company
    {
        $tenant = Tenant::create([
            'name' => 'PPV Test Tenant '.$countryCode,
            'slug' => 'ppv-test-'.strtolower($countryCode).'-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'PPV Test Company '.$countryCode,
            'country_code' => $countryCode,
            'currency' => $countryCode === 'US' ? 'USD' : 'TND',
            'locale' => $countryCode === 'FR' ? 'fr_FR' : 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $this->service->seedForCompany($company);

        return $company;
    }
}
