<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Console\Commands\BackfillChartPurposesCommand;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `accounting:backfill-chart-purposes` (register E-1/H-5/G-4).
 *
 * The chart of accounts is built by the per-country COA seeders, not by a
 * migration, so `accounts` is EMPTY after RefreshDatabase's migrate:fresh and
 * every test seeds exactly the brownfield chart rows it reasons about.
 */
final class BackfillChartPurposesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Chart Backfill Tenant',
            'slug' => 'chart-backfill-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function makeCompany(string $countryCode, string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Chart Co '.$suffix,
            'legal_name' => 'Chart Co '.$suffix.' SARL',
            'tax_id' => 'TAX-CB-'.$suffix,
            'country_code' => $countryCode,
            'locale' => $countryCode === 'TN' ? 'fr_TN' : 'fr_FR',
            'timezone' => $countryCode === 'TN' ? 'Africa/Tunis' : 'Europe/Paris',
            'currency' => $countryCode === 'TN' ? 'TND' : 'EUR',
        ]);
    }

    private function seedAccount(
        Company $company,
        string $code,
        string $type,
        ?string $parentId = null,
        ?string $purpose = null,
        bool $isActive = true,
    ): string {
        $id = (string) Str::uuid();
        DB::table('accounts')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => 'Account '.$code,
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => $isActive,
            'is_system' => true,
            'balance' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * The French-plan class headers every definition in the command hangs off.
     *
     * The types mirror `FranceChartOfAccountsSeeder` / `TunisiaChartOfAccountsSeeder`.
     * `58` (Virements internes) exists only on the French chart — TN roots
     * `5810` directly on class `5` — so both are seeded and each country's
     * definitions pick the one they name.
     *
     * @param  list<string>  $except  Header codes to leave OUT (missing-parent cases).
     * @return array<string, string>
     */
    private function seedFrenchPlanHeaders(Company $company, array $except = []): array
    {
        $headers = [
            '3' => 'asset',
            '40' => 'liability',
            '41' => 'asset',
            '5' => 'asset',
            '58' => 'asset',
            '60' => 'expense',
            '62' => 'expense',
            '63' => 'expense',
            '65' => 'expense',
            '70' => 'revenue',
            '75' => 'revenue',
        ];

        $ids = [];
        foreach ($headers as $code => $type) {
            // PHP silently casts numeric-string array keys to int.
            $code = (string) $code;
            if (in_array($code, $except, true)) {
                continue;
            }
            $ids[$code] = $this->seedAccount($company, $code, $type);
        }

        return $ids;
    }

    /**
     * A brownfield French chart as the seeder left it before this lane: the
     * class headers and the advance accounts exist, but no COGS / general
     * expense / discount account and no purpose on 409/418/419.
     *
     * @param  list<string>  $exceptHeaders
     * @return array<string, string>
     */
    private function seedBrownfieldFrenchChart(Company $company, array $exceptHeaders = []): array
    {
        return $this->seedFrenchPlanHeaders($company, $exceptHeaders) + [
            '409' => $this->seedAccount($company, '409', 'asset'),
            '418' => $this->seedAccount($company, '418', 'asset'),
            '419' => $this->seedAccount($company, '419', 'liability'),
        ];
    }

    public function test_it_creates_the_missing_french_cogs_and_general_expense_accounts(): void
    {
        $company = $this->makeCompany('FR', '1');
        $parents = $this->seedBrownfieldFrenchChart($company);

        $this->artisan('accounting:backfill-chart-purposes')->assertSuccessful();

        $cogs = DB::table('accounts')->where('company_id', $company->id)->where('code', '603')->first();
        $this->assertNotNull($cogs);
        $this->assertSame(SystemAccountPurpose::CostOfGoodsSold->value, $cogs->system_purpose);
        $this->assertSame('expense', $cogs->type);
        $this->assertSame($parents['60'], $cogs->parent_id);
        $this->assertTrue((bool) $cogs->is_system);

        $general = DB::table('accounts')->where('company_id', $company->id)->where('code', '628')->first();
        $this->assertNotNull($general);
        $this->assertSame(SystemAccountPurpose::GeneralExpense->value, $general->system_purpose);
        $this->assertSame($parents['62'], $general->parent_id);
    }

    public function test_it_promotes_the_existing_french_advance_accounts_in_place(): void
    {
        $company = $this->makeCompany('FR', '2');
        $parents = $this->seedBrownfieldFrenchChart($company);

        $this->artisan('accounting:backfill-chart-purposes')->assertSuccessful();

        $supplierAdvance = DB::table('accounts')->where('id', $parents['409'])->first();
        $this->assertNotNull($supplierAdvance);
        $this->assertSame(SystemAccountPurpose::SupplierAdvance->value, $supplierAdvance->system_purpose);

        $customerAdvance = DB::table('accounts')->where('id', $parents['419'])->first();
        $this->assertNotNull($customerAdvance);
        $this->assertSame(SystemAccountPurpose::CustomerAdvance->value, $customerAdvance->system_purpose);

        $uninvoiced = DB::table('accounts')->where('id', $parents['418'])->first();
        $this->assertNotNull($uninvoiced);
        $this->assertSame(SystemAccountPurpose::UninvoicedRevenue->value, $uninvoiced->system_purpose);

        // No duplicate rows were created for the promoted codes.
        $this->assertSame(1, DB::table('accounts')->where('company_id', $company->id)->where('code', '419')->count());
    }

    public function test_it_backfills_the_tunisian_parity_accounts_only(): void
    {
        $company = $this->makeCompany('TN', '3');
        // `65` doubles as the TN general-expense holder, so it is seeded with
        // the purpose already on it rather than through the header helper.
        $headers = $this->seedFrenchPlanHeaders($company, ['65']);
        $customerParent = $headers['41'];
        $revenueParent = $headers['70'];
        // TN already maps COGS and general expense on its own codes.
        $this->seedAccount($company, '603', 'expense', null, SystemAccountPurpose::CostOfGoodsSold->value);
        $this->seedAccount($company, '65', 'expense', null, SystemAccountPurpose::GeneralExpense->value);

        $this->artisan('accounting:backfill-chart-purposes')->assertSuccessful();

        $uninvoiced = DB::table('accounts')->where('company_id', $company->id)->where('code', '418')->first();
        $this->assertNotNull($uninvoiced);
        $this->assertSame(SystemAccountPurpose::UninvoicedRevenue->value, $uninvoiced->system_purpose);
        $this->assertSame($customerParent, $uninvoiced->parent_id);

        $discount = DB::table('accounts')->where('company_id', $company->id)->where('code', '7097')->first();
        $this->assertNotNull($discount);
        $this->assertSame(SystemAccountPurpose::SalesDiscount->value, $discount->system_purpose);
        $this->assertSame($revenueParent, $discount->parent_id);
        $this->assertSame('expense', $discount->type);
    }

    public function test_it_leaves_a_chart_that_already_carries_the_purpose_on_another_code_untouched(): void
    {
        $company = $this->makeCompany('FR', '4');
        $this->seedBrownfieldFrenchChart($company);
        // A brownfield chart that mapped COGS onto the finer PCG grain 6037.
        $legacy = $this->seedAccount($company, '6037', 'expense', null, SystemAccountPurpose::CostOfGoodsSold->value);

        $this->artisan('accounting:backfill-chart-purposes')->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('accounts')->where('company_id', $company->id)->where('code', '603')->count(),
            'PURPOSE FIRST: an already-resolving chart must not gain a second holder of the same purpose.',
        );
        $this->assertSame(
            SystemAccountPurpose::CostOfGoodsSold->value,
            DB::table('accounts')->where('id', $legacy)->value('system_purpose'),
        );
    }

    public function test_it_reports_a_missing_parent_instead_of_inventing_one(): void
    {
        $company = $this->makeCompany('FR', '5');
        // A chart complete but for the `60` (Achats) class header. Exactly the
        // two definitions rooted there — `603` (COGS) and `607` (purchase
        // expenses) — must be reported and neither invented; every other
        // definition still places normally, which is what makes this a
        // missing-PARENT case rather than a broken-chart case.
        $this->seedBrownfieldFrenchChart($company, ['60']);

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutput(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 2')
            ->assertFailed();

        $this->assertSame(0, DB::table('accounts')->where('company_id', $company->id)->where('code', '603')->count());
        $this->assertSame(0, DB::table('accounts')->where('company_id', $company->id)->where('code', '607')->count());
        // The rest of the chart was still repaired.
        $this->assertSame(1, DB::table('accounts')->where('company_id', $company->id)->where('code', '628')->count());
    }

    /**
     * A NULL `parent_code` is the seeder's own "this is a chart root", not an
     * unknown parent. The generic chart declares `5810` (POS tender clearing)
     * that way, so the create path must insert a root row instead of reporting
     * a missing parent — the one shape the French-plan arm never exercises.
     */
    public function test_it_creates_a_root_account_when_the_definition_declares_no_parent(): void
    {
        $company = $this->makeCompany('XX', '13');
        foreach (['3000' => 'asset', '4000' => 'liability', '6000' => 'expense', '7000' => 'revenue'] as $code => $type) {
            $this->seedAccount($company, (string) $code, $type);
        }

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutput(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();

        $posTenderClearing = DB::table('accounts')
            ->where('company_id', $company->id)
            ->where('code', '5810')
            ->first();

        $this->assertNotNull($posTenderClearing);
        $this->assertNull($posTenderClearing->parent_id, 'The generic chart roots 5810; it must not be re-parented.');
        $this->assertSame(SystemAccountPurpose::PosTenderClearing->value, $posTenderClearing->system_purpose);
        $this->assertSame('asset', $posTenderClearing->type);
    }

    /**
     * O-27: the generic chart is backfilled too. The original arms deliberately
     * had no generic definitions ("the generic chart already mapped them"),
     * which holds for the chart as seeded TODAY and not for a chart provisioned
     * before those rows existed.
     */
    public function test_it_backfills_the_generic_chart_for_the_o27_purposes(): void
    {
        $company = $this->makeCompany('XX', '14');
        foreach (['3000' => 'asset', '4000' => 'liability', '6000' => 'expense', '7000' => 'revenue'] as $code => $type) {
            $this->seedAccount($company, (string) $code, $type);
        }

        $this->artisan('accounting:backfill-chart-purposes')->assertSuccessful();

        foreach ([
            '3700' => SystemAccountPurpose::Inventory,
            '4080' => SystemAccountPurpose::GoodsReceivedNotInvoiced,
            '4197' => SystemAccountPurpose::VoucherLiability,
            '6070' => SystemAccountPurpose::PurchaseExpenses,
            '6350' => SystemAccountPurpose::PurchaseStampDuty,
            '7091' => SystemAccountPurpose::SalesDiscount,
            '7092' => SystemAccountPurpose::SalesReturnsClearing,
        ] as $code => $purpose) {
            $account = DB::table('accounts')->where('company_id', $company->id)->where('code', $code)->first();
            $this->assertNotNull($account, sprintf('Generic chart account %s was not created.', $code));
            $this->assertSame($purpose->value, $account->system_purpose);
        }
    }

    /**
     * O-27 skip-and-report: a manifest-REQUIRED purpose this command has no
     * definition for is NAMED, counted on its own token, and never guessed —
     * and it must not contaminate the FAILURES gate or the exit code.
     */
    public function test_it_reports_required_purposes_it_has_no_mapping_for_without_failing_the_gate(): void
    {
        $company = $this->makeCompany('FR', '15');
        $this->seedBrownfieldFrenchChart($company);

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutputToContain('does not map REQUIRED purpose '.SystemAccountPurpose::CustomerReceivable->value)
            ->expectsOutput(BackfillChartPurposesCommand::UNMAPPED_TOKEN_PREFIX.' 10')
            ->expectsOutput(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();

        // Never guessed: no account was invented for the unmapped purposes.
        $this->assertSame(
            0,
            DB::table('accounts')
                ->where('company_id', $company->id)
                ->where('system_purpose', SystemAccountPurpose::CustomerReceivable->value)
                ->count(),
        );
    }

    /**
     * The residual is a fact about the CHART, not about the country: a purpose
     * the command has no definition for stops being reported the moment the
     * chart resolves it, whatever code carries it.
     */
    public function test_an_unmapped_required_purpose_is_not_reported_once_the_chart_resolves_it(): void
    {
        $company = $this->makeCompany('FR', '16');
        $this->seedBrownfieldFrenchChart($company);
        $this->seedAccount($company, '411', 'asset', null, SystemAccountPurpose::CustomerReceivable->value);

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutput(BackfillChartPurposesCommand::UNMAPPED_TOKEN_PREFIX.' 9')
            ->assertSuccessful();
    }

    public function test_it_refuses_an_account_at_the_canonical_code_with_the_wrong_type(): void
    {
        $company = $this->makeCompany('FR', '9');
        $this->seedBrownfieldFrenchChart($company);
        // A chart where 603 was hand-created as a revenue account.
        $wrongType = $this->seedAccount($company, '603', 'revenue');

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutput(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 1')
            ->assertFailed();

        $this->assertNull(DB::table('accounts')->where('id', $wrongType)->value('system_purpose'));
        $this->assertNull(Account::findByPurpose($company->id, SystemAccountPurpose::CostOfGoodsSold));
    }

    public function test_it_refuses_an_inactive_account_at_the_canonical_code(): void
    {
        $company = $this->makeCompany('FR', '10');
        $this->seedBrownfieldFrenchChart($company);
        $inactive = $this->seedAccount($company, '628', 'expense', null, null, false);

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutput(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 1')
            ->assertFailed();

        $this->assertNull(DB::table('accounts')->where('id', $inactive)->value('system_purpose'));
    }

    public function test_it_refuses_to_repurpose_an_account_that_already_carries_another_purpose(): void
    {
        $company = $this->makeCompany('FR', '11');
        $this->seedBrownfieldFrenchChart($company);
        // 628 already used for something else by an operator.
        $taken = $this->seedAccount(
            $company,
            '628',
            'expense',
            null,
            SystemAccountPurpose::MarketingGoodwillExpense->value,
        );

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutput(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 1')
            ->assertFailed();

        $this->assertSame(
            SystemAccountPurpose::MarketingGoodwillExpense->value,
            DB::table('accounts')->where('id', $taken)->value('system_purpose'),
        );
        $this->assertNull(Account::findByPurpose($company->id, SystemAccountPurpose::GeneralExpense));
    }

    public function test_it_refuses_a_holder_of_the_purpose_that_has_the_wrong_type(): void
    {
        $company = $this->makeCompany('FR', '12');
        $this->seedBrownfieldFrenchChart($company);
        // PURPOSE-FIRST branch: the purpose resolves, but onto a revenue account.
        $badHolder = $this->seedAccount(
            $company,
            '6037',
            'revenue',
            null,
            SystemAccountPurpose::CostOfGoodsSold->value,
        );

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutput(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 1')
            ->assertFailed();

        // Neither repaired nor duplicated — reported for an operator.
        $this->assertSame(0, DB::table('accounts')->where('company_id', $company->id)->where('code', '603')->count());
        $this->assertSame(
            SystemAccountPurpose::CostOfGoodsSold->value,
            DB::table('accounts')->where('id', $badHolder)->value('system_purpose'),
        );
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $company = $this->makeCompany('FR', '6');
        $this->seedBrownfieldFrenchChart($company);

        $this->artisan('accounting:backfill-chart-purposes', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('accounts')->where('company_id', $company->id)->where('code', '603')->count());
        $this->assertNull(DB::table('accounts')->where('company_id', $company->id)->where('code', '419')->value('system_purpose'));
    }

    public function test_it_is_idempotent(): void
    {
        $company = $this->makeCompany('FR', '7');
        $this->seedBrownfieldFrenchChart($company);

        $this->artisan('accounting:backfill-chart-purposes')->assertSuccessful();
        $countAfterFirst = DB::table('accounts')->where('company_id', $company->id)->count();

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutput(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();

        $this->assertSame($countAfterFirst, DB::table('accounts')->where('company_id', $company->id)->count());
    }

    public function test_it_skips_a_soft_deleted_company(): void
    {
        $company = $this->makeCompany('FR', '8');
        $this->seedBrownfieldFrenchChart($company);
        $company->delete();

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutput(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('accounts')->where('company_id', $company->id)->where('code', '603')->count());
    }
}
