<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `fiscal:lock-expired-periods` — daily 01:00.
 *
 * `FiscalPeriodAutoLockService::lockExpiredPeriods()` writes `fiscal_periods`
 * and `fiscal_years`, both TENANT tables. The command ran on the scheduler's
 * CENTRAL connection, so since the 2026-05-28 database-per-tenant flip every
 * nightly run raised 42P01 — and the `catch (\Exception)` at the bottom of
 * handle() swallowed it into a FAILURE exit that the schedule entry (no
 * `onFailure()`, `runInBackground()`) never observed. Silently dead nightly.
 *
 * NOTE — the locking business logic is deliberately untouched by this
 * conversion: only the iteration and the tenant context changed.
 */
final class LockExpiredFiscalPeriodsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_it_closes_expired_periods_and_ended_fiscal_years(): void
    {
        $tenant = $this->createTenant('fiscal-lock-a');
        $company = $this->createCompany($tenant->id, 'A');

        $year = $this->createFiscalYear($company, endDate: Carbon::now()->subMonths(6), isClosed: false);
        $expired = $this->createPeriod($year, endDate: Carbon::now()->subMonths(6), status: PeriodStatus::Open);

        $exitCode = Artisan::call('fiscal:lock-expired-periods');

        $this->assertSame(0, $exitCode);
        $this->assertTrue($year->fresh()?->is_closed);
        // Closed year => STEP 3 promotes every one of its periods to Locked.
        $this->assertSame(PeriodStatus::Locked, $expired->fresh()?->status);
    }

    public function test_a_current_period_in_an_open_fiscal_year_is_untouched(): void
    {
        $tenant = $this->createTenant('fiscal-lock-current');
        $company = $this->createCompany($tenant->id, 'CURRENT');

        $year = $this->createFiscalYear($company, endDate: Carbon::now()->addMonths(6), isClosed: false);
        $current = $this->createPeriod($year, endDate: Carbon::now()->addDays(5), status: PeriodStatus::Open);

        $this->assertSame(0, Artisan::call('fiscal:lock-expired-periods'));

        $this->assertFalse($year->fresh()?->is_closed);
        $this->assertSame(PeriodStatus::Open, $current->fresh()?->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $tenant = $this->createTenant('fiscal-lock-dry');
        $company = $this->createCompany($tenant->id, 'DRY');

        $year = $this->createFiscalYear($company, endDate: Carbon::now()->subMonths(6), isClosed: false);
        $expired = $this->createPeriod($year, endDate: Carbon::now()->subMonths(6), status: PeriodStatus::Open);

        $this->assertSame(0, Artisan::call('fiscal:lock-expired-periods', ['--dry-run' => true]));

        $this->assertFalse($year->fresh()?->is_closed);
        $this->assertSame(PeriodStatus::Open, $expired->fresh()?->status);
    }

    /**
     * The iteration guard. `forEachTenant()` opens a slot per row in the
     * CENTRAL tenant directory — with no tenant rows at all there is nothing to
     * iterate, so the service must never run and no fiscal data may change.
     * A command that still called the service once from central context (the
     * pre-conversion shape) would lock these rows.
     */
    public function test_nothing_is_locked_when_the_tenant_directory_is_empty(): void
    {
        $this->assertSame(0, Tenant::query()->count());

        $company = $this->createCompany((string) Str::uuid(), 'ORPHAN');
        $year = $this->createFiscalYear($company, endDate: Carbon::now()->subMonths(6), isClosed: false);
        $expired = $this->createPeriod($year, endDate: Carbon::now()->subMonths(6), status: PeriodStatus::Open);

        $this->assertSame(0, Artisan::call('fiscal:lock-expired-periods'));

        $this->assertFalse($year->fresh()?->is_closed);
        $this->assertSame(PeriodStatus::Open, $expired->fresh()?->status);
    }

    public function test_the_command_is_registered_with_the_scheduler(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('php artisan fiscal:lock-expired-periods', $output);
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function createCompany(string $tenantId, string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $tenantId,
            'name' => "Fiscal Company {$suffix}",
            'legal_name' => "Fiscal Company {$suffix} LLC",
            'tax_id' => "TAX-FSC-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createFiscalYear(Company $company, Carbon $endDate, bool $isClosed): FiscalYear
    {
        return FiscalYear::create([
            'company_id' => $company->id,
            'name' => 'FY '.$endDate->year,
            'start_date' => $endDate->copy()->subYear(),
            'end_date' => $endDate,
            'is_closed' => $isClosed,
        ]);
    }

    private function createPeriod(FiscalYear $year, Carbon $endDate, PeriodStatus $status): FiscalPeriod
    {
        return FiscalPeriod::create([
            'fiscal_year_id' => $year->id,
            'company_id' => $year->company_id,
            'name' => 'P'.$endDate->month,
            'period_number' => $endDate->month,
            'start_date' => $endDate->copy()->startOfMonth(),
            'end_date' => $endDate,
            'status' => $status,
        ]);
    }
}
