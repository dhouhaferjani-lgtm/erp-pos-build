<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\UserManagement;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Application\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Application\Services\TenantLinkSigner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserActionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $targetUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company SARL',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        $this->targetUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Target User',
            'email' => 'target@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->targetUser->assignRole('operator');
    }

    // ========== ACTIVATE USER ==========

    public function test_can_activate_inactive_user(): void
    {
        $this->targetUser->update(['status' => UserStatus::Inactive]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/users/{$this->targetUser->id}/activate");

        $response->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'status' => UserStatus::Active->value,
        ]);
    }

    public function test_cannot_activate_already_active_user(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/users/{$this->targetUser->id}/activate");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'USER_ALREADY_ACTIVE');
    }

    public function test_unauthenticated_user_cannot_activate_user(): void
    {
        $this->targetUser->update(['status' => UserStatus::Inactive]);

        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/activate");

        $response->assertUnauthorized();
    }

    public function test_activate_is_logged_to_audit_trail(): void
    {
        $this->targetUser->update(['status' => UserStatus::Inactive]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/activate");

        $response->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'user.activated',
            'aggregate_type' => 'user',
            'aggregate_id' => $this->targetUser->id,
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    // ========== DEACTIVATE USER ==========

    public function test_can_deactivate_active_user(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate");

        $response->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'status' => UserStatus::Inactive->value,
        ]);
    }

    public function test_cannot_deactivate_already_inactive_user(): void
    {
        $this->targetUser->update(['status' => UserStatus::Inactive]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'USER_ALREADY_INACTIVE');
    }

    public function test_cannot_deactivate_self(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/users/{$this->adminUser->id}/deactivate");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'CANNOT_DEACTIVATE_SELF');
    }

    public function test_unauthenticated_user_cannot_deactivate_user(): void
    {
        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/deactivate");

        $response->assertUnauthorized();
    }

    public function test_deactivate_is_logged_to_audit_trail(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate");

        $response->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'user.deactivated',
            'aggregate_type' => 'user',
            'aggregate_id' => $this->targetUser->id,
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_deactivated_user_tokens_are_revoked(): void
    {
        // Create a token for the target user
        $this->targetUser->createToken('test-token');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->targetUser->id,
            'tokenable_type' => User::class,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate");

        $response->assertOk();

        // Tokens should be deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->targetUser->id,
            'tokenable_type' => User::class,
        ]);
    }

    /**
     * C-13(i). `pos_pin` was NEVER cleared on offboarding — only the explicit
     * `PATCH /users/{id}/pos-pin` with a null pin cleared it. A fired employee's
     * PIN hash therefore stayed on their row forever, and `setupPin`'s
     * tenant-wide, unscoped uniqueness scan (PosAuthController) walks EVERY user
     * holding a `pos_pin`, so a ghost PIN goes on refusing that value to a real
     * new operator.
     */
    public function test_deactivating_a_user_clears_their_pos_pin(): void
    {
        $this->targetUser->update(['pos_pin' => '1234']);
        $this->assertNotNull($this->targetUser->refresh()->pos_pin, 'Pre-condition: the target holds a PIN.');

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        $this->assertNull(
            $this->targetUser->refresh()->pos_pin,
            'Offboarding must take the POS PIN with it.'
        );

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'user.pos_pin_cleared',
            'aggregate_id' => $this->targetUser->id,
            'user_id' => $this->adminUser->id,
        ]);
    }

    public function test_deleting_a_user_clears_their_pos_pin(): void
    {
        $this->targetUser->update(['pos_pin' => '4321']);

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->deleteJson("/api/v1/users/{$this->targetUser->id}")
            ->assertOk();

        $this->assertNull($this->targetUser->refresh()->pos_pin);
    }

    /**
     * The PIN a cleared user held is a bcrypt hash — it cannot be restored, and
     * must not be. Reactivation restores the memberships the cascade revoked;
     * the operator is issued a NEW pin.
     */
    public function test_reactivating_a_user_does_not_restore_their_pos_pin(): void
    {
        $this->targetUser->update(['pos_pin' => '5678']);

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/activate")
            ->assertOk();

        $this->assertNull(
            $this->targetUser->refresh()->pos_pin,
            'A bcrypt PIN hash cannot be restored, and reactivation must not pretend otherwise.'
        );
    }

    /**
     * Idempotence: a user with no PIN must not emit a spurious clear event.
     */
    public function test_deactivating_a_user_without_a_pin_logs_no_pin_cleared_event(): void
    {
        $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseMissing('audit_events', [
            'event_type' => 'user.pos_pin_cleared',
            'aggregate_id' => $this->targetUser->id,
        ]);
    }

    // ========== RESET PASSWORD ==========

    public function test_can_trigger_password_reset(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/users/{$this->targetUser->id}/reset-password");

        $response->assertOk()
            ->assertJsonPath('data.message', 'Password reset email sent');

        // P1-2: admin-triggered reset must use the tenant-qualified notification,
        // NOT the stock Illuminate ResetPassword (which carries no tenant param).
        Notification::assertSentTo(
            $this->targetUser,
            ResetPasswordNotification::class
        );
        Notification::assertNotSentTo($this->targetUser, ResetPassword::class);
    }

    public function test_admin_reset_link_carries_a_decryptable_tenant_qualifier(): void
    {
        Notification::fake();

        $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/users/{$this->targetUser->id}/reset-password")
            ->assertOk();

        $tenantId = $this->tenant->id;

        Notification::assertSentTo(
            $this->targetUser,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $n) use ($tenantId): bool {
                $url = $n->toMail($this->targetUser)->actionUrl;
                $query = parse_url($url, PHP_URL_QUERY) ?: '';
                parse_str($query, $params);
                $signed = $params['tenant'] ?? null;

                if (! is_string($signed)) {
                    return false;
                }

                return app(TenantLinkSigner::class)
                    ->extract($signed) === $tenantId;
            }
        );
    }

    public function test_unauthenticated_user_cannot_trigger_password_reset(): void
    {
        $response = $this->postJson("/api/v1/users/{$this->targetUser->id}/reset-password");

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_trigger_password_reset(): void
    {
        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->postJson("/api/v1/users/{$this->targetUser->id}/reset-password");

        $response->assertForbidden();
    }

    public function test_cannot_trigger_password_reset_for_user_from_different_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/v1/users/{$otherUser->id}/reset-password");

        $response->assertNotFound();
    }

    public function test_password_reset_is_logged_to_audit_trail(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/users/{$this->targetUser->id}/reset-password");

        $response->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'user.password_reset_triggered',
            'aggregate_type' => 'user',
            'aggregate_id' => $this->targetUser->id,
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    // ========== GET SINGLE USER ==========

    public function test_can_get_single_user(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/v1/users/{$this->targetUser->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'phone',
                    'status',
                    'locale',
                    'timezone',
                    'roles',
                    'emailVerifiedAt',
                    'lastLoginAt',
                    'lastLoginIp',
                    'createdAt',
                    'updatedAt',
                ],
                'meta',
            ])
            ->assertJsonPath('data.id', $this->targetUser->id)
            ->assertJsonPath('data.email', 'target@example.com');
    }

    public function test_cannot_get_user_from_different_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/v1/users/{$otherUser->id}");

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_get_user(): void
    {
        $response = $this->getJson("/api/v1/users/{$this->targetUser->id}");

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_get_user(): void
    {
        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->getJson("/api/v1/users/{$this->targetUser->id}");

        $response->assertForbidden();
    }

    public function test_get_returns_404_for_nonexistent_user(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/users/00000000-0000-0000-0000-000000000000');

        $response->assertNotFound();
    }
}
