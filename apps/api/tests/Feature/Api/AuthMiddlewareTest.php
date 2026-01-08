<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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

class AuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_config_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/company/config');

        $response->assertStatus(401);
    }

    public function test_company_config_returns_data_when_authenticated(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'settings' => [],
        ]);

        // Create company
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

        // Set permissions team context and seed roles/permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create user
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        // Create membership
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
            'status' => MembershipStatus::Active,
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->getJson('/api/v1/company/config');

        $response->assertStatus(200);
    }

    public function test_subscription_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/subscription');

        $response->assertStatus(401);
    }

    public function test_subscription_returns_data_when_authenticated(): void
    {
        // Create tenant
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company-2',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'settings' => [],
        ]);

        // Create company
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

        // Set permissions team context and seed roles/permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create user
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'test2@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        // Create membership
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
            'status' => MembershipStatus::Active,
        ]);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Company-Id' => $company->id])
            ->getJson('/api/v1/subscription');

        $response->assertStatus(200);
    }
}
