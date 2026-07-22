<?php

declare(strict_types=1);

namespace Tests\Feature\Company\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class BackfillUserCompanyMembershipsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'T', 'slug' => 'bf-'.uniqid(), 'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function makeCompanyFor(Tenant $tenant, string $name = 'C'): Company
    {
        return Company::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'legal_name' => $name.' SARL',
            'country_code' => 'FR', 'currency' => 'EUR', 'locale' => 'fr',
            'timezone' => 'Europe/Paris', 'date_format' => 'd/m/Y', 'status' => CompanyStatus::Active,
        ]);
    }

    /** Single-company tenant helper (the common case). */
    private function makeCompany(): Company
    {
        return $this->makeCompanyFor($this->makeTenant());
    }

    private function runBackfill(): void
    {
        $migration = require __DIR__.'/../../../../database/migrations/tenant/2026_07_16_100000_backfill_user_company_memberships.php';
        $migration->up();
    }

    public function test_single_company_tenant_backfills_memberless_active_user_with_null_membership(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['tenant_id' => $company->tenant_id, 'status' => UserStatus::Active]);
        UserCompanyMembership::where('user_id', $user->id)->delete();

        $this->runBackfill();

        $row = DB::table('user_company_memberships')
            ->where('user_id', $user->id)->where('company_id', $company->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->allowed_location_ids);
        $this->assertFalse((bool) $row->is_primary);
        $this->assertSame('active', $row->status);
        $this->assertSame('viewer', $row->role);
    }

    public function test_multi_company_tenant_skips_memberless_user_and_writes_no_rows(): void
    {
        // Two companies in one tenant → ambiguous → SKIP (no regression: already deny-all).
        $tenant = $this->makeTenant();
        $companyA = $this->makeCompanyFor($tenant, 'A');
        $companyB = $this->makeCompanyFor($tenant, 'B');
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => UserStatus::Active]);
        UserCompanyMembership::where('user_id', $user->id)->delete();

        Log::spy();
        $this->runBackfill();

        // No membership row was created for the ambiguous user in EITHER company.
        $this->assertSame(0, UserCompanyMembership::where('user_id', $user->id)->count());
        // A structured skip report was emitted (for the companion command to act on).
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg): bool => $msg === 'multiloc.backfill.skipped_ambiguous_users')
            ->once();
    }

    public function test_existing_restricted_membership_is_untouched(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['tenant_id' => $company->tenant_id, 'status' => UserStatus::Active]);
        UserCompanyMembership::where('user_id', $user->id)->delete();
        $existing = UserCompanyMembership::create([
            'user_id' => $user->id, 'company_id' => $company->id, 'role' => 'manager',
            'allowed_location_ids' => ['11111111-1111-1111-1111-111111111111'],
            'is_primary' => true, 'status' => 'active',
        ]);

        $this->runBackfill();

        $existing->refresh();
        $this->assertSame(['11111111-1111-1111-1111-111111111111'], $existing->allowed_location_ids);
        $this->assertSame(1, UserCompanyMembership::where('user_id', $user->id)->where('company_id', $company->id)->count());
    }

    public function test_inactive_user_gets_no_membership(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['tenant_id' => $company->tenant_id, 'status' => UserStatus::Inactive]);
        UserCompanyMembership::where('user_id', $user->id)->delete();

        $this->runBackfill();

        $this->assertSame(0, UserCompanyMembership::where('user_id', $user->id)->where('company_id', $company->id)->count());
    }

    public function test_rerun_is_a_noop(): void
    {
        $company = $this->makeCompany();
        $user = User::factory()->create(['tenant_id' => $company->tenant_id, 'status' => UserStatus::Active]);
        UserCompanyMembership::where('user_id', $user->id)->delete();

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(1, UserCompanyMembership::where('user_id', $user->id)->where('company_id', $company->id)->count());
    }
}
