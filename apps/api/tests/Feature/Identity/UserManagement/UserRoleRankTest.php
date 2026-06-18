<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\UserManagement;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
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
use Tests\Traits\AssertsApiValidation;

/**
 * Security contract for role-rank enforcement on user create/update
 * (go-live audit 2026-06-14, Finding #2 — privilege escalation).
 *
 * `users.create` / `users.update` are legitimately held by delegated roles
 * (e.g. an HR role), but the `role` field was validated only as
 * `exists:roles,name`. That let such a user mint or promote an account into
 * `admin` (Permission::all()), escalating above their own level. A user may
 * only assign a role whose permission set is a subset of their own.
 */
final class UserRoleRankTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $hr;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Rank Tenant',
            'slug' => 'rank-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rank Co',
            'legal_name' => 'Rank Co SARL',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // A delegated role that can manage users but holds no admin powers.
        $hrRole = Role::firstOrCreate(['name' => 'limited-hr', 'guard_name' => 'sanctum']);
        $hrRole->syncPermissions(['users.view', 'users.create', 'users.update']);

        $this->hr = $this->makeUser('hr@example.com', 'limited-hr', MembershipRole::Manager);
        $this->admin = $this->makeUser('admin@example.com', 'admin', MembershipRole::Owner);
    }

    private function makeUser(string $email, string $role, MembershipRole $membershipRole): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $membershipRole,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    public function test_delegated_user_cannot_create_an_admin(): void
    {
        $response = $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Sneaky Admin',
                'email' => 'sneaky@example.com',
                'role' => 'admin',
            ]);

        $this->assertApiValidationErrors($response, ['role']);

        $this->assertNull(
            User::where('email', 'sneaky@example.com')->first(),
            'A delegated user must not be able to mint an admin account.',
        );
    }

    public function test_delegated_user_can_create_user_with_their_own_role(): void
    {
        // limited-hr assigning limited-hr: permission set is a subset of the
        // actor's own, so it is allowed.
        $this->actingAs($this->hr, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Peer',
                'email' => 'peer@example.com',
                'role' => 'limited-hr',
            ])
            ->assertStatus(201);
    }

    public function test_delegated_user_cannot_promote_existing_user_to_admin(): void
    {
        $target = $this->makeUser('target@example.com', 'limited-hr', MembershipRole::Viewer);

        $response = $this->actingAs($this->hr, 'sanctum')
            ->patchJson('/api/v1/users/'.$target->id, [
                'role' => 'admin',
            ]);

        $this->assertApiValidationErrors($response, ['role']);
    }

    public function test_admin_can_still_create_an_admin(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Real Admin',
                'email' => 'real-admin@example.com',
                'role' => 'admin',
            ])
            ->assertStatus(201);
    }
}
