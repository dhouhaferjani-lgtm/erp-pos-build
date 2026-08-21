<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

/**
 * O-27 — `2026_08_21_140000_backfill_chart_required_purposes_o27`.
 *
 * The same deploy that widens {@see SystemAccountPurpose::requiredPurposes()}
 * to the manifest's full REQUIRED set must also, unattended, fill the fourteen
 * purposes that widening newly measures. `tenants:migrate` runs on push, so
 * this migration is the repair channel and its GUARD LADDER is the contract:
 * chartless tenant -> quiet skip; already-complete chart -> no-op; unmappable
 * purpose -> logged and left visible, never guessed and never thrown.
 *
 * The migration is invoked directly rather than through `artisan migrate`
 * because `RefreshDatabase` has already run it against the empty schema; these
 * cases build the pre-migration chart shape by hand and then apply it.
 */
#[UsesFrozenSeederFixture]
final class BackfillChartRequiredPurposesO27MigrationTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_21_140000_backfill_chart_required_purposes_o27.php';

    private const GATE_TOKEN = 'CHART-PURPOSE O27 BACKFILL MIGRATION:';

    /**
     * A representative slice of the fourteen, one per repair shape: a PROMOTE
     * (the account row survives, the mapping does not) and a CREATE (the row is
     * gone entirely).
     *
     * @var array<string, string>
     */
    private const HOLED_PURPOSE_CODES = [
        'inventory' => '37',
        'goods_received_not_invoiced' => '408',
        'voucher_liability' => '4197',
        'purchase_stamp_duty' => '6354',
        'pos_tender_clearing' => '5810',
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the legacy frozen-seeder arm: it is the config default, it is
        // what built every brownfield chart, and it must not silently drift
        // onto the template arm.
        config(['country_defaults.provisioning_enabled' => false]);

        $this->tenant = Tenant::create([
            'name' => 'O27 Chart Migration Tenant',
            'slug' => 'o27-chart-migration-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function frenchCompany(): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'O27 Chart Co '.Str::random(5),
            'legal_name' => 'O27 Chart Co SARL',
            'tax_id' => 'TAX-O27-'.uniqid(),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
        ]);
    }

    /**
     * Provision through the SERVICE, not the bare seeder.
     * `ChartOfAccountsService::seedForCompany()` runs
     * `FranceChartOfAccountsSeeder` AND `InventoryVarianceAccounts::provisionCompany()`
     * in one transaction; `inventory_shrinkage_expense` comes from the second
     * half, so a chart built from the seeder alone is incomplete and would make
     * every "valid after repair" assertion below fail for a reason that has
     * nothing to do with this migration.
     */
    private function seededFrenchCompany(): Company
    {
        $company = $this->frenchCompany();
        app(ChartOfAccountsService::class)->seedForCompany($company);

        return $company;
    }

    /**
     * Reproduce a brownfield chart provisioned before these purposes existed:
     * two of them lose their account row outright, the rest keep the row but
     * lose the mapping.
     */
    private function holeTheO27Purposes(string $companyId): void
    {
        DB::table('accounts')
            ->where('company_id', $companyId)
            ->whereIn('code', ['4197', '5810'])
            ->delete();

        DB::table('accounts')
            ->where('company_id', $companyId)
            ->whereIn('code', ['37', '408', '6354'])
            ->update(['system_purpose' => null, 'is_system' => false]);
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/tenant/'.self::MIGRATION);
        $migration->up();
    }

    private function validation(string $companyId): array
    {
        return app(ChartOfAccountsService::class)->validateCompanyAccounts($companyId);
    }

    public function test_it_repairs_a_brownfield_chart_holed_on_the_o27_purposes_unattended(): void
    {
        $company = $this->seededFrenchCompany();
        $this->holeTheO27Purposes($company->id);

        $before = $this->validation($company->id);
        $this->assertFalse($before['valid'], 'The holed chart must be reported unhealthy by the widened validation.');
        foreach (array_keys(self::HOLED_PURPOSE_CODES) as $purpose) {
            $this->assertContains($purpose, $before['missing_purposes']);
        }

        $this->runMigration();

        $after = $this->validation($company->id);
        $this->assertTrue($after['valid'], 'Still missing after the migration: '.implode(', ', $after['missing_purposes']));

        foreach (self::HOLED_PURPOSE_CODES as $purpose => $code) {
            $account = Account::findByPurpose($company->id, SystemAccountPurpose::from($purpose));
            $this->assertNotNull($account, sprintf('Purpose %s should resolve after the migration.', $purpose));
            $this->assertSame($code, $account->code, sprintf(
                'Purpose %s must land on the FR chart\'s own account code.',
                $purpose,
            ));
        }
    }

    /**
     * GUARD 1 — a tenant with no chart at all. The backfill reports missing
     * parents and returns FAILURE; the migration must swallow it, invent
     * nothing, and let every other tenant's run continue.
     */
    public function test_a_chartless_company_is_skipped_without_throwing_or_inventing_accounts(): void
    {
        $chartless = $this->frenchCompany();

        $this->runMigration();

        $this->assertSame(0, DB::table('accounts')->where('company_id', $chartless->id)->count());
    }

    /**
     * GUARD 2 — a complete chart is a no-op, and running twice changes nothing.
     */
    public function test_an_already_complete_chart_is_a_no_op_and_the_migration_is_idempotent(): void
    {
        $company = $this->seededFrenchCompany();
        $this->assertTrue($this->validation($company->id)['valid']);

        $baseline = DB::table('accounts')->where('company_id', $company->id)->count();

        $this->runMigration();
        $this->assertSame($baseline, DB::table('accounts')->where('company_id', $company->id)->count());

        $this->runMigration();
        $this->assertSame($baseline, DB::table('accounts')->where('company_id', $company->id)->count());
        $this->assertTrue($this->validation($company->id)['valid']);
    }

    /**
     * GUARD 3 — the residual. `customer_receivable` is manifest-REQUIRED and
     * the backfill has NO definition for it (it is one of the ten purposes
     * `requiredPurposes()` already checked before this lane). A chart missing
     * it must be NAMED, must not be guessed at, must not throw, and must be
     * left visibly failing validation rather than silently certified.
     */
    public function test_a_required_purpose_with_no_mapping_is_logged_and_left_visibly_failing(): void
    {
        $company = $this->seededFrenchCompany();

        DB::table('accounts')
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::CustomerReceivable->value)
            ->delete();

        Log::spy();

        // Never throws.
        $this->runMigration();

        // Never guessed: no account was invented to carry the purpose.
        $this->assertNull(Account::findByPurpose($company->id, SystemAccountPurpose::CustomerReceivable));

        // Left visible: the operator sees the real state, not a clean bill.
        $after = $this->validation($company->id);
        $this->assertFalse($after['valid']);
        $this->assertContains(SystemAccountPurpose::CustomerReceivable->value, $after['missing_purposes']);

        // And the run itself is still reported as a successful repair pass —
        // the residual is not a backfill FAILURE, it is a chart the backfill
        // was never given a mapping for.
        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=ok'))
            ->once();
    }

    /**
     * The deploy checklist greps the tenant log. Production runs
     * `LOG_LEVEL=warning`, which drops `Log::info` entirely, so a gate line at
     * info level makes the grep pass on an EMPTY log. It must be warning-or-above,
     * carry the tenant key, and state pass/fail without parsing a count.
     */
    public function test_it_emits_the_deploy_gate_line_at_warning_level_on_success(): void
    {
        $company = $this->seededFrenchCompany();
        $this->holeTheO27Purposes($company->id);

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=ok'))
            ->once();
    }

    public function test_the_deploy_gate_line_reports_failure_when_a_chart_cannot_be_placed(): void
    {
        $this->frenchCompany();

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=FAILED'))
            ->once();
    }

    /**
     * On PostgreSQL, catching a `QueryException` is not enough to keep the
     * "must not brick the whole run" promise: the failed statement aborts the
     * ENCLOSING transaction `migrate` wraps every migration in, so every later
     * statement — including the migration repository's bookkeeping INSERT —
     * fails with "current transaction is aborted". The repair must run inside a
     * SAVEPOINT that can be rolled back on its own.
     */
    public function test_a_failing_backfill_does_not_poison_the_enclosing_migration_transaction(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Only PostgreSQL aborts the enclosing transaction after a failed statement; '
                .'this is the production driver and the hazard is PG-specific.',
            );
        }

        $company = $this->seededFrenchCompany();
        $this->holeTheO27Purposes($company->id);

        DB::statement('ALTER TABLE accounts DROP COLUMN balance');

        $this->runMigration();

        $this->assertSame(
            1,
            DB::table('companies')->where('id', $company->id)->count(),
            'the enclosing transaction must survive a failed backfill',
        );
    }

    /**
     * `down()` is a DECLARED no-op: the backfilled accounts may already carry
     * posted journal lines, and unmapping would return tenants to the state
     * where a chart that cannot receive goods reports itself healthy. Prove it
     * changes nothing rather than skip rollback coverage.
     */
    public function test_the_backfill_declares_an_irreversible_no_op_down_via_the_round_trip_harness(): void
    {
        $company = $this->seededFrenchCompany();
        $this->holeTheO27Purposes($company->id);
        $baselineCount = Account::query()->where('company_id', $company->id)->count();

        $this->assertTenantMigrationIsIrreversibleNoOp(
            self::MIGRATION,
            function (string $context) use ($company, $baselineCount): void {
                $inventory = Account::findByPurpose($company->id, SystemAccountPurpose::Inventory);
                $this->assertNotNull($inventory, "Inventory should resolve {$context}.");
                $this->assertSame('37', $inventory->code, "Inventory code should be 37 {$context}.");
                $this->assertSame(
                    $baselineCount + 2,
                    Account::query()->where('company_id', $company->id)->count(),
                    "account count should be baseline+2 (4197/5810 recreated) {$context} — down() must not delete or unmap them.",
                );
            },
        );
    }
}
