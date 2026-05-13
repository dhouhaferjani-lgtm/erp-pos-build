<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
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
 * Tenant-isolation regression coverage for RoleController user-role
 * endpoints.
 *
 * Master plan §M2.4 — three known-gap CrossTenantRoute annotations on
 * RoleController::assignRole(), ::removeRole(), and ::userRoles().
 * The Spatie-scoped routes (index/show/store/update/destroy/permissions)
 * remain annotated because they are protected by Spatie's TeamScope
 * global scope, not RMB; those rows are NOT in this round's fix-now
 * scope.
 *
 * The three KNOWN GAP rows all do `User::findOrFail($userId)` without a
 * tenant predicate. Any user UUID from any tenant resolves; only the
 * downstream Spatie role lookup is team-scoped. The gap let an attacker
 * with `roles.assign` on tenant A AND knowledge of a tenant-B user UUID
 * assign a tenant-A role to that user (the role would then be ineffective
 * for tenant-B requests due to Spatie's team scoping, but the row exists
 * and discloses cross-tenant user existence).
 *
 * Fix: every User lookup goes through a tenant-scoped query that
 * resolves to 404 on cross-tenant ids, matching the same shape as a
 * missing id so id enumeration cannot distinguish foreign-tenant from
 * missing.
 */
final class RoleTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $adminA;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-role-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-role-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $companyA = $this->makeCompany($this->tenantA->id, 'Company A', 'TAX-RA');
        $companyB = $this->makeCompany($this->tenantB->id, 'Company B', 'TAX-RB');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminA = $this->makeUser($this->tenantA->id, 'admin-role-a@example.com');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->adminA->assignRole('admin');

        $this->userA = $this->makeUser($this->tenantA->id, 'user-role-a@example.com');

        $this->userB = $this->makeUser($this->tenantB->id, 'user-role-b@example.com');

        UserCompanyMembership::create([
            'user_id' => $this->adminA->id,
            'company_id' => $companyA->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $companyA->id,
            'role' => 'cashier',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $companyB->id,
            'role' => 'cashier',
        ]);
    }

    public function test_assign_role_rejects_cross_tenant_user(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/v1/users/'.$this->userB->id.'/roles', [
                'role' => 'cashier',
            ])
            ->assertStatus(404);
    }

    public function test_remove_role_rejects_cross_tenant_user(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson('/api/v1/users/'.$this->userB->id.'/roles', [
                'role' => 'cashier',
            ])
            ->assertStatus(404);
    }

    public function test_user_roles_rejects_cross_tenant_user(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/v1/users/'.$this->userB->id.'/roles')
            ->assertStatus(404);
    }

    public function test_assign_role_rejects_nonexistent_user_with_same_shape(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/v1/users/00000000-0000-0000-0000-000000000000/roles', [
                'role' => 'cashier',
            ])
            ->assertStatus(404);
    }

    public function test_same_tenant_assign_role_succeeds(): void
    {
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/v1/users/'.$this->userA->id.'/roles', [
                'role' => 'cashier',
            ]);

        $response->assertStatus(200);
        $this->assertContains('cashier', $response->json('data.roles', []));
    }

    public function test_same_tenant_user_roles_returns_payload(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('cashier');

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/v1/users/'.$this->userA->id.'/roles');

        $response->assertStatus(200);
        $this->assertEquals($this->userA->id, $response->json('data.user_id'));
    }

    public function test_same_tenant_remove_role_clears_role(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('cashier');

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson('/api/v1/users/'.$this->userA->id.'/roles', [
                'role' => 'cashier',
            ]);

        $response->assertStatus(200);
        $this->assertNotContains('cashier', $response->json('data.roles', []));
    }

    private function makeCompany(string $tenantId, string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => $taxId,
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeUser(string $tenantId, string $email): User
    {
        return User::create([
            'tenant_id' => $tenantId,
            'name' => 'User '.$email,
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
    }
}
