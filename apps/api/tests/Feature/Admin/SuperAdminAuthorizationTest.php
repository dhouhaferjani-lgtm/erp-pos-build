<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\SuperAdmin;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security tests for super admin authorization.
 *
 * These tests verify that the EnsureSuperAdmin middleware correctly
 * blocks unauthorized access to admin endpoints.
 */
class SuperAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private SuperAdmin $superAdmin;

    private Tenant $tenant;

    private User $tenantUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a super admin
        $this->superAdmin = SuperAdmin::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // Create a tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create a tenant user
        $this->tenantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant User',
            'email' => 'user@tenant.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
    }

    // ========================================
    // Dashboard Endpoint Tests
    // ========================================

    public function test_super_admin_can_access_admin_dashboard(): void
    {
        $token = $this->superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_tenants',
                    'active_tenants',
                    'trial_tenants',
                    'expired_tenants',
                    'total_users',
                    'total_companies',
                ],
            ]);
    }

    public function test_tenant_user_cannot_access_admin_dashboard(): void
    {
        $token = $this->tenantUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard');

        $response->assertForbidden()
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Access denied. Super admin privileges required.',
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_access_admin_dashboard(): void
    {
        $response = $this->getJson('/api/v1/admin/dashboard');

        $response->assertUnauthorized();
    }

    public function test_deactivated_super_admin_cannot_access_admin_dashboard(): void
    {
        $this->superAdmin->update(['is_active' => false]);
        $token = $this->superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard');

        $response->assertForbidden()
            ->assertJson([
                'error' => [
                    'code' => 'ACCOUNT_DEACTIVATED',
                    'message' => 'This admin account has been deactivated.',
                ],
            ]);
    }

    // ========================================
    // Tenants Endpoint Tests
    // ========================================

    public function test_super_admin_can_list_tenants(): void
    {
        $token = $this->superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/tenants');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_tenant_user_cannot_list_tenants(): void
    {
        $token = $this->tenantUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/tenants');

        $response->assertForbidden()
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Access denied. Super admin privileges required.',
                ],
            ]);
    }

    public function test_super_admin_can_view_tenant_details(): void
    {
        $token = $this->superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/tenants/{$this->tenant->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'tenant',
                    'stats',
                ],
            ]);
    }

    public function test_tenant_user_cannot_view_tenant_details(): void
    {
        $token = $this->tenantUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/tenants/{$this->tenant->id}");

        $response->assertForbidden();
    }

    // ========================================
    // Tenant Actions Tests
    // ========================================

    public function test_tenant_user_cannot_suspend_tenant(): void
    {
        $token = $this->tenantUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/suspend", [
                'reason' => 'Test suspension',
            ]);

        $response->assertForbidden();

        // Verify tenant was NOT suspended
        $this->tenant->refresh();
        $this->assertNotEquals(TenantStatus::Suspended, $this->tenant->status);
    }

    public function test_tenant_user_cannot_activate_tenant(): void
    {
        // First suspend the tenant
        $this->tenant->update(['status' => TenantStatus::Suspended]);

        $token = $this->tenantUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/activate");

        $response->assertForbidden();

        // Verify tenant was NOT activated
        $this->tenant->refresh();
        $this->assertEquals(TenantStatus::Suspended, $this->tenant->status);
    }

    // ========================================
    // Admin Auth Routes Tests
    // ========================================

    public function test_tenant_user_cannot_access_admin_me_endpoint(): void
    {
        $token = $this->tenantUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/auth/me');

        $response->assertForbidden();
    }

    public function test_super_admin_can_access_admin_me_endpoint(): void
    {
        $token = $this->superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.email', 'superadmin@test.com');
    }

    public function test_tenant_user_cannot_logout_from_admin(): void
    {
        $token = $this->tenantUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/auth/logout');

        $response->assertForbidden();
    }

    // ========================================
    // Audit Logs Tests
    // ========================================

    public function test_tenant_user_cannot_view_admin_audit_logs(): void
    {
        $token = $this->tenantUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/audit-logs');

        $response->assertForbidden();
    }

    public function test_super_admin_can_view_audit_logs(): void
    {
        $token = $this->superAdmin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/audit-logs');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ========================================
    // Rate Limiting Tests
    // ========================================

    public function test_admin_login_is_rate_limited(): void
    {
        // Make 6 login attempts (limit is 5)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => 'wrong@email.com',
                'password' => 'wrongpassword',
            ]);
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'wrong@email.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(429);
    }

    // ========================================
    // Super Admin Login Tests
    // ========================================

    public function test_super_admin_can_login(): void
    {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'superadmin@test.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'admin' => [
                        'id',
                        'name',
                        'email',
                        'role',
                    ],
                    'token',
                ],
            ]);
    }

    public function test_tenant_user_cannot_login_as_admin(): void
    {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'user@tenant.com',
            'password' => 'password123',
        ]);

        // Should fail because tenant users are not in super_admins table
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_inactive_super_admin_cannot_login(): void
    {
        $this->superAdmin->update(['is_active' => false]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'superadmin@test.com',
            'password' => 'password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
