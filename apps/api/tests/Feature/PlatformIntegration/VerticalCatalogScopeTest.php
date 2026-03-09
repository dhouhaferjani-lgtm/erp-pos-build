<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformIntegration;

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
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VerticalCatalogScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-api-key']);
    }

    /** @test */
    public function it_passes_tire_shop_vertical_to_platform_api(): void
    {
        $this->setupTenantContext(Vertical::TireShop, 'tire-shop-test');

        Http::fake([
            'platform.test/*' => Http::response([
                'data' => [
                    ['id' => 'mfr-001', 'name' => 'Michelin'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->getUser(), 'sanctum')
            ->getJson('/api/v1/platform/catalog/manufacturers');

        $response->assertStatus(200);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_contains($request->url(), 'vertical=tire_shop');
        });
    }

    /** @test */
    public function it_passes_car_glass_vertical_to_platform_api(): void
    {
        $this->setupTenantContext(Vertical::CarGlass, 'car-glass-test');

        Http::fake([
            'platform.test/*' => Http::response([
                'data' => [
                    ['id' => 'mfr-001', 'name' => 'Pilkington'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->getUser(), 'sanctum')
            ->getJson('/api/v1/platform/catalog/manufacturers');

        $response->assertStatus(200);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_contains($request->url(), 'vertical=car_glass');
        });
    }

    /** @test */
    public function it_does_not_pass_vertical_for_general_automotive_tenant(): void
    {
        $this->setupTenantContext(Vertical::Mechanic, 'mechanic-test');

        Http::fake([
            'platform.test/*' => Http::response([
                'data' => [
                    ['id' => 'mfr-001', 'name' => 'Bosch'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->getUser(), 'sanctum')
            ->getJson('/api/v1/platform/catalog/manufacturers');

        $response->assertStatus(200);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return ! str_contains($request->url(), 'vertical=');
        });
    }

    private User $currentUser;

    private function getUser(): User
    {
        return $this->currentUser;
    }

    private function setupTenantContext(Vertical $vertical, string $slug): void
    {
        $tenant = Tenant::create([
            'name' => "Test {$vertical->label()}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => $vertical,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Test {$vertical->label()} Company",
            'legal_name' => "Test {$vertical->label()} Company SARL",
            'tax_id' => 'TAX-'.strtoupper($slug),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->currentUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => "{$slug}@test.tn",
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->currentUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->currentUser->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);
    }
}
