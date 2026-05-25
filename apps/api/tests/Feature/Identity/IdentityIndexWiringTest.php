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
use App\Modules\Tenant\Domain\CentralIdentity;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IdentityIndexWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_writes_central_identity_and_domains_rows(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CountriesSeeder::class);
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Owner',
            'email' => 'jane@newco.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'company_name' => 'New Co',
            'country_code' => 'FR',
            'vertical' => 'retail',
        ]);

        $response->assertCreated();

        $tenant = Tenant::where('name', 'New Co')->firstOrFail();
        $user = User::where('email', 'jane@newco.com')->firstOrFail();

        $this->assertDatabaseHas('central_identities', [
            'email' => 'jane@newco.com',
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);

        // register must also create the Stancl domains row for the optional
        // subdomain shortcut: domain = "{slug}.synerivia.tn".
        $this->assertDatabaseHas('domains', [
            'tenant_id' => $tenant->id,
            'domain' => strtolower($tenant->slug).'.synerivia.tn',
        ]);
    }

    public function test_invited_user_with_email_is_written_to_the_index(): void
    {
        [$tenant, $admin] = $this->makeTenantWithAdmin();
        Notification::fake();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Invited Bob',
            'email' => 'bob@team.com',
            'role' => 'operator',
        ]);

        $response->assertCreated();
        $invited = User::where('email', 'bob@team.com')->firstOrFail();

        $this->assertDatabaseHas('central_identities', [
            'email' => 'bob@team.com',
            'tenant_id' => $tenant->id,
            'user_id' => $invited->id,
        ]);
    }

    public function test_pin_only_invited_user_without_email_is_not_indexed(): void
    {
        [$tenant, $admin] = $this->makeTenantWithAdmin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Cashier Carla',
            'role' => 'cashier',
        ]);

        $response->assertCreated();

        $this->assertSame(
            1, // only the admin's own identity row
            CentralIdentity::where('tenant_id', $tenant->id)->count()
        );
    }

    public function test_email_change_moves_the_index_row(): void
    {
        [$tenant, $admin] = $this->makeTenantWithAdmin();
        $target = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Old Email User',
            'email' => 'old@team.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        app(\App\Modules\Tenant\Application\Services\IdentityIndexService::class)
            ->record('old@team.com', $tenant->id, $target->id);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/users/{$target->id}", [
            'email' => 'new@team.com',
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('central_identities', [
            'email' => 'old@team.com',
            'tenant_id' => $tenant->id,
        ]);
        $this->assertDatabaseHas('central_identities', [
            'email' => 'new@team.com',
            'tenant_id' => $tenant->id,
            'user_id' => $target->id,
        ]);
    }

    public function test_deactivation_removes_the_index_row(): void
    {
        [$tenant, $admin] = $this->makeTenantWithAdmin();
        $target = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'To Delete',
            'email' => 'delete@team.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        app(\App\Modules\Tenant\Application\Services\IdentityIndexService::class)
            ->record('delete@team.com', $tenant->id, $target->id);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$target->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('central_identities', [
            'email' => 'delete@team.com',
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeTenantWithAdmin(): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
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

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $admin->assignRole('admin');
        app(\App\Modules\Tenant\Application\Services\IdentityIndexService::class)
            ->record('admin@example.com', $tenant->id, $admin->id);

        UserCompanyMembership::create([
            'user_id' => $admin->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Owner,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        return [$tenant, $admin];
    }
}
