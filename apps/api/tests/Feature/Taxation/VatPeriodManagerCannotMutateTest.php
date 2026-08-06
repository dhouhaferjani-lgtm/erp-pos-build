<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Gate finding I-1 (2026-08-06 review, orchestrator ruling): under the
 * pre-fix grants, `manager` could POST /vat/periods/{id}/{generate,close,
 * reopen,file} (kept `reports.manage`) while being 403'd on the read side
 * (`GET /vat/periods*` now requires `reports.financial`, which manager lost
 * under "OPERATIONAL ONLY") — a branch manager could file a VAT declaration
 * to the tax authority it cannot read back. The owner's "manager gets
 * OPERATIONAL ONLY" governs: VAT-period lifecycle mutation is financial, so
 * `reports.manage` is dropped from the manager grant. Accountant keeps it.
 */
final class VatPeriodManagerCannotMutateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'I-1 VAT Mutate Tenant',
            'slug' => 'i1-vat-mutate-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        DB::table('countries')->insertOrIgnore([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'is_active' => true,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'I-1 VAT Mutate Company',
            'legal_name' => 'I-1 VAT Mutate Company SARL',
            'tax_id' => 'I1VATMUT',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
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
            'name' => 'I-1 '.$role,
            'email' => $role.'-i1vat@example.com',
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

    private function openPeriod(string $start = '2026-01-01', string $end = '2026-01-31', string $label = 'January 2026'): VatPeriod
    {
        return VatPeriod::create([
            'company_id' => $this->company->id,
            'country_code' => 'TN',
            'period_type' => VatPeriodType::Monthly,
            'label' => $label,
            'period_start' => $start,
            'period_end' => $end,
            'status' => VatPeriodStatus::Open,
        ]);
    }

    public function test_manager_is_forbidden_from_generating_periods(): void
    {
        $this->actingAs($this->userWithRole('manager'))
            ->postJson('/api/v1/vat/periods/generate', ['year' => 2026])
            ->assertForbidden();
    }

    public function test_manager_is_forbidden_from_closing_a_period(): void
    {
        $period = $this->openPeriod();

        $this->actingAs($this->userWithRole('manager'))
            ->postJson("/api/v1/vat/periods/{$period->id}/close")
            ->assertForbidden();
    }

    public function test_manager_is_forbidden_from_reopening_a_period(): void
    {
        $period = $this->openPeriod();

        $this->actingAs($this->userWithRole('manager'))
            ->postJson("/api/v1/vat/periods/{$period->id}/reopen")
            ->assertForbidden();
    }

    public function test_manager_is_forbidden_from_filing_a_period(): void
    {
        $period = $this->openPeriod();

        $this->actingAs($this->userWithRole('manager'))
            ->postJson("/api/v1/vat/periods/{$period->id}/file")
            ->assertForbidden();
    }

    public function test_accountant_can_still_generate_close_reopen_and_attempt_file(): void
    {
        $accountant = $this->userWithRole('accountant');

        // generate: 201
        $this->actingAs($accountant)
            ->postJson('/api/v1/vat/periods/generate', ['year' => 2027])
            ->assertStatus(201);

        // close: 200 (business-state allows it — period starts Open)
        $period = $this->openPeriod('2025-01-01', '2025-01-31', 'January 2025');
        $this->actingAs($accountant)
            ->postJson("/api/v1/vat/periods/{$period->id}/close")
            ->assertStatus(200);

        // reopen: still permission-allowed; business rule (successor closed?
        // none here) may 422, but MUST NOT be 403 — proves the permission
        // gate itself passes for accountant.
        $reopenResponse = $this->actingAs($accountant)
            ->postJson("/api/v1/vat/periods/{$period->id}/reopen");
        $this->assertNotSame(403, $reopenResponse->getStatusCode());

        // file: business rule requires Closed-with-computed-totals; an Open
        // period 422s on business logic, not 403 on permission — proves the
        // permission gate passes for accountant.
        $openPeriod = $this->openPeriod('2025-02-01', '2025-02-28', 'February 2025');
        $fileResponse = $this->actingAs($accountant)
            ->postJson("/api/v1/vat/periods/{$openPeriod->id}/file");
        $this->assertNotSame(403, $fileResponse->getStatusCode());
    }
}
