<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

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
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression tests for Spatie Permission guard consistency.
 *
 * BACKGROUND:
 * A bug was introduced when the User model's getDefaultGuardName() method returned
 * 'web' instead of 'sanctum', while all permissions/roles were created with 'sanctum'.
 * This caused all permission checks to fail with 403 errors because:
 * - $user->can('permission') uses the User's default guard to look up permissions
 * - Permissions only existed for 'sanctum' guard, not 'web'
 *
 * These tests ensure this bug cannot be reintroduced.
 *
 * @see https://spatie.be/docs/laravel-permission/v6/basic-usage/multiple-guards
 */
class GuardConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_GUARD = 'sanctum';

    /**
     * Ensures the User model returns the correct guard name for Spatie Permission.
     *
     * CRITICAL: This guard MUST match the guard used when creating permissions/roles.
     * If this test fails, permission checks will not work correctly.
     */
    public function test_user_model_returns_sanctum_as_default_guard(): void
    {
        $user = new User;

        $this->assertEquals(
            self::EXPECTED_GUARD,
            $user->getDefaultGuardName(),
            'User::getDefaultGuardName() must return "sanctum" to match permission/role guard. '
            . 'If you change this, ALL permission checks will fail with 403 errors.'
        );
    }

    /**
     * Ensures the sanctum guard is defined in auth configuration.
     *
     * Spatie Permission requires the guard to exist in config/auth.php.
     */
    public function test_auth_config_has_sanctum_guard_defined(): void
    {
        $guards = config('auth.guards');

        $this->assertArrayHasKey(
            'sanctum',
            $guards,
            'The "sanctum" guard must be defined in config/auth.php. '
            . 'Without this, Spatie Permission will throw an error when checking permissions.'
        );
    }

    /**
     * Ensures all permissions are created with the correct guard.
     */
    public function test_all_permissions_use_sanctum_guard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $wrongGuardPermissions = Permission::where('guard_name', '!=', self::EXPECTED_GUARD)->get();

        $this->assertEmpty(
            $wrongGuardPermissions->toArray(),
            'Found permissions with wrong guard: ' . $wrongGuardPermissions->pluck('name', 'guard_name')->toJson()
            . '. All permissions must use "sanctum" guard.'
        );
    }

    /**
     * Ensures all roles are created with the correct guard.
     */
    public function test_all_roles_use_sanctum_guard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $wrongGuardRoles = Role::where('guard_name', '!=', self::EXPECTED_GUARD)->get();

        $this->assertEmpty(
            $wrongGuardRoles->toArray(),
            'Found roles with wrong guard: ' . $wrongGuardRoles->pluck('name', 'guard_name')->toJson()
            . '. All roles must use "sanctum" guard.'
        );
    }

    /**
     * Ensures PermissionSeeder creates permissions with correct guard.
     */
    public function test_permission_seeder_uses_sanctum_guard(): void
    {
        $this->seed(PermissionSeeder::class);

        $wrongGuardPermissions = Permission::where('guard_name', '!=', self::EXPECTED_GUARD)->get();
        $wrongGuardRoles = Role::where('guard_name', '!=', self::EXPECTED_GUARD)->get();

        $this->assertEmpty(
            $wrongGuardPermissions->toArray(),
            'PermissionSeeder created permissions with wrong guard.'
        );

        $this->assertEmpty(
            $wrongGuardRoles->toArray(),
            'PermissionSeeder created roles with wrong guard.'
        );
    }

    /**
     * INTEGRATION TEST: Ensures a user with a role can actually check permissions.
     *
     * This is the critical test that would have caught the original bug.
     * It tests the full permission check flow end-to-end.
     */
    public function test_user_with_role_can_check_permissions_using_can_method(): void
    {
        // Set up tenant and company
        $tenant = Tenant::create([
            'name' => 'Guard Test Tenant',
            'slug' => 'guard-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Guard Test Company',
            'legal_name' => 'Guard Test Company LLC',
            'tax_id' => 'GUARD123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Set tenant context for permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        // Seed permissions
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create user and assign admin role
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Guard Test User',
            'email' => 'guard-test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        // CRITICAL: Test using $user->can() - this is what controllers use
        // If the guard is wrong, this will return false even with the role
        $this->assertTrue(
            $user->can('products.view'),
            'User with admin role cannot check "products.view" permission using $user->can(). '
            . 'This indicates a guard mismatch between User model and permissions. '
            . 'User guard: ' . $user->getDefaultGuardName() . ', '
            . 'Expected guard: ' . self::EXPECTED_GUARD
        );

        // Test a few more permissions to be thorough
        $this->assertTrue($user->can('partners.view'), 'Admin should have partners.view permission');
        $this->assertTrue($user->can('invoices.view'), 'Admin should have invoices.view permission');
        $this->assertTrue($user->can('payments.view'), 'Admin should have payments.view permission');
    }

    /**
     * Tests that permission checks work through the hasPermissionTo method.
     */
    public function test_user_with_role_can_check_permissions_using_has_permission_to(): void
    {
        $tenant = Tenant::create([
            'name' => 'HasPermission Test',
            'slug' => 'has-permission-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Permission Test User',
            'email' => 'permission-test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        // Test hasPermissionTo - another common way to check permissions
        $this->assertTrue(
            $user->hasPermissionTo('products.view'),
            'User with admin role cannot check permission using hasPermissionTo(). Guard mismatch likely.'
        );
    }

    /**
     * Tests that API endpoints respect permissions correctly.
     *
     * This is an integration test that verifies the full auth flow works,
     * from authentication through permission checking.
     */
    public function test_api_endpoint_respects_permissions_for_authenticated_user(): void
    {
        $tenant = Tenant::create([
            'name' => 'API Auth Test',
            'slug' => 'api-auth-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'API Test Company',
            'legal_name' => 'API Test Company LLC',
            'tax_id' => 'API123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'API Test User',
            'email' => 'api-test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        // Create company membership (required for company-scoped endpoints)
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        // Test that authenticated user with proper role can access protected endpoint
        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders([
                'X-Company-ID' => $company->id,
            ])
            ->getJson('/api/v1/products');

        // Should NOT get 403 Forbidden
        $this->assertNotEquals(
            403,
            $response->getStatusCode(),
            'Authenticated user with admin role received 403 Forbidden. '
            . 'This indicates permission guard mismatch. '
            . 'Response: ' . $response->getContent()
        );
    }

    /**
     * Tests that unauthenticated requests are properly rejected.
     */
    public function test_unauthenticated_user_cannot_access_protected_endpoints(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertUnauthorized();
    }

    /**
     * Tests that a user without required permission gets 403.
     */
    public function test_user_without_permission_gets_forbidden(): void
    {
        $tenant = Tenant::create([
            'name' => 'No Permission Test',
            'slug' => 'no-permission-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'No Permission Company',
            'legal_name' => 'No Permission Company LLC',
            'tax_id' => 'NOPERM123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create user WITHOUT any role
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'No Role User',
            'email' => 'no-role@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        // Create company membership (user has access to company but no permissions)
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Viewer,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        // User without role should get 403
        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders([
                'X-Company-ID' => $company->id,
            ])
            ->getJson('/api/v1/products');

        $response->assertForbidden();
    }
}
