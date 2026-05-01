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
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Coverage for PATCH /api/v1/users/{id}/pos-pin (UserController::setPosPin).
 *
 * The endpoint backs the admin-driven PIN reset flow on the users page.
 * Item #4 of the user-management bug bundle (project_user_management_bugs).
 */
class SetPosPinTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $cashierUser;

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

        $this->cashierUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier User',
            'email' => null,
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->cashierUser->assignRole('cashier');
    }

    public function test_admin_can_set_a_pin_and_it_is_hashed(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => '4321',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.message', 'POS PIN updated');

        $this->cashierUser->refresh();
        $this->assertNotNull($this->cashierUser->pos_pin);
        $this->assertNotSame('4321', $this->cashierUser->pos_pin);
        $this->assertTrue(Hash::check('4321', $this->cashierUser->pos_pin));
    }

    public function test_admin_can_clear_an_existing_pin(): void
    {
        $this->cashierUser->update(['pos_pin' => '1234']);
        $this->assertNotNull($this->cashierUser->fresh()->pos_pin);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.message', 'POS PIN cleared');

        $this->assertNull($this->cashierUser->fresh()->pos_pin);
    }

    public function test_pin_must_be_4_to_6_digits(): void
    {
        $tooShort = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => '12',
            ]);
        $tooShort->assertUnprocessable();

        $tooLong = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => '1234567',
            ]);
        $tooLong->assertUnprocessable();

        $notDigits = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => 'abcd',
            ]);
        $notDigits->assertUnprocessable();
    }

    public function test_pin_must_be_unique_within_tenant(): void
    {
        $other = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Cashier',
            'email' => null,
            'password' => 'Password1!',
            'pos_pin' => '5678',
            'status' => UserStatus::Active,
        ]);
        $other->assignRole('cashier');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => '5678',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'PIN_ALREADY_IN_USE');
    }

    public function test_same_pin_can_be_reused_in_a_different_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Tenant Cashier',
            'email' => null,
            'password' => 'Password1!',
            'pos_pin' => '5678',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => '5678',
            ]);

        $response->assertOk();
    }

    public function test_unauthenticated_user_cannot_set_pin(): void
    {
        $response = $this->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
            'pin' => '1234',
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_set_pin(): void
    {
        $viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $viewer->assignRole('viewer');

        $response = $this->actingAs($viewer, 'sanctum')
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => '1234',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_set_pin_for_user_in_different_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $foreignUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Foreign User',
            'email' => 'foreign@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->patchJson("/api/v1/users/{$foreignUser->id}/pos-pin", [
                'pin' => '1234',
            ]);

        $response->assertNotFound();
    }

    public function test_set_pin_is_logged_to_audit_trail(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => '4321',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'user.pos_pin_set',
            'aggregate_type' => 'user',
            'aggregate_id' => $this->cashierUser->id,
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_cleared_pin_is_logged_to_audit_trail(): void
    {
        $this->cashierUser->update(['pos_pin' => '1234']);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/users/{$this->cashierUser->id}/pos-pin", [
                'pin' => null,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'user.pos_pin_cleared',
            'aggregate_type' => 'user',
            'aggregate_id' => $this->cashierUser->id,
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }
}
