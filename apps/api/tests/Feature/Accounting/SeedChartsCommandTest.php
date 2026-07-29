<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class SeedChartsCommandTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_seeds_the_chart_and_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('accounting:seed-charts')->assertSuccessful();
        $firstPass = Account::query()->where('company_id', $company->id)->count();
        self::assertGreaterThan(0, $firstPass);
        // The portfolio/fee liability accounts the Phase-2 chart provisioning adds.
        self::assertTrue(
            Account::query()->where('company_id', $company->id)->where('code', '403')->exists(),
        );

        // Re-run adds nothing new — chart provisioning is additive/idempotent.
        $this->command('accounting:seed-charts')->assertSuccessful();
        self::assertSame($firstPass, Account::query()->where('company_id', $company->id)->count());
    }

    public function test_dry_run_reports_would_create_count_then_real_run_creates(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->command('accounting:seed-charts', ['--dry-run' => true])
            ->expectsOutputToContain('would be created')
            ->assertSuccessful();
        self::assertSame(0, Account::query()->where('company_id', $company->id)->count());

        $this->command('accounting:seed-charts')->assertSuccessful();
        self::assertGreaterThan(0, Account::query()->where('company_id', $company->id)->count());
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
