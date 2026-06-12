<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature test for RequireModule middleware protecting the Parapharmacy
 * master-data routes (ingredients, certifications, health claims, key
 * components) behind the Parapharmacy module.
 *
 * Verifies end-to-end that:
 * 1. Tenants whose vertical does not include Parapharmacy get 403
 * 2. Parapharmacy-vertical tenants (Parapharmacy in default modules)
 *    retain full access
 */
final class ParapharmacyModuleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private const PARAPHARMACY_INDEX_ROUTES = [
        '/api/v1/parapharmacy/ingredients',
        '/api/v1/parapharmacy/certifications',
        '/api/v1/parapharmacy/health-claims',
        '/api/v1/parapharmacy/key-components',
    ];

    private Tenant $retailTenant;

    private Company $retailCompany;

    private User $retailUser;

    private Tenant $parapharmacyTenant;

    private Company $parapharmacyCompany;

    private User $parapharmacyUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Retail tenant: vertical does NOT include the Parapharmacy module.
        $this->retailTenant = Tenant::create([
            'name' => 'Retail Tenant',
            'slug' => 'retail-tenant-ppm',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
            'enabled_extras' => [],
        ]);
        $this->retailCompany = $this->makeCompany($this->retailTenant->id, 'Retail Company');
        $this->retailUser = $this->makeUser($this->retailTenant->id, 'retail@ppm.test', $this->retailCompany);

        // Parapharmacy tenant: Parapharmacy is in the vertical's default modules.
        $this->parapharmacyTenant = Tenant::create([
            'name' => 'Parapharmacy Tenant',
            'slug' => 'parapharmacy-tenant-ppm',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
            'enabled_extras' => [],
        ]);
        $this->parapharmacyCompany = $this->makeCompany($this->parapharmacyTenant->id, 'Parapharmacy Company');
        $this->parapharmacyUser = $this->makeUser($this->parapharmacyTenant->id, 'para@ppm.test', $this->parapharmacyCompany);

        // The routes also carry can:settings.manage — grant it to both users
        // so the only difference under test is the module gate.
        Permission::create(['name' => 'settings.manage', 'guard_name' => 'sanctum']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->retailTenant->id);
        $this->retailUser->givePermissionTo('settings.manage');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->parapharmacyTenant->id);
        $this->parapharmacyUser->givePermissionTo('settings.manage');
    }

    public function test_retail_user_cannot_access_parapharmacy_master_data_routes(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->retailTenant->id);
        app(CompanyContext::class)->setCompanyId($this->retailCompany->id);

        foreach (self::PARAPHARMACY_INDEX_ROUTES as $route) {
            $response = $this->actingAs($this->retailUser, 'sanctum')->getJson($route);

            $response->assertStatus(403);
            $response->assertJson([
                'message' => "Module 'Parapharmacy' is not enabled for this business type",
            ]);
        }
    }

    public function test_retail_user_cannot_create_parapharmacy_master_data(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->retailTenant->id);
        app(CompanyContext::class)->setCompanyId($this->retailCompany->id);

        $response = $this->actingAs($this->retailUser, 'sanctum')
            ->postJson('/api/v1/parapharmacy/ingredients', [
                'slug' => 'vitamin-c',
                'is_allergen' => false,
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Parapharmacy' is not enabled for this business type",
        ]);
    }

    public function test_parapharmacy_user_can_access_parapharmacy_master_data_routes(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->parapharmacyTenant->id);
        app(CompanyContext::class)->setCompanyId($this->parapharmacyCompany->id);

        foreach (self::PARAPHARMACY_INDEX_ROUTES as $route) {
            $response = $this->actingAs($this->parapharmacyUser, 'sanctum')->getJson($route);

            $response->assertStatus(200);
        }
    }

    private function makeCompany(string $tenantId, string $name): Company
    {
        return Company::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => 'TAX-'.substr(md5($name), 0, 8),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeUser(string $tenantId, string $email, Company $company): User
    {
        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => 'Test User',
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
        ]);

        return $user;
    }
}
