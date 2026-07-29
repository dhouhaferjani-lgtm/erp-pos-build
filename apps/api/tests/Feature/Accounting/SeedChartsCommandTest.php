<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->command('accounting:seed-charts')
            ->expectsOutputToContain('0 created')
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
    }

    public function test_dry_run_reports_created_then_real_run_creates(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('accounting:seed-charts', ['--dry-run' => true])
            ->expectsOutputToContain('Chart provisioning:')
            ->assertSuccessful();
        self::assertSame(0, Account::query()->where('company_id', $company->id)->count());

        $this->command('accounting:seed-charts')->assertSuccessful();
        self::assertGreaterThan(0, Account::query()->where('company_id', $company->id)->count());
    }

    public function test_no_companies_reports_stable_marker_exit_zero(): void
    {
        Tenant::factory()->create();

        $this->command('accounting:seed-charts')
            ->expectsOutputToContain('across 0 company/companies')
            ->assertSuccessful();
    }

    public function test_delegate_throw_fails_loud_and_aborts(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->app->instance(ChartOfAccountsService::class, new class extends ChartOfAccountsService
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
            Mockery::type('array'),
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
