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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature test for RequireModule middleware protecting the Menu routes behind
 * the Menu module. Menu is a default module only for the F&B verticals
 * (restaurant, coffee_shop); a non-Menu vertical (e.g. retail) must be blocked
 * end-to-end with 403.
 */
final class MenuModuleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an authenticated tenant context for the given vertical.
     *
     * @return array{user: User, company: Company}
     */
    private function makeContext(Vertical $vertical, string $slug): array
    {
        $tenant = Tenant::create([
            'name' => "Test {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => $vertical,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Test {$slug} Company",
            'legal_name' => "Test {$slug} Company LLC",
            'tax_id' => 'TAX'.strtoupper($slug),
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
            'name' => 'Test User',
            'email' => "user@{$slug}.test",
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        return ['user' => $user, 'company' => $company];
    }

    /** @test */
    public function a_non_menu_vertical_is_blocked_from_menu_routes_with_403(): void
    {
        $ctx = $this->makeContext(Vertical::Retail, 'retail-menu');

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/menus');

        $response->assertStatus(403);
    }

    /** @test */
    public function a_menu_vertical_can_reach_menu_routes(): void
    {
        $ctx = $this->makeContext(Vertical::Restaurant, 'restaurant-menu');

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/menus');

        $response->assertSuccessful();
    }
}
