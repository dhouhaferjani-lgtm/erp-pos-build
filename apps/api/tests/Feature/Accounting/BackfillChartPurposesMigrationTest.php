<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

/**
 * SEEDS gate finding I-1 — `2026_08_10_090000_backfill_chart_purposes`.
 *
 * The lane widened `requiredPurposes()` and seeded FR `603`/`628`, but charts
 * are written once at provisioning: without an UNATTENDED repair, every
 * already-provisioned French company keeps booking zero COGS. This migration
 * delegates to `accounting:backfill-chart-purposes` so `tenants:migrate` (which
 * auto-runs on push) performs the same repair the command performs by hand.
 *
 * The migration is invoked directly rather than through `artisan migrate`
 * because `RefreshDatabase` has already run it against the empty schema; these
 * cases build the pre-migration chart shape by hand and then apply it.
 */
final class BackfillChartPurposesMigrationTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_10_090000_backfill_chart_purposes.php';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Chart Purpose Migration Tenant',
            'slug' => 'chart-purpose-migration-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function frenchCompany(): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Chart Purpose Co '.Str::random(5),
            'legal_name' => 'Chart Purpose Co SARL',
            'tax_id' => 'TAX-CPM-'.uniqid(),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
        ]);
    }

    /**
     * Recreate the PRE-lane French chart: the seeder now emits these purposes,
     * so they have to be stripped to reproduce an existing company's shape.
     */
    private function stripLanePurposes(string $companyId): void
    {
        DB::table('accounts')
            ->where('company_id', $companyId)
            ->whereIn('code', ['603', '628', '7097'])
            ->delete();

        DB::table('accounts')
            ->where('company_id', $companyId)
            ->whereIn('code', ['409', '418', '419'])
            ->update(['system_purpose' => null, 'is_system' => false]);
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/tenant/'.self::MIGRATION);
        $migration->up();
    }

    public function test_the_migration_repairs_a_pre_lane_french_chart_unattended(): void
    {
        $company = $this->frenchCompany();
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripLanePurposes($company->id);

        $this->assertNull(Account::findByPurpose($company->id, SystemAccountPurpose::CostOfGoodsSold));

        $this->runMigration();

        $cogs = Account::findByPurpose($company->id, SystemAccountPurpose::CostOfGoodsSold);
        $this->assertNotNull($cogs);
        $this->assertSame('603', $cogs->code);

        $general = Account::findByPurpose($company->id, SystemAccountPurpose::GeneralExpense);
        $this->assertNotNull($general);
        $this->assertSame('628', $general->code);

        foreach ([
            SystemAccountPurpose::SupplierAdvance->value => '409',
            SystemAccountPurpose::CustomerAdvance->value => '419',
            SystemAccountPurpose::UninvoicedRevenue->value => '418',
            SystemAccountPurpose::SalesDiscount->value => '7097',
        ] as $purpose => $code) {
            $account = Account::findByPurpose($company->id, SystemAccountPurpose::from($purpose));
            $this->assertNotNull($account, "purpose {$purpose} should resolve after the migration");
            $this->assertSame($code, $account->code);
        }
    }

    public function test_the_migration_is_idempotent_and_never_throws_on_a_chartless_company(): void
    {
        // A company with NO chart at all: the backfill reports missing parents
        // and returns FAILURE, which the migration must swallow rather than
        // abort every other tenant's migration run.
        $chartless = $this->frenchCompany();

        $company = $this->frenchCompany();
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripLanePurposes($company->id);

        $this->runMigration();
        $afterFirst = DB::table('accounts')->where('company_id', $company->id)->count();

        $this->runMigration();

        $this->assertSame($afterFirst, DB::table('accounts')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('accounts')->where('company_id', $chartless->id)->count());
    }

    /**
     * The migration's own `down()` is a DECLARED no-op (the backfilled accounts
     * may already carry posted journal lines, and unmapping would return French
     * tenants to zero COGS). Prove `down()` changes NOTHING rather than skip
     * rollback coverage.
     */
    public function test_the_backfill_declares_an_irreversible_no_op_down_via_the_round_trip_harness(): void
    {
        $company = $this->frenchCompany();
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripLanePurposes($company->id);
        $baselineCount = Account::query()->where('company_id', $company->id)->count();

        $this->assertTenantMigrationIsIrreversibleNoOp(
            self::MIGRATION,
            function (string $context) use ($company, $baselineCount): void {
                $cogs = Account::findByPurpose($company->id, SystemAccountPurpose::CostOfGoodsSold);
                $this->assertNotNull($cogs, "CostOfGoodsSold should resolve {$context}.");
                $this->assertSame('603', $cogs->code, "CostOfGoodsSold code should be 603 {$context}.");
                $this->assertSame(
                    $baselineCount + 3,
                    Account::query()->where('company_id', $company->id)->count(),
                    "account count should be baseline+3 (603/628/7097) {$context} — down() must not delete or unmap them.",
                );
            },
        );
    }
}
