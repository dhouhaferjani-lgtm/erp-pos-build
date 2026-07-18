<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\UserManagement;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Application\Notifications\UserInvitation;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class CreateUserTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

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
    }

    public function test_unauthenticated_user_cannot_create_user(): void
    {
        $response = $this->postJson('/api/v1/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'role' => 'operator',
        ]);

        $response->assertUnauthorized();
    }

    public function test_name_is_required(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'email' => 'newuser@example.com',
                'role' => 'operator',
            ]);

        $this->assertApiValidationErrors($response, ['name']);
    }

    public function test_email_is_required(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'role' => 'operator',
            ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_email_must_be_valid(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'invalid-email',
                'role' => 'operator',
            ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_email_must_be_unique_within_tenant(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'existing@example.com',
                'role' => 'operator',
            ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_same_email_allowed_in_different_tenants(): void
    {
        // Create user in another tenant with same email
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'User in Other Tenant',
            'email' => 'shared@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'shared@example.com',
                'role' => 'operator',
            ]);

        $response->assertCreated();
    }

    public function test_role_is_required(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
            ]);

        $this->assertApiValidationErrors($response, ['role']);
    }

    public function test_role_must_exist(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'role' => 'nonexistent-role',
            ]);

        $this->assertApiValidationErrors($response, ['role']);
    }

    public function test_phone_format_validation(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'role' => 'operator',
                'phone' => 'invalid-phone',
            ]);

        $this->assertApiValidationErrors($response, ['phone']);
    }

    public function test_successful_creation_returns_201(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'phone' => '+33612345678',
                'role' => 'operator',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'phone',
                    'status',
                    'roles',
                    'createdAt',
                ],
                'meta',
            ])
            ->assertJsonPath('data.name', 'New User')
            ->assertJsonPath('data.email', 'newuser@example.com')
            ->assertJsonPath('data.status', 'pending_verification');

        $this->assertDatabaseHas('users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'tenant_id' => $this->tenant->id,
            'status' => UserStatus::PendingVerification->value,
        ]);
    }

    public function test_new_user_is_assigned_role(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'role' => 'operator',
            ]);

        $response->assertCreated();

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertTrue($user->hasRole('operator'));
    }

    public function test_store_creates_null_membership_for_new_staff(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/users', [
                'name' => 'New Cashier',
                'role' => 'cashier',
                'phone' => '+33612345678',
            ]);

        $response->assertCreated();
        $userId = $response->json('data.id');

        $membership = UserCompanyMembership::where('user_id', $userId)
            ->where('company_id', $this->company->id)
            ->first();

        $this->assertNotNull($membership);
        $this->assertNull($membership->allowed_location_ids);
        $this->assertSame(MembershipRole::Cashier, $membership->role);
        $this->assertTrue($membership->is_primary);
        $this->assertSame(MembershipStatus::Active, $membership->status);
    }

    public function test_new_user_receives_invitation_email(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'role' => 'operator',
            ]);

        $response->assertCreated();

        $user = User::where('email', 'newuser@example.com')->first();

        // Verify invitation notification was sent
        Notification::assertSentTo(
            $user,
            UserInvitation::class
        );
    }

    public function test_user_without_permission_cannot_create_user(): void
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
            ->postJson('/api/v1/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'role' => 'operator',
            ]);

        $response->assertForbidden();
    }

    public function test_can_set_optional_locale(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'French User',
                'email' => 'french@example.com',
                'role' => 'operator',
                'locale' => 'fr',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'french@example.com',
            'locale' => 'fr',
        ]);
    }

    public function test_can_set_optional_timezone(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Paris User',
                'email' => 'paris@example.com',
                'role' => 'operator',
                'timezone' => 'Europe/Paris',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'paris@example.com',
            'timezone' => 'Europe/Paris',
        ]);
    }

    public function test_can_create_cashier_without_email(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'POS Cashier',
                'role' => 'cashier',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'name' => 'POS Cashier',
            'email' => null,
            'status' => 'active',
        ]);
    }

    public function test_non_cashier_requires_email(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'New Operator',
                'role' => 'operator',
            ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_creation_is_logged_to_audit_trail(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/users', [
                'name' => 'Audited User',
                'email' => 'audited@example.com',
                'role' => 'operator',
            ]);

        $response->assertCreated();

        $user = User::where('email', 'audited@example.com')->first();

        // Verify audit trail entry exists with company context
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'user.created',
            'aggregate_type' => 'user',
            'aggregate_id' => $user->id,
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }
}
