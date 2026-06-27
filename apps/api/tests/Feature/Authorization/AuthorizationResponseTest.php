<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Permission denials must return a descriptive, i18n 403 envelope naming the
 * missing ability — never a bare Laravel "This action is unauthorized." — so an
 * onboarding tenant can self-remediate.
 */
final class AuthorizationResponseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Authz Tenant',
            'slug' => 'authz-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Authz Company',
            'legal_name' => 'Authz Company LLC',
            'tax_id' => 'AUTHZ123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    #[Test]
    public function denied_uom_view_returns_descriptive_403_naming_the_ability(): void
    {
        // The accountant role is intentionally NOT granted uom.view.
        $accountant = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Authz Accountant',
            'email' => 'authz-accountant@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $accountant->assignRole('accountant');

        UserCompanyMembership::create([
            'user_id' => $accountant->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Accountant,
        ]);

        $response = $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/v1/uom/units')
            ->assertStatus(403);

        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $response->assertJsonPath('error.ability', 'uom.view');
        $this->assertStringContainsString('uom.view', (string) $response->json('error.message'));
        $this->assertStringContainsString('Settings', (string) $response->json('error.message'));
    }
}
