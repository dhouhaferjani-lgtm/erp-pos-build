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
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Security contract for role management & role assignment authorization
 * (go-live audit 2026-06-14, Finding #1 — privilege escalation).
 *
 * Before this change RoleController::store/update/assignRole had NO
 * authorization gate (route comments were aspirational only). Any
 * authenticated tenant member — e.g. a cashier — could mint an
 * arbitrary-permission role or self-assign `admin` (Permission::all()).
 *
 * These tests pin: role CRUD requires `roles.manage`; role assignment
 * requires `users.assign-roles`; and the gates do not block a legitimate
 * `admin`.
 */
final class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Role Authz Tenant',
            'slug' => 'role-authz-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Role Authz Co',
            'legal_name' => 'Role Authz Co LLC',
            'tax_id' => 'TAX-ROLE-AUTHZ',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Authz Admin',
            'email' => 'authz-admin@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->admin->assignRole('admin');

        // Cashier: a real low-privilege role that lacks roles.manage and
        // users.assign-roles (see RolesAndPermissionsSeeder).
        $this->cashier = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Authz Cashier',
            'email' => 'authz-cashier@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->cashier->assignRole('cashier');

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
    }

    public function test_cashier_cannot_self_assign_admin_role(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/users/'.$this->cashier->id.'/roles', [
                'role' => 'admin',
            ])
            ->assertStatus(403);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->assertFalse(
            $this->cashier->fresh()->hasRole('admin'),
            'A cashier must NOT be able to grant themselves the admin role.',
        );
    }

    public function test_cashier_cannot_create_role(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => 'pwn',
                'permissions' => ['users.assign-roles', 'invoices.post'],
            ])
            ->assertStatus(403);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->assertFalse(
            Role::where('name', 'pwn')->exists(),
            'A cashier must NOT be able to mint a new role.',
        );
    }

    public function test_cashier_cannot_update_role(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $cashierRole = Role::where('name', 'cashier')->firstOrFail();

        $this->actingAs($this->cashier, 'sanctum')
            ->patchJson('/api/v1/roles/'.$cashierRole->id, [
                'permissions' => ['users.assign-roles', 'invoices.post'],
            ])
            ->assertStatus(403);
    }

    public function test_admin_can_assign_role(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/users/'.$this->cashier->id.'/roles', [
                'role' => 'manager',
            ])
            ->assertStatus(200);
    }

    public function test_admin_can_create_role(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => 'custom-role',
                'permissions' => ['partners.view'],
            ])
            ->assertStatus(201);
    }

    public function test_cashier_cannot_delete_role(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $deletable = Role::create(['name' => 'deletable-role', 'guard_name' => 'sanctum']);

        $this->actingAs($this->cashier, 'sanctum')
            ->deleteJson('/api/v1/roles/'.$deletable->id)
            ->assertStatus(403);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->assertTrue(
            Role::where('id', $deletable->id)->exists(),
            'A cashier must NOT be able to delete a role.',
        );
    }

    public function test_admin_can_delete_role(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $deletable = Role::create(['name' => 'admin-deletable-role', 'guard_name' => 'sanctum']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/v1/roles/'.$deletable->id)
            ->assertStatus(200);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->assertFalse(
            Role::where('id', $deletable->id)->exists(),
            'An admin with roles.manage must be able to delete a non-system, user-less role.',
        );
    }
}
