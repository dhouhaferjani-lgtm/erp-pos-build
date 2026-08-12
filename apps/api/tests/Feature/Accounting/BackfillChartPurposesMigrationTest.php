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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
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
#[UsesFrozenSeederFixture]
final class BackfillChartPurposesMigrationTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_10_090000_backfill_chart_purposes.php';

    /**
     * The token the deploy checklist greps for on the AUTOMATIC (tenants:migrate)
     * path. Distinct from the COMMAND's own `CHART-PURPOSE BACKFILL FAILURES:`
     * token, which the checklist greps on the manual `tenants:run` path — one
     * token per channel, so neither gate can be satisfied by the other's output.
     */
    private const GATE_TOKEN = 'CHART-PURPOSE BACKFILL MIGRATION:';

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
     * The deploy checklist's AUTOMATIC path greps the tenant log for evidence
     * that every tenant ran and none failed. Production sets
     * `LOG_LEVEL=warning` (`apps/api/.env.production.example:33`), which drops
     * `Log::info` entirely — so a gate line emitted at info level makes the
     * checklist pass on an EMPTY log (gate finding N-2). The gate line must
     * therefore be emitted at warning-or-above, carry the tenant key (N-4), and
     * state pass/fail without the reader having to parse a count.
     */
    public function test_it_emits_the_deploy_gate_line_at_warning_level_on_success(): void
    {
        $company = $this->frenchCompany();
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripLanePurposes($company->id);

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=ok'))
            ->once();
    }

    public function test_the_deploy_gate_line_reports_failure_when_a_chart_cannot_be_placed(): void
    {
        // A company with no chart at all: the command returns FAILURE, and the
        // gate line must say so at a level production keeps.
        $this->frenchCompany();

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=FAILED'))
            ->once();
    }

    /**
     * The migration's `catch (Throwable)` promises that "a tenant whose chart
     * cannot be repaired must not brick the whole unattended migration run".
     * On PostgreSQL, catching a `QueryException` is NOT enough to keep that
     * promise: the failed statement aborts the ENCLOSING transaction (the one
     * `migrate` wraps every migration in), so every later statement — including
     * the migration repository's own bookkeeping INSERT — fails with "current
     * transaction is aborted". The repair must therefore run inside a SAVEPOINT
     * that can be rolled back on its own.
     *
     * The throw is forced the way an unforeseen schema/state drift would cause
     * one: the column the backfill writes is removed, so its INSERT raises.
     */
    public function test_a_failing_backfill_does_not_poison_the_enclosing_migration_transaction(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Only PostgreSQL aborts the enclosing transaction after a failed statement; '
                .'this is the production driver and the hazard is PG-specific.',
            );
        }

        $company = $this->frenchCompany();
        (new FranceChartOfAccountsSeeder)->run($company->id, $this->tenant->id);
        $this->stripLanePurposes($company->id);

        DB::statement('ALTER TABLE accounts DROP COLUMN balance');

        // Must not throw — that part the catch already guarantees.
        $this->runMigration();

        // THE POINT: the connection must still be usable afterwards, or
        // `tenants:migrate` dies on the next statement for this tenant and,
        // depending on the runner, for every tenant after it.
        $this->assertSame(
            1,
            DB::table('companies')->where('id', $company->id)->count(),
            'the enclosing transaction must survive a failed backfill',
        );
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
