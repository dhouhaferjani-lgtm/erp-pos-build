<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    #[Test]
    #[DataProvider('countryProvider')]
    public function ppv_seeders_are_idempotent_when_ppv_accounts_already_exist(string $countryCode): void
    {
        $company = $this->seedCompany($countryCode);

        $this->service->seedForCompany($company);

        $this->assertSame(1, Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::PurchasePriceVarianceExpense->value)
            ->count());
        $this->assertSame(1, Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::PurchasePriceVarianceIncome->value)
            ->count());
    }

    #[Test]
    public function ppv_backfill_migration_restores_existing_tenant_accounts_and_case_a_posts(): void
    {
        $company = $this->seedCompany('TN');
        Account::query()
            ->where('company_id', $company->id)
            ->whereIn('system_purpose', [
                SystemAccountPurpose::PurchasePriceVarianceExpense->value,
                SystemAccountPurpose::PurchasePriceVarianceIncome->value,
            ])
            ->delete();

        $migrationPath = database_path('migrations/tenant/2026_07_04_120000_backfill_purchase_price_variance_accounts.php');
        $this->assertFileExists($migrationPath);
        $migration = require $migrationPath;
        $migration->up();
        $migration->up();

        $expense = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::PurchasePriceVarianceExpense);
        $income = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::PurchasePriceVarianceIncome);

        $this->assertSame('6585', $expense->code);
        $this->assertSame('7585', $income->code);
        $this->assertSame(1, Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::PurchasePriceVarianceExpense->value)
            ->count());
        $this->assertSame(1, Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::PurchasePriceVarianceIncome->value)
            ->count());

        $supplier = Partner::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'name' => 'PPV Backfill Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
        $invoice = Document::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'partner_id' => $supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-PPV-BACKFILL-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '520.000',
            'line_tax_amount' => '98.800',
            'stamp_duty_amount' => '0.000',
            'tax_amount' => '98.800',
            'total' => '618.800',
        ]);

        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $company->id,
            Str::uuid()->toString(),
            '100.0000',
            '5.200',
            'TND',
        );

        DB::transaction(fn () => app(GeneralLedgerService::class)->createSupplierInvoiceGrIrClearingEntry(
            $invoice,
            accruedHt: '520.000',
            billedHt: '520.000',
            recoverableVat: '98.800',
            nonRecoverableVat: '0.000',
            timbre: '0.000',
        ));

        $this->assertTrue(true);
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
