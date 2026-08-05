<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `fraud:detect` — daily 02:00.
 *
 * `Company::all()` sat at the TOP of handle(), OUTSIDE the per-company
 * try/catch, so under database-per-tenant the 42P01 on the (tenant) `companies`
 * table escaped the whole command. Combined with `runInBackground()` and no
 * `onFailure()` on the schedule entry, nightly fraud detection failed with no
 * operator signal.
 */
final class DetectFraudPatternsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_it_analyses_every_company_of_every_tenant_in_the_directory(): void
    {
        $tenantA = $this->createTenant('fraud-a');
        $tenantB = $this->createTenant('fraud-b');

        $companyA1 = $this->createCompany($tenantA->id, 'A1');
        $companyA2 = $this->createCompany($tenantA->id, 'A2');
        $companyB1 = $this->createCompany($tenantB->id, 'B1');

        $exitCode = Artisan::call('fraud:detect');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($companyA1->id, $output);
        $this->assertStringContainsString($companyA2->id, $output);
        $this->assertStringContainsString($companyB1->id, $output);
        $this->assertStringContainsString('Companies analyzed', $output);
    }

    /**
     * The iteration guard: a company whose `tenant_id` has no row in the CENTRAL
     * tenant directory is unreachable by `forEachTenant()`, so it must never be
     * analysed. The pre-conversion `Company::all()` swept it up.
     */
    public function test_a_company_whose_tenant_is_absent_from_the_directory_is_never_analysed(): void
    {
        $tenant = $this->createTenant('fraud-real');
        $reachable = $this->createCompany($tenant->id, 'REAL');
        $orphan = $this->createCompany((string) Str::uuid(), 'ORPHAN');

        $exitCode = Artisan::call('fraud:detect');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($reachable->id, $output);
        $this->assertStringNotContainsString($orphan->id, $output);
    }

    public function test_the_company_option_narrows_the_analysis_within_the_tenant(): void
    {
        $tenant = $this->createTenant('fraud-filter');
        $target = $this->createCompany($tenant->id, 'TARGET');
        $other = $this->createCompany($tenant->id, 'OTHER');

        $exitCode = Artisan::call('fraud:detect', ['--company' => $target->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($target->id, $output);
        $this->assertStringNotContainsString($other->id, $output);
    }

    /**
     * An operator who narrows to a company that no reachable tenant owns must
     * not be told the run succeeded — the same "exited SUCCESS having done
     * nothing" trap `failIfTenantFilterUnvisited()` closes for `--tenant`.
     */
    public function test_an_unmatched_company_filter_fails_loudly(): void
    {
        $this->createCompany($this->createTenant('fraud-unmatched')->id, 'PRESENT');

        $exitCode = Artisan::call('fraud:detect', ['--company' => (string) Str::uuid()]);

        $this->assertNotSame(0, $exitCode);
    }

    public function test_a_tenant_with_no_companies_is_not_a_failure(): void
    {
        $this->createTenant('fraud-empty');

        $this->assertSame(0, Artisan::call('fraud:detect'));
    }

    public function test_the_command_is_registered_with_the_scheduler(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('php artisan fraud:detect', $output);
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
            'name' => "Fraud Company {$suffix}",
            'legal_name' => "Fraud Company {$suffix} LLC",
            'tax_id' => "TAX-FRD-{$suffix}",
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }
}
