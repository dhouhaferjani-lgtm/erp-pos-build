<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature test for RequireModule middleware on the four Workshop-family AutoSpecs
 * modules: scheduling, workshop-bundles, workshop-work-orders, workshop-technicians.
 *
 * Each of these routes groups must carry `module:Workshop` so that non-automotive
 * verticals (retail, pharmacy, restaurant, coffee_shop, fashion, parapharmacy)
 * receive a 403 even when authenticated. This test guards against accidental
 * removal of the middleware in any of the four route files.
 */
class WorkshopModuleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $retailTenant;

    private Company $retailCompany;

    private User $retailUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->retailTenant = Tenant::factory()->create([
            'vertical' => 'retail',
            'enabled_extras' => json_encode([]),
        ]);

        $this->retailCompany = Company::factory()->create([
            'tenant_id' => $this->retailTenant->id,
        ]);

        $this->retailUser = User::factory()->create([
            'tenant_id' => $this->retailTenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->retailUser->id,
            'company_id' => $this->retailCompany->id,
            'role' => 'admin',
        ]);
    }

    /**
     * @return array<string, array{method: string, uri: string}>
     */
    public static function workshopRouteProvider(): array
    {
        return [
            'scheduling_bays_index' => ['method' => 'GET', 'uri' => '/api/v1/scheduling/bays'],
            'scheduling_appointments_index' => ['method' => 'GET', 'uri' => '/api/v1/scheduling/appointments'],
            'scheduling_calendar_day' => ['method' => 'GET', 'uri' => '/api/v1/scheduling/calendar/day'],
            'services_index' => ['method' => 'GET', 'uri' => '/api/v1/services'],
            'service_categories_index' => ['method' => 'GET', 'uri' => '/api/v1/service-categories'],
            'workshop_bundles_index' => ['method' => 'GET', 'uri' => '/api/v1/workshop/bundles'],
            'workshop_work_orders_index' => ['method' => 'GET', 'uri' => '/api/v1/workshop/work-orders'],
            'workshop_technicians_index' => ['method' => 'GET', 'uri' => '/api/v1/workshop/technicians'],
        ];
    }

    /**
     * @dataProvider workshopRouteProvider
     */
    public function test_retail_vertical_cannot_access_workshop_route(string $method, string $uri): void
    {
        Sanctum::actingAs($this->retailUser);

        $response = $this->json($method, $uri, [], [
            'X-Company-Id' => $this->retailCompany->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Workshop' is not enabled for this business type",
        ]);
    }

    public function test_pharmacy_vertical_cannot_access_workshop_routes(): void
    {
        $pharmacyTenant = Tenant::factory()->create([
            'vertical' => 'pharmacy',
            'enabled_extras' => json_encode([]),
        ]);

        $pharmacyCompany = Company::factory()->create([
            'tenant_id' => $pharmacyTenant->id,
        ]);

        $pharmacyUser = User::factory()->create([
            'tenant_id' => $pharmacyTenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $pharmacyUser->id,
            'company_id' => $pharmacyCompany->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($pharmacyUser);

        $response = $this->getJson('/api/v1/scheduling/appointments', [
            'X-Company-Id' => $pharmacyCompany->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Workshop' is not enabled for this business type",
        ]);
    }

    public function test_restaurant_vertical_cannot_access_workshop_routes(): void
    {
        $restaurantTenant = Tenant::factory()->create([
            'vertical' => 'restaurant',
            'enabled_extras' => json_encode([]),
        ]);

        $restaurantCompany = Company::factory()->create([
            'tenant_id' => $restaurantTenant->id,
        ]);

        $restaurantUser = User::factory()->create([
            'tenant_id' => $restaurantTenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $restaurantUser->id,
            'company_id' => $restaurantCompany->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($restaurantUser);

        $response = $this->getJson('/api/v1/workshop/work-orders', [
            'X-Company-Id' => $restaurantCompany->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Workshop' is not enabled for this business type",
        ]);
    }

    public function test_parts_retailer_without_workshop_cannot_access_workshop_routes(): void
    {
        // parts_retailer is an automotive vertical but NOT part of the Workshop module
        // set (only mechanic, body_shop, car_glass have Workshop in default_modules).
        // Tire shops, parts retailers, and service stations should be 403'd on workshop.
        $partsTenant = Tenant::factory()->create([
            'vertical' => 'parts_retailer',
            'enabled_extras' => json_encode([]),
        ]);

        $partsCompany = Company::factory()->create([
            'tenant_id' => $partsTenant->id,
        ]);

        $partsUser = User::factory()->create([
            'tenant_id' => $partsTenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $partsUser->id,
            'company_id' => $partsCompany->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($partsUser);

        $response = $this->getJson('/api/v1/scheduling/bays', [
            'X-Company-Id' => $partsCompany->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => "Module 'Workshop' is not enabled for this business type",
        ]);
    }

    public function test_unauthenticated_user_receives_401_on_workshop_routes(): void
    {
        $response = $this->getJson('/api/v1/workshop/bundles');

        $response->assertStatus(401);
    }
}
