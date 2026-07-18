<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\UserManagement;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class UserLocationAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $otherCompany;

    private Location $locationA;

    private Location $locationB;

    private Location $otherCompanyLocation;

    private User $ownerAdmin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Location Access Tenant',
            'slug' => 'location-access-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = $this->createCompany('Primary Company');
        $this->otherCompany = $this->createCompany('Other Company');

        $this->locationA = $this->createLocation($this->company, 'LOC-A');
        $this->locationB = $this->createLocation($this->company, 'LOC-B');
        $this->otherCompanyLocation = $this->createLocation($this->otherCompany, 'LOC-C');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $managePermission = Permission::findOrCreate('users.manage_location_access', 'sanctum');
        Role::findByName('admin', 'sanctum')->givePermissionTo($managePermission);

        $this->ownerAdmin = $this->createUser(
            email: 'owner@example.com',
            spatieRole: 'admin',
            membershipRole: MembershipRole::Owner,
            allowedLocations: null,
        );
        $this->target = $this->createUser(
            email: 'target@example.com',
            spatieRole: 'operator',
            membershipRole: MembershipRole::Cashier,
            allowedLocations: [$this->locationA->id],
        );
    }

    public function test_forbidden_without_manage_location_access_permission(): void
    {
        $granter = $this->createUser(
            email: 'manager-without-location-permission@example.com',
            spatieRole: 'viewer',
            membershipRole: MembershipRole::Manager,
            allowedLocations: [$this->locationA->id],
        );
        $granter->givePermissionTo('users.update');

        $response = $this->actingAs($granter, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->target->id}", [
                'allowed_location_ids' => [$this->locationA->id],
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cannot_edit_own_allowed_location_ids(): void
    {
        $response = $this->actingAs($this->ownerAdmin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->ownerAdmin->id}", [
                'allowed_location_ids' => [$this->locationA->id],
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'SELF_LOCATION_ESCALATION');

        $this->assertNull($this->membershipFor($this->ownerAdmin)->allowed_location_ids);
    }

    public function test_restricted_granter_cannot_grant_beyond_own_set(): void
    {
        $granter = $this->createRestrictedAdmin('restricted-beyond@example.com', [$this->locationA->id]);

        $response = $this->actingAs($granter, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->target->id}", [
                'allowed_location_ids' => [$this->locationA->id, $this->locationB->id],
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'LOCATION_ESCALATION');

        $this->assertSame([$this->locationA->id], $this->membershipFor($this->target)->allowed_location_ids);
    }

    public function test_restricted_granter_cannot_grant_explicit_null(): void
    {
        $granter = $this->createRestrictedAdmin('restricted-null@example.com', [$this->locationA->id]);
        $before = $this->membershipFor($this->target)->getRawOriginal('allowed_location_ids');

        $response = $this->actingAs($granter, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->target->id}", [
                'allowed_location_ids' => null,
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'LOCATION_ESCALATION');

        $this->assertSame($before, $this->membershipFor($this->target)->getRawOriginal('allowed_location_ids'));
    }

    public function test_restricted_granter_can_grant_within_own_set(): void
    {
        $granter = $this->createRestrictedAdmin(
            'restricted-within@example.com',
            [$this->locationA->id, $this->locationB->id],
        );

        $response = $this->actingAs($granter, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->target->id}", [
                'allowed_location_ids' => [$this->locationB->id],
            ]);

        $response->assertOk();
        $this->assertSame([$this->locationB->id], $this->membershipFor($this->target)->allowed_location_ids);
    }

    public function test_admin_null_membership_can_grant_any_company_location(): void
    {
        $unrestrictedAdmin = $this->createUser(
            email: 'unrestricted-admin@example.com',
            spatieRole: 'admin',
            membershipRole: MembershipRole::Admin,
            allowedLocations: null,
        );

        $response = $this->actingAs($unrestrictedAdmin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->target->id}", [
                'allowed_location_ids' => [$this->locationA->id, $this->locationB->id],
            ]);

        $response->assertOk();
        $this->assertSame(
            [$this->locationA->id, $this->locationB->id],
            $this->membershipFor($this->target)->allowed_location_ids,
        );
    }

    public function test_granting_non_company_location_is_rejected(): void
    {
        $response = $this->actingAs($this->ownerAdmin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->target->id}", [
                'allowed_location_ids' => [$this->otherCompanyLocation->id],
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'INVALID_LOCATION');

        $this->assertSame([$this->locationA->id], $this->membershipFor($this->target)->allowed_location_ids);
    }

    public function test_store_denied_grant_creates_no_user_membership_role_or_audit_row(): void
    {
        Notification::fake();
        $granter = $this->createUser(
            email: 'store-granter@example.com',
            spatieRole: 'viewer',
            membershipRole: MembershipRole::Manager,
            allowedLocations: [$this->locationA->id],
        );
        $granter->givePermissionTo('users.create');

        $before = [
            'users' => User::count(),
            'memberships' => UserCompanyMembership::count(),
            'roles' => DB::table('model_has_roles')->count(),
            'audits' => AuditEvent::count(),
        ];

        $response = $this->actingAs($granter, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/users', [
                'name' => 'Denied New User',
                'email' => 'denied-new-user@example.com',
                'role' => 'viewer',
                'allowed_location_ids' => [$this->locationA->id],
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertSame($before['users'], User::count());
        $this->assertSame($before['memberships'], UserCompanyMembership::count());
        $this->assertSame($before['roles'], DB::table('model_has_roles')->count());
        $this->assertSame($before['audits'], AuditEvent::count());
    }

    public function test_update_denied_grant_leaves_profile_role_and_membership_unchanged(): void
    {
        $granter = $this->createRestrictedAdmin('atomic-update@example.com', [$this->locationA->id]);
        $membershipBefore = $this->membershipFor($this->target)->getRawOriginal('allowed_location_ids');
        $rolesBefore = $this->target->getRoleNames()->values()->all();
        $auditCountBefore = AuditEvent::count();

        $response = $this->actingAs($granter, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->target->id}", [
                'name' => 'Leaked Name',
                'role' => 'manager',
                'allowed_location_ids' => [$this->locationA->id, $this->locationB->id],
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'LOCATION_ESCALATION');
        $this->assertSame('target@example.com', $this->target->refresh()->email);
        $this->assertSame('target@example.com', $this->target->email);
        $this->assertSame('target@example.com', $this->target->name);
        $this->assertSame($rolesBefore, $this->target->getRoleNames()->values()->all());
        $this->assertSame(
            $membershipBefore,
            $this->membershipFor($this->target)->getRawOriginal('allowed_location_ids'),
        );
        $this->assertSame($auditCountBefore, AuditEvent::count());
    }

    private function createCompany(string $name): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => $name.' SARL',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createLocation(Company $company, string $code): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => $code,
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    /**
     * @param  list<string>|null  $allowedLocations
     */
    private function createUser(
        string $email,
        string $spatieRole,
        MembershipRole $membershipRole,
        ?array $allowedLocations,
    ): User {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole($spatieRole);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $membershipRole,
            'allowed_location_ids' => $allowedLocations,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param  list<string>  $allowedLocations
     */
    private function createRestrictedAdmin(string $email, array $allowedLocations): User
    {
        return $this->createUser(
            email: $email,
            spatieRole: 'admin',
            membershipRole: MembershipRole::Manager,
            allowedLocations: $allowedLocations,
        );
    }

    private function membershipFor(User $user): UserCompanyMembership
    {
        return UserCompanyMembership::where('user_id', $user->id)
            ->where('company_id', $this->company->id)
            ->firstOrFail();
    }
}
