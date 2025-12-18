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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class UpdateUserTest extends TestCase
{
    use AssertsApiValidation;
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

    public function test_unauthenticated_user_cannot_update_user(): void
    {
        $response = $this->patchJson("/api/v1/users/{$this->targetUser->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertUnauthorized();
    }

    public function test_can_update_user_name(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_can_update_user_email(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'email' => 'newemail@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'newemail@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'email' => 'newemail@example.com',
        ]);
    }

    public function test_cannot_update_email_to_existing_email_in_tenant(): void
    {
        User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'email' => 'existing@example.com',
            ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_can_update_user_phone(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'phone' => '+33612345678',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.phone', '+33612345678');
    }

    public function test_phone_format_validation(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'phone' => 'invalid-phone',
            ]);

        $this->assertApiValidationErrors($response, ['phone']);
    }

    public function test_can_update_user_locale(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'locale' => 'fr',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'locale' => 'fr',
        ]);
    }

    public function test_can_update_user_timezone(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'timezone' => 'Europe/Paris',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'timezone' => 'Europe/Paris',
        ]);
    }

    public function test_can_update_user_role(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'role' => 'manager',
            ]);

        $response->assertOk();

        $this->targetUser->refresh();
        $this->assertTrue($this->targetUser->hasRole('manager'));
        $this->assertFalse($this->targetUser->hasRole('operator'));
    }

    public function test_role_must_exist(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'role' => 'nonexistent-role',
            ]);

        $this->assertApiValidationErrors($response, ['role']);
    }

    public function test_cannot_update_user_from_different_tenant(): void
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
            ->patchJson("/api/v1/users/{$otherUser->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertNotFound();
    }

    public function test_user_without_permission_cannot_update_user(): void
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
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertForbidden();
    }

    public function test_update_returns_404_for_nonexistent_user(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson('/api/v1/users/00000000-0000-0000-0000-000000000000', [
                'name' => 'Updated Name',
            ]);

        $response->assertNotFound();
    }

    public function test_update_is_logged_to_audit_trail(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'name' => 'Audited Update',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'user.updated',
            'aggregate_type' => 'user',
            'aggregate_id' => $this->targetUser->id,
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_can_update_multiple_fields_at_once(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->targetUser->id}", [
                'name' => 'Complete Update',
                'email' => 'complete@example.com',
                'phone' => '+33698765432',
                'locale' => 'en',
                'timezone' => 'America/New_York',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Complete Update')
            ->assertJsonPath('data.email', 'complete@example.com')
            ->assertJsonPath('data.phone', '+33698765432');

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'name' => 'Complete Update',
            'email' => 'complete@example.com',
            'phone' => '+33698765432',
            'locale' => 'en',
            'timezone' => 'America/New_York',
        ]);
    }
}
