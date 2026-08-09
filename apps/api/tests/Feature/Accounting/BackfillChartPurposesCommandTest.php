<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Console\Commands\BackfillChartPurposesCommand;
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
     * A brownfield French chart as the seeder left it before this lane: the
     * class headers and the advance accounts exist, but no COGS / general
     * expense / discount account and no purpose on 409/418/419.
     *
     * @return array<string, string>
     */
    private function seedBrownfieldFrenchChart(Company $company): array
    {
        return [
            '40' => $this->seedAccount($company, '40', 'liability'),
            '41' => $this->seedAccount($company, '41', 'asset'),
            '60' => $this->seedAccount($company, '60', 'expense'),
            '62' => $this->seedAccount($company, '62', 'expense'),
            '70' => $this->seedAccount($company, '70', 'revenue'),
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
        $customerParent = $this->seedAccount($company, '41', 'asset');
        $revenueParent = $this->seedAccount($company, '70', 'revenue');
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
        // Only the third-party headers: no 60 / 62 / 70 to hang the new accounts on.
        $this->seedAccount($company, '40', 'liability');
        $this->seedAccount($company, '41', 'asset');

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutputToContain(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 3')
            ->assertFailed();

        $this->assertSame(0, DB::table('accounts')->where('company_id', $company->id)->where('code', '603')->count());
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
            ->expectsOutputToContain(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();

        $this->assertSame($countAfterFirst, DB::table('accounts')->where('company_id', $company->id)->count());
    }

    public function test_it_skips_a_soft_deleted_company(): void
    {
        $company = $this->makeCompany('FR', '8');
        $this->seedBrownfieldFrenchChart($company);
        $company->delete();

        $this->artisan('accounting:backfill-chart-purposes')
            ->expectsOutputToContain(BackfillChartPurposesCommand::SUMMARY_TOKEN_PREFIX.' 0')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('accounts')->where('company_id', $company->id)->where('code', '603')->count());
    }
}
