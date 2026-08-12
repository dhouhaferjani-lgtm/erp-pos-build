<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Application\Services\CountryTemplateResolver;
use App\Modules\CountryDefaults\Infrastructure\Seeders\TemplateChartOfAccountsSeeder;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Mockery\LegacyMockInterface;
use RuntimeException;
use Tests\TestCase;

final class SeedChartsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The seven portfolio/fee codes Phase-2 chart provisioning must add for TN
     * (treasury-phase2-deploy-checklist.md §2, Tunisia column).
     *
     * @var list<string>
     */
    private const TN_REQUIRED_CODES = ['5312', '413', '5313', '5314', '6275', '43666', '416'];

    /**
     * Codes only the Tunisia chart defines. `413`/`416`/`627` are shared with the Generic
     * chart, so asserting those alone cannot tell the two locales apart.
     *
     * @var list<string>
     */
    private const TN_ONLY_CODES = ['5312', '5313', '5314', '6275', '43666'];

    /**
     * The Generic chart's counterparts to TN_ONLY_CODES (deploy checklist §2, Generic column).
     *
     * @var list<string>
     */
    private const GENERIC_ONLY_CODES = ['5112', '5113', '5114', '44566'];

    public function test_dry_run_previews_without_writing_accounts(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        self::assertSame(0, Account::query()->count());

        $this->command('accounting:seed-charts', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertSuccessful();

        self::assertSame(0, Account::query()->count(), 'dry-run must not persist accounts');
    }

    public function test_seeds_all_seven_phase2_codes_and_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('accounting:seed-charts')->assertSuccessful();
        $firstPass = Account::query()->where('company_id', $company->id)->count();
        self::assertGreaterThan(0, $firstPass);

        foreach (self::TN_REQUIRED_CODES as $code) {
            self::assertTrue(
                Account::query()->where('company_id', $company->id)->where('code', $code)->exists(),
                "Phase-2 required TN chart code {$code} must resolve after provisioning.",
            );
        }

        // Second apply is command-level idempotent: zero creations, count stable.
        // Assert the FULL summary marker — a bare '0 created' substring also matches
        // '10 created', so it would not catch dishonest reporting.
        $this->command('accounting:seed-charts')
            ->expectsOutputToContain('Chart provisioning: 0 created, 0 promoted, 0 reparented across 1 company/companies')
            ->assertSuccessful();
        self::assertSame($firstPass, Account::query()->where('company_id', $company->id)->count());
    }

    public function test_non_tn_company_gets_the_generic_chart(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id, 'country_code' => 'US']);

        $this->command('accounting:seed-charts')->assertSuccessful();

        self::assertGreaterThan(0, Account::query()->where('company_id', $company->id)->count());
        // Generic chart still carries the effects-receivable / doubtful-receivable codes.
        self::assertTrue(Account::query()->where('company_id', $company->id)->where('code', '413')->exists());
        self::assertTrue(Account::query()->where('company_id', $company->id)->where('code', '416')->exists());

        // 413/416 are SHARED with the Tunisia chart, so they cannot prove the locale
        // resolver picked Generic. Discriminate on the codes only one chart defines.
        foreach (self::GENERIC_ONLY_CODES as $code) {
            self::assertTrue(
                Account::query()->where('company_id', $company->id)->where('code', $code)->exists(),
                "Generic-only chart code {$code} must resolve for a non-TN company.",
            );
        }

        foreach (self::TN_ONLY_CODES as $code) {
            self::assertFalse(
                Account::query()->where('company_id', $company->id)->where('code', $code)->exists(),
                "TN-only chart code {$code} must NOT be provisioned for a non-TN company.",
            );
        }
    }

    public function test_apply_reports_promotions_and_reparents_honestly(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('accounting:seed-charts')->assertSuccessful();

        // Drift both directory-owned fields the command claims to report on. The seeder
        // promotes is_system false->true (never demotes) and re-issues parent links, so a
        // re-apply must repair BOTH — and say so.
        $promotable = Account::query()->where('company_id', $company->id)
            ->where('code', '413')->firstOrFail();
        $promotable->update(['is_system' => false]);

        $reparentable = Account::query()->where('company_id', $company->id)
            ->where('code', '416')->firstOrFail();
        self::assertNotNull($reparentable->parent_id, 'fixture account must start with a parent link');
        $originalParentId = $reparentable->parent_id;
        $reparentable->update(['parent_id' => null]);

        $this->command('accounting:seed-charts')
            ->expectsOutputToContain('Chart provisioning: 0 created, 1 promoted, 1 reparented across 1 company/companies')
            ->assertSuccessful();

        self::assertTrue(
            (bool) Account::query()->whereKey($promotable->id)->firstOrFail()->is_system,
            'the drifted is_system flag must be promoted back',
        );
        self::assertSame(
            $originalParentId,
            Account::query()->whereKey($reparentable->id)->firstOrFail()->parent_id,
            'the cleared parent link must be re-issued',
        );
    }

    public function test_dry_run_reports_promotions_and_reparents_without_persisting(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('accounting:seed-charts')->assertSuccessful();

        $promotable = Account::query()->where('company_id', $company->id)
            ->where('code', '413')->firstOrFail();
        $promotable->update(['is_system' => false]);

        $this->command('accounting:seed-charts', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN] Chart provisioning: 0 created, 1 promoted, 0 reparented across 1 company/companies')
            ->assertSuccessful();

        self::assertFalse(
            (bool) Account::query()->whereKey($promotable->id)->firstOrFail()->is_system,
            'dry-run must report the promotion WITHOUT persisting it',
        );
    }

    public function test_dry_run_reports_created_then_real_run_creates(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        self::assertSame(0, Artisan::call('accounting:seed-charts', ['--dry-run' => true]));
        $preview = Artisan::output();
        self::assertSame(0, Account::query()->where('company_id', $company->id)->count());

        // This test's NAME is a claim about the reported count, so assert the number —
        // a generic 'Chart provisioning:' marker would still pass if the preview
        // dishonestly reported 0 created.
        self::assertSame(
            1,
            preg_match('/Chart provisioning: (\d+) created/', $preview, $matches),
            'the dry-run summary must report a creation count',
        );
        // `?? '0'` keeps the capture access type-safe (the match array is only proven
        // non-empty at runtime); a missing capture yields 0 and trips the guard below.
        $previewed = (int) ($matches[1] ?? '0');
        self::assertGreaterThan(0, $previewed, 'dry-run must preview a non-zero creation count');

        self::assertSame(0, Artisan::call('accounting:seed-charts'));
        $actual = Account::query()->where('company_id', $company->id)->count();
        self::assertGreaterThan(0, $actual);
        self::assertSame(
            $previewed,
            $actual,
            'the previewed creation count must equal what the real run actually creates',
        );
    }

    public function test_no_companies_reports_stable_marker_exit_zero(): void
    {
        Tenant::factory()->create();

        $this->command('accounting:seed-charts')
            ->expectsOutputToContain('across 0 company/companies')
            ->assertSuccessful();
    }

    public function test_template_provisioning_refuses_to_mutate_existing_company_charts(): void
    {
        config()->set('country_defaults.provisioning_enabled', true);
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $account = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'EXISTING',
            'name' => 'Operator-owned account',
            'parent_id' => null,
            'is_system' => false,
        ]);

        $before = $account->refresh()->getAttributes();

        $this->command('accounting:seed-charts')
            ->expectsOutputToContain('disabled while country-defaults template provisioning is enabled')
            ->assertFailed();

        self::assertSame(1, Account::query()->where('company_id', $company->id)->count());
        self::assertSame($before, $account->refresh()->getAttributes());
    }

    public function test_template_provisioning_allows_dry_run_inspection_without_writes(): void
    {
        config()->set('country_defaults.provisioning_enabled', true);
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $this->app->instance(ChartOfAccountsService::class, new class($this->app->make(CountryTemplateResolver::class), $this->app->make(TemplateChartOfAccountsSeeder::class)) extends ChartOfAccountsService
        {
            public function seedForCompany(Company $company): void
            {
                Account::factory()->create([
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'code' => 'DRY-RUN-ONLY',
                ]);
            }
        });

        $this->command('accounting:seed-charts', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN] Chart provisioning: 1 created')
            ->assertSuccessful();

        self::assertSame(0, Account::query()->where('company_id', $company->id)->count());
    }

    public function test_delegate_throw_fails_loud_and_aborts(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->app->instance(ChartOfAccountsService::class, new class($this->app->make(CountryTemplateResolver::class), $this->app->make(TemplateChartOfAccountsSeeder::class)) extends ChartOfAccountsService
        {
            public function seedForCompany(Company $company): void
            {
                throw new RuntimeException('boom');
            }
        });

        $logSpy = Log::spy();

        $this->command('accounting:seed-charts')
            ->expectsOutputToContain('No further companies were processed.')
            ->assertFailed();

        self::assertInstanceOf(LegacyMockInterface::class, $logSpy);
        $logSpy->shouldHaveReceived('error', [
            Mockery::on(static fn (string $message): bool => $message === 'accounting:seed-charts failed for a company; aborting.'),
            // Assert the tenant-aware CONTEXT KEYS, not merely `type('array')` — the
            // whole point of the fail-loud contract is that an operator can trace the
            // abort to a tenant/company/country, so dropping a key must fail this test.
            Mockery::on(static function (array $context): bool {
                foreach (['tenant_id', 'company_id', 'country_code', 'exception_class', 'exception_message'] as $key) {
                    if (! array_key_exists($key, $context)) {
                        return false;
                    }
                }

                return true;
            }),
        ]);

        self::assertSame(0, Account::query()->count(), 'no accounts written when the delegate throws');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
