<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Reports;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * W-6 D5 owner ruling (2026-08-05, "Option B split") — HTTP-level, role-based
 * coverage for the seeded role grants: one representative endpoint per
 * permission group, exercised through the actual seeded roles (not ad hoc
 * `givePermissionTo` calls), so a regression in either the seeder grants or
 * the route middleware trips a test.
 *
 * - Financial group representative: GET /reports/trial-balance
 * - Operational group representative: GET /reports/overdue-summary
 * - GL-browse group representative: GET /ledger
 */
final class FinanceReportPermissionSplitHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'D5 Split Tenant',
            'slug' => 'd5-split-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'D5 Split Company',
            'legal_name' => 'D5 Split Company LLC',
            'tax_id' => 'D5TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'D5 '.$role,
            'email' => $role.'-d5@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role,
        ]);

        $user->assignRole($role);

        return $user;
    }

    // ------------------------------------------------ reports.financial ----

    public function test_trial_balance_admin_and_accountant_allowed(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->getJson('/api/v1/reports/trial-balance')
            ->assertOk();

        $this->actingAs($this->userWithRole('accountant'))
            ->getJson('/api/v1/reports/trial-balance')
            ->assertOk();
    }

    public function test_trial_balance_manager_and_viewer_denied(): void
    {
        $this->actingAs($this->userWithRole('manager'))
            ->getJson('/api/v1/reports/trial-balance')
            ->assertForbidden();

        $this->actingAs($this->userWithRole('viewer'))
            ->getJson('/api/v1/reports/trial-balance')
            ->assertForbidden();
    }

    // ---------------------------------------------- reports.operational ----

    public function test_overdue_summary_admin_accountant_and_manager_allowed(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->getJson('/api/v1/reports/overdue-summary')
            ->assertOk();

        $this->actingAs($this->userWithRole('accountant'))
            ->getJson('/api/v1/reports/overdue-summary')
            ->assertOk();

        $this->actingAs($this->userWithRole('manager'))
            ->getJson('/api/v1/reports/overdue-summary')
            ->assertOk();
    }

    public function test_overdue_summary_viewer_denied(): void
    {
        $this->actingAs($this->userWithRole('viewer'))
            ->getJson('/api/v1/reports/overdue-summary')
            ->assertForbidden();
    }

    // ---------------------------------------------------- ledger.view ------

    public function test_ledger_admin_and_accountant_allowed(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->getJson('/api/v1/ledger')
            ->assertOk();

        $this->actingAs($this->userWithRole('accountant'))
            ->getJson('/api/v1/ledger')
            ->assertOk();
    }

    public function test_ledger_manager_and_viewer_denied(): void
    {
        $this->actingAs($this->userWithRole('manager'))
            ->getJson('/api/v1/ledger')
            ->assertForbidden();

        $this->actingAs($this->userWithRole('viewer'))
            ->getJson('/api/v1/ledger')
            ->assertForbidden();
    }
}
