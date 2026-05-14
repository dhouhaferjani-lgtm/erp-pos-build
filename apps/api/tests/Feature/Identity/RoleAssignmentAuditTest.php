<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
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
 * Audit-trail coverage for privileged role assignment / removal (T1 / G1).
 *
 * Role assignment is a privileged action: "who granted whom which role, and
 * when" is a primary compliance question. Before this change RoleController
 * called Spatie's $user->assignRole()/->removeRole() directly and the only
 * record was the model_has_roles row — which carries team_id but no actor,
 * no timestamp-of-assignment, and no removal record at all.
 *
 * These tests pin the contract: every successful assign/remove leaves an
 * audit_events row carrying tenant + company + actor + action + target user
 * + role name + timestamp.
 */
final class RoleAssignmentAuditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Role Audit Tenant',
            'slug' => 'role-audit-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Role Audit Co',
            'legal_name' => 'Role Audit Co LLC',
            'tax_id' => 'TAX-ROLE-AUDIT',
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
            'name' => 'Role Audit Admin',
            'email' => 'role-audit-admin@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->admin->assignRole('admin');

        $this->target = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Role Audit Target',
            'email' => 'role-audit-target@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->target->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
    }

    public function test_assign_role_persists_audit_event(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/users/'.$this->target->id.'/roles', [
                'role' => 'cashier',
            ])
            ->assertStatus(200);

        $audit = AuditEvent::where('aggregate_id', $this->target->id)
            ->where('event_type', 'identity.role.assigned')
            ->first();

        $this->assertNotNull(
            $audit,
            'Assigning a role must leave an audit_events row.',
        );
        $this->assertSame($this->tenant->id, $audit->tenant_id);
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame($this->admin->id, $audit->user_id, 'Audit actor must be the admin who performed the assignment.');
        $this->assertSame('User', $audit->aggregate_type);
        $this->assertSame($this->target->id, $audit->aggregate_id);
        $this->assertSame('cashier', $audit->payload['role_name']);
        $this->assertSame($this->admin->id, $audit->payload['actor_user_id']);
        $this->assertSame($this->target->id, $audit->payload['target_user_id']);
        $this->assertArrayHasKey('assigned_at', $audit->payload);
    }

    public function test_remove_role_persists_audit_event(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->target->assignRole('cashier');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/v1/users/'.$this->target->id.'/roles', [
                'role' => 'cashier',
            ])
            ->assertStatus(200);

        $audit = AuditEvent::where('aggregate_id', $this->target->id)
            ->where('event_type', 'identity.role.removed')
            ->first();

        $this->assertNotNull(
            $audit,
            'Removing a role must leave an audit_events row.',
        );
        $this->assertSame($this->tenant->id, $audit->tenant_id);
        $this->assertSame($this->company->id, $audit->company_id);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('User', $audit->aggregate_type);
        $this->assertSame($this->target->id, $audit->aggregate_id);
        $this->assertSame('cashier', $audit->payload['role_name']);
        $this->assertSame($this->admin->id, $audit->payload['actor_user_id']);
        $this->assertArrayHasKey('removed_at', $audit->payload);
    }

    public function test_failed_assignment_leaves_no_audit_event(): void
    {
        // Validation failure (unknown role) must not produce a spurious audit row.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/users/'.$this->target->id.'/roles', [
                'role' => 'nonexistent-role',
            ])
            ->assertStatus(422);

        $this->assertSame(
            0,
            AuditEvent::where('event_type', 'identity.role.assigned')->count(),
            'A rejected role assignment must not leave an audit_events row.',
        );
    }
}
