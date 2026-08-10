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

/**
 * Cross-seeder system-purpose parity (register G-4 / H-5 / E-1).
 *
 * The three chart-of-accounts seeders are the seeded country defaults for the
 * GL. A purpose seeded by ONE country and not the others is a silent
 * country-specific outage: every consumer resolves BY PURPOSE
 * ({@see Account::findByPurpose}), so the missing country either throws or —
 * worse, where the caller probes non-throwingly — books nothing at all
 * (France booked zero COGS forever: PostCOGSOnInvoice swallows the miss).
 *
 * This test is the guard the super-admin COA template library's publish gate
 * should inherit. Each exemption below is a DELIBERATE country difference with
 * a named owner; a lane that closes one must delete its exemption here.
 */
final class ChartOfAccountsPurposeParityTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountsService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ChartOfAccountsService::class);
        $this->tenant = Tenant::create([
            'name' => 'Parity Tenant',
            'slug' => 'parity-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    /**
     * Purposes a given country chart deliberately does NOT seed.
     *
     * @return array<string, list<SystemAccountPurpose>>
     */
    private function exemptions(): array
    {
        return [
            // The timbre (droit de timbre) collected on sales and remitted to
            // the State is a Tunisian obligation with no PCG / international
            // counterpart. Deliberately TN-only.
            'FR' => [SystemAccountPurpose::SalesStampDutyPayable],
            'XX' => [SystemAccountPurpose::SalesStampDutyPayable],

            // OWNED BY ANOTHER LANE — do not close these here. Each lane must
            // DELETE its entry below when it lands, or this guard keeps passing
            // green while TN stays four purposes short.
            //
            // H-2 (TN rounding dust must leave 4375) owns the two
            // rounding-difference purposes. CODE COLLISION WARNING for that
            // lane: `6588`/`7588` are NOT available — `6588` already exists and
            // already carries RoundingLossExpense in all three charts
            // (TunisiaChartOfAccountsSeeder / FranceChartOfAccountsSeeder /
            // GenericChartOfAccountsSeeder), under UNIQUE(company_id, code), so
            // seeding it again aborts the tenant run with a QueryException. H-2
            // picks the account; the FR and Generic charts already map this pair
            // to 6581/7581, which are free in the TN chart today.
            //
            // H-1 (TN chart re-numbered onto the PCN, where class 6 headers
            // differ from the PCG the seeder currently carries) owns the
            // class-6 FX pair, whose correct PCN parent is exactly what H-1
            // re-numbers.
            'TN' => [
                SystemAccountPurpose::SalesRoundingDifferenceExpense,
                SystemAccountPurpose::SalesRoundingDifferenceIncome,
                SystemAccountPurpose::RealizedFxGain,
                SystemAccountPurpose::RealizedFxLoss,
            ],
        ];
    }

    private function createCompany(string $countryCode): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parity Co '.uniqid(),
            'country_code' => $countryCode,
            'currency' => $countryCode === 'TN' ? 'TND' : 'EUR',
            'locale' => $countryCode === 'TN' ? 'fr_TN' : 'en_US',
            'timezone' => $countryCode === 'TN' ? 'Africa/Tunis' : 'UTC',
        ]);
    }

    private function seedChart(string $countryCode): Company
    {
        $company = $this->createCompany($countryCode);
        $this->service->seedForCompany($company);

        return $company;
    }

    /**
     * @return array{code: string, type: AccountType}
     */
    private function purposeAccount(Company $company, SystemAccountPurpose $purpose): array
    {
        $account = Account::findByPurpose($company->id, $purpose);
        $this->assertNotNull(
            $account,
            sprintf('%s chart must map %s', $company->country_code, $purpose->value),
        );

        return ['code' => $account->code, 'type' => $account->type];
    }

    public function test_every_country_chart_seeds_every_purpose_except_its_documented_exemptions(): void
    {
        $exemptions = $this->exemptions();

        foreach (['TN', 'FR', 'XX'] as $countryCode) {
            $company = $this->seedChart($countryCode);

            $seeded = Account::forCompany($company->id)
                ->whereNotNull('system_purpose')
                ->pluck('system_purpose')
                ->map(static fn (SystemAccountPurpose $purpose): string => $purpose->value)
                ->sort()
                ->values()
                ->all();

            $exempt = array_map(
                static fn (SystemAccountPurpose $purpose): string => $purpose->value,
                $exemptions[$countryCode] ?? [],
            );

            $expected = array_values(array_diff(
                array_map(
                    static fn (SystemAccountPurpose $purpose): string => $purpose->value,
                    SystemAccountPurpose::cases(),
                ),
                $exempt,
            ));
            sort($expected);

            $this->assertSame(
                $expected,
                $seeded,
                sprintf(
                    'Chart %s purpose parity drift. Missing: [%s]. Unexpected: [%s].',
                    $countryCode,
                    implode(', ', array_diff($expected, $seeded)),
                    implode(', ', array_diff($seeded, $expected)),
                ),
            );
        }
    }

    public function test_france_maps_cost_of_goods_sold_to_the_pcg_stock_variation_account(): void
    {
        $company = $this->seedChart('FR');

        $account = $this->purposeAccount($company, SystemAccountPurpose::CostOfGoodsSold);

        // PCG 603 "Variation des stocks (approvisionnements et marchandises)" —
        // the perpetual-inventory destocking charge, mirroring the TN chart's
        // own 603 mapping. NOT 607 (achats de marchandises), which already
        // carries PurchaseExpenses in this same chart.
        $this->assertSame('603', $account['code']);
        $this->assertSame(AccountType::Expense, $account['type']);
    }

    public function test_france_maps_general_expense(): void
    {
        $company = $this->seedChart('FR');

        $account = $this->purposeAccount($company, SystemAccountPurpose::GeneralExpense);

        $this->assertSame('628', $account['code']);
        $this->assertSame(AccountType::Expense, $account['type']);
    }

    public function test_france_maps_customer_and_supplier_advances(): void
    {
        $company = $this->seedChart('FR');

        $customerAdvance = $this->purposeAccount($company, SystemAccountPurpose::CustomerAdvance);
        $this->assertSame('419', $customerAdvance['code']);
        $this->assertSame(AccountType::Liability, $customerAdvance['type']);

        $supplierAdvance = $this->purposeAccount($company, SystemAccountPurpose::SupplierAdvance);
        $this->assertSame('409', $supplierAdvance['code']);
        $this->assertSame(AccountType::Asset, $supplierAdvance['type']);
    }

    public function test_france_chart_passes_required_purpose_validation(): void
    {
        $company = $this->seedChart('FR');

        $result = $this->service->validateCompanyAccounts($company->id);

        $this->assertTrue($result['valid'], 'FR missing: '.implode(', ', $result['missing_purposes']));
        $this->assertEmpty($result['missing_purposes']);
    }

    public function test_required_purposes_cover_cost_of_goods_sold_and_general_expense(): void
    {
        $required = SystemAccountPurpose::requiredPurposes();

        $this->assertContains(SystemAccountPurpose::CostOfGoodsSold, $required);
        $this->assertContains(SystemAccountPurpose::GeneralExpense, $required);
    }

    public function test_validation_catches_a_chart_missing_cost_of_goods_sold_or_general_expense(): void
    {
        $company = $this->seedChart('FR');

        Account::forCompany($company->id)
            ->whereIn('system_purpose', [
                SystemAccountPurpose::CostOfGoodsSold->value,
                SystemAccountPurpose::GeneralExpense->value,
            ])
            ->update(['system_purpose' => null]);

        $result = $this->service->validateCompanyAccounts($company->id);

        $this->assertFalse($result['valid']);
        $this->assertContains(SystemAccountPurpose::CostOfGoodsSold->value, $result['missing_purposes']);
        $this->assertContains(SystemAccountPurpose::GeneralExpense->value, $result['missing_purposes']);
    }

    public function test_validation_catches_a_chart_missing_the_advance_purposes(): void
    {
        // CustomerAdvance and SupplierAdvance were ALREADY in requiredPurposes()
        // (register H-5 states otherwise — erratum): the French chart therefore
        // failed validateCompanyAccounts() outright before 409/419 were mapped.
        $company = $this->seedChart('FR');

        Account::forCompany($company->id)
            ->whereIn('system_purpose', [
                SystemAccountPurpose::CustomerAdvance->value,
                SystemAccountPurpose::SupplierAdvance->value,
            ])
            ->update(['system_purpose' => null]);

        $result = $this->service->validateCompanyAccounts($company->id);

        $this->assertFalse($result['valid']);
        $this->assertContains(SystemAccountPurpose::CustomerAdvance->value, $result['missing_purposes']);
        $this->assertContains(SystemAccountPurpose::SupplierAdvance->value, $result['missing_purposes']);
    }

    public function test_tunisia_seeds_the_606_family_referenced_by_the_expense_categories(): void
    {
        $company = $this->seedChart('TN');

        foreach (['606', '6061', '6064'] as $code) {
            $this->assertDatabaseHas('accounts', [
                'company_id' => $company->id,
                'code' => $code,
            ]);
        }

        $utilities = $this->purposeAccount($company, SystemAccountPurpose::UtilitiesExpense);
        $this->assertSame('6061', $utilities['code']);

        $office = $this->purposeAccount($company, SystemAccountPurpose::OfficeExpense);
        $this->assertSame('6064', $office['code']);
    }

    public function test_tunisia_vat_on_bank_fees_is_parented_to_the_state_family(): void
    {
        $company = $this->seedChart('TN');

        $stateFamilyId = Account::forCompany($company->id)->where('code', '44')->value('id');
        $vatOnBankFees = Account::forCompany($company->id)->where('code', '43666')->first();

        $this->assertNotNull($vatOnBankFees);
        $this->assertSame(
            $stateFamilyId,
            $vatOnBankFees->parent_id,
            'TVA récupérable sur frais bancaires must sit under 44 (État et collectivités publiques), '
            .'like every other VAT account, not under 43 (Organismes sociaux).',
        );
    }

    public function test_france_and_tunisia_map_sales_discount_and_uninvoiced_revenue(): void
    {
        foreach (['FR' => '7097', 'TN' => '7097'] as $countryCode => $expectedCode) {
            $company = $this->seedChart($countryCode);

            $discount = $this->purposeAccount($company, SystemAccountPurpose::SalesDiscount);
            $this->assertSame($expectedCode, $discount['code']);
            $this->assertSame(AccountType::Expense, $discount['type']);

            $uninvoiced = $this->purposeAccount($company, SystemAccountPurpose::UninvoicedRevenue);
            $this->assertSame('418', $uninvoiced['code']);
            $this->assertSame(AccountType::Asset, $uninvoiced['type']);
        }
    }
}
