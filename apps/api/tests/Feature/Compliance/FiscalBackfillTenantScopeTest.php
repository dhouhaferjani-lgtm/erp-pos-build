<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Commands\BackfillFiscalHashesCommand;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `fiscal:backfill` and `fiscal-years:backfill` — cat-(b) wave 2 conversion.
 *
 * Both used to enumerate `companies` (a TENANT table) from the console's
 * CENTRAL connection and call that the fleet; after the 2026-05-28
 * database-per-tenant flip they raised 42P01 and backfilled nothing.
 *
 * The contract these tests lock in is the one-shot-backfill rule: a fleet-wide
 * run must be asked for. A backfill that silently skips a tenant leaves
 * permanently wrong fiscal data behind with no signal, so neither an absent
 * scope nor an unreachable `--tenant` may be treated as "nothing to do".
 *
 * **`fiscal:backfill` is not registered by any service provider** (checked
 * 2026-08-05: `ComplianceServiceProvider` registers only
 * `VerifyFiscalChainsCommand`), so it cannot be reached from the CLI at all —
 * a finding the cat-(b) audit missed when it recorded "no class was found to
 * be DEAD". Wiring a schema-touching backfill into the CLI surface is out of
 * this wave's scope, so the tests below register the command into the test
 * kernel instead. See
 * `docs/superpowers/tickets/2026-08-05-cross-tenant-annotation-ast-check.md`.
 */
final class FiscalBackfillTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `fiscal:backfill` has no provider registration — register it here so
        // the conversion is exercised end-to-end through the console kernel.
        $this->app->make(ConsoleKernel::class)
            ->registerCommand($this->app->make(BackfillFiscalHashesCommand::class));
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function backfillCommandProvider(): array
    {
        return [
            'fiscal:backfill' => ['fiscal:backfill'],
            'fiscal-years:backfill' => ['fiscal-years:backfill'],
        ];
    }

    #[DataProvider('backfillCommandProvider')]
    public function test_it_refuses_to_run_without_an_explicit_scope(string $command): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->create(['tenant_id' => $tenant->id]);

        $this->artisan($command)
            ->expectsOutputToContain('Refusing to run without an explicit scope')
            ->assertExitCode(2);
    }

    #[DataProvider('backfillCommandProvider')]
    public function test_it_refuses_both_scopes_at_once(string $command): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan($command, ['--tenant' => $tenant->id, '--all-tenants' => true])
            ->expectsOutputToContain('mutually exclusive')
            ->assertExitCode(2);
    }

    #[DataProvider('backfillCommandProvider')]
    public function test_an_unknown_tenant_fails_loudly_instead_of_reporting_nothing_to_do(string $command): void
    {
        Tenant::factory()->create();

        $this->artisan($command, ['--tenant' => (string) Str::uuid()])
            ->expectsOutputToContain('not found in the central tenant directory')
            ->assertFailed();
    }

    // =================================================================
    // fiscal-years:backfill — behaviour
    // =================================================================

    public function test_fiscal_years_backfill_creates_years_only_for_the_selected_tenant(): void
    {
        $selected = Tenant::factory()->create();
        $selectedCompany = Company::factory()->create(['tenant_id' => $selected->id]);

        $other = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $other->id]);

        // A new company gets its fiscal years from a `created` model event, so
        // strip them to recreate the state this backfill exists to repair.
        DB::table('fiscal_years')->delete();

        $this->artisan('fiscal-years:backfill', ['--tenant' => $selected->id])
            ->assertExitCode(0);

        $this->assertGreaterThan(0, $this->fiscalYearCount((string) $selectedCompany->id));
        $this->assertSame(
            0,
            $this->fiscalYearCount((string) $otherCompany->id),
            'A tenant outside the named scope must not be touched by a one-shot backfill',
        );
    }

    public function test_fiscal_years_backfill_covers_every_tenant_under_all_tenants(): void
    {
        $a = Company::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $b = Company::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        DB::table('fiscal_years')->delete();

        $this->artisan('fiscal-years:backfill', ['--all-tenants' => true])
            ->assertExitCode(0);

        $this->assertGreaterThan(0, $this->fiscalYearCount((string) $a->id));
        $this->assertGreaterThan(0, $this->fiscalYearCount((string) $b->id));
    }

    /**
     * A company whose tenant_id has no row in the central directory is
     * unreachable by forEachTenant — no iteration slot ever opens for it — so
     * the pre-conversion fleet-wide `Company::doesntHave('fiscalYears')` query
     * would have swept it up and this one must not.
     */
    public function test_a_company_whose_tenant_is_absent_from_the_directory_is_never_backfilled(): void
    {
        $reachable = Company::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $orphan = Company::factory()->create(['tenant_id' => (string) Str::uuid()]);

        DB::table('fiscal_years')->delete();

        $this->artisan('fiscal-years:backfill', ['--all-tenants' => true])
            ->assertExitCode(0);

        $this->assertGreaterThan(0, $this->fiscalYearCount((string) $reachable->id));
        $this->assertSame(0, $this->fiscalYearCount((string) $orphan->id));
    }

    public function test_fiscal_years_backfill_fails_for_a_company_no_reachable_tenant_owns(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->create(['tenant_id' => $tenant->id]);

        $this->artisan('fiscal-years:backfill', [
            '--tenant' => $tenant->id,
            '--company' => (string) Str::uuid(),
        ])
            ->expectsOutputToContain('Company not found')
            ->assertExitCode(1);
    }

    // =================================================================
    // fiscal:backfill — behaviour
    // =================================================================

    public function test_fiscal_backfill_reports_per_tenant_and_succeeds_with_nothing_to_do(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->create(['tenant_id' => $tenant->id]);

        $this->artisan('fiscal:backfill', ['--tenant' => $tenant->id, '--force' => true])
            ->expectsOutputToContain(sprintf('TENANT %s (%s): no documents require backfill.', $tenant->id, $tenant->slug))
            ->assertExitCode(0);
    }

    public function test_fiscal_backfill_fails_when_the_selected_tenant_owns_no_company(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan('fiscal:backfill', ['--tenant' => $tenant->id, '--force' => true])
            ->expectsOutputToContain('No companies found.')
            ->assertExitCode(1);
    }

    private function fiscalYearCount(string $companyId): int
    {
        return DB::table('fiscal_years')->where('company_id', $companyId)->count();
    }
}
