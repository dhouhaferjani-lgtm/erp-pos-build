<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Facades\GlobalCache;
use Tests\TestCase;

/**
 * Feature tests for Company Config API endpoint.
 *
 * Tests the GET /api/v1/company/config endpoint that returns
 * effective configuration for the current user's tenant.
 *
 * This endpoint is used by the frontend to:
 * - Determine which modules are enabled
 * - Render dynamic navigation
 * - Show/hide features based on vertical
 */
class CompanyConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $mechanicTenant;

    private Tenant $pharmacyTenant;

    private Company $mechanicCompany;

    private Company $pharmacyCompany;

    private User $mechanicUser;

    private User $pharmacyUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mechanic tenant (has Vehicle, Workshop modules)
        $this->mechanicTenant = Tenant::factory()->create([
            'vertical' => 'mechanic',
            'enabled_extras' => json_encode([]),
        ]);

        $this->mechanicCompany = Company::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
        ]);

        $this->mechanicUser = User::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->mechanicUser->id,
            'company_id' => $this->mechanicCompany->id,
            'role' => 'admin',
        ]);

        // Create pharmacy tenant (has BatchExpiry module)
        $this->pharmacyTenant = Tenant::factory()->create([
            'vertical' => 'pharmacy',
            'enabled_extras' => json_encode([]),
        ]);

        $this->pharmacyCompany = Company::factory()->create([
            'tenant_id' => $this->pharmacyTenant->id,
        ]);

        $this->pharmacyUser = User::factory()->create([
            'tenant_id' => $this->pharmacyTenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->pharmacyUser->id,
            'company_id' => $this->pharmacyCompany->id,
            'role' => 'admin',
        ]);
    }

    public function test_returns_config_for_mechanic_tenant(): void
    {
        Sanctum::actingAs($this->mechanicUser);

        $response = $this->getJson('/api/v1/company/config');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'vertical',
                'default_modules',
                'enabled_extras',
                'all_enabled_modules',
            ],
        ]);

        $response->assertJson([
            'data' => [
                'vertical' => 'mechanic',
                'enabled_extras' => [],
            ],
        ]);

        // Verify default modules for mechanic vertical
        $data = $response->json('data');
        $this->assertContains('Identity', $data['all_enabled_modules']);
        $this->assertContains('Vehicle', $data['all_enabled_modules']);
        $this->assertContains('Workshop', $data['all_enabled_modules']);
    }

    public function test_line_designation_override_comes_from_primary_company_setting(): void
    {
        UserCompanyMembership::query()
            ->where('user_id', $this->mechanicUser->id)
            ->where('company_id', $this->mechanicCompany->id)
            ->update([
                'is_primary' => true,
                'status' => 'active',
            ]);

        $this->mechanicCompany->update(['line_designation_override_enabled' => true]);

        Sanctum::actingAs($this->mechanicUser);

        $this->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.line_designation_override_enabled', true);

        $this->mechanicCompany->update(['line_designation_override_enabled' => false]);

        $this->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.line_designation_override_enabled', false);
    }

    public function test_returns_config_for_pharmacy_tenant(): void
    {
        Sanctum::actingAs($this->pharmacyUser);

        $response = $this->getJson('/api/v1/company/config');

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'vertical' => 'pharmacy',
                'enabled_extras' => [],
            ],
        ]);

        // Verify default modules for pharmacy vertical
        $data = $response->json('data');
        $this->assertContains('Identity', $data['all_enabled_modules']);
        $this->assertContains('BatchExpiry', $data['all_enabled_modules']);
        // Pharmacy should NOT have Vehicle module
        $this->assertNotContains('Vehicle', $data['all_enabled_modules']);
    }

    public function test_platform_import_enrichment_capability_requires_key_and_supported_vertical(): void
    {
        Sanctum::actingAs($this->mechanicUser);
        config(['services.platform.api_key' => 'platform-key']);

        $this->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.platform_import_enrichment_available', true);

        config(['services.platform.api_key' => '   ']);
        $this->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.platform_import_enrichment_available', false);

        config(['services.platform.api_key' => 'platform-key']);
        $this->mechanicTenant->update(['vertical' => 'retail']);
        $this->getJson('/api/v1/company/config')
            ->assertOk()
            ->assertJsonPath('data.platform_import_enrichment_available', false);
    }

    public function test_includes_enabled_extras_in_config(): void
    {
        // Update mechanic tenant to have Fleet extra enabled
        $this->mechanicTenant->update([
            'enabled_extras' => json_encode(['Fleet', 'Appointments']),
        ]);

        Sanctum::actingAs($this->mechanicUser);

        $response = $this->getJson('/api/v1/company/config');

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'enabled_extras' => ['Fleet', 'Appointments'],
            ],
        ]);

        // Verify extras are included in all_enabled_modules
        $data = $response->json('data');
        $this->assertContains('Fleet', $data['all_enabled_modules']);
        $this->assertContains('Appointments', $data['all_enabled_modules']);
    }

    public function test_requires_authentication(): void
    {
        // No authentication
        $response = $this->getJson('/api/v1/company/config');

        $response->assertStatus(401);
    }

    public function test_uses_caching(): void
    {
        Sanctum::actingAs($this->mechanicUser);

        // First request - cache miss
        $response1 = $this->getJson('/api/v1/company/config');
        $response1->assertStatus(200);

        // Verify cache was set
        $cacheKey = "tenant_config:{$this->mechanicTenant->id}";
        $this->assertTrue(GlobalCache::has($cacheKey), 'Cache should be set after first request');

        // Second request - should hit cache
        $response2 = $this->getJson('/api/v1/company/config');
        $response2->assertStatus(200);

        // Responses should be identical
        $this->assertEquals($response1->json('data'), $response2->json('data'));
    }

    public function test_cache_is_invalidated_when_vertical_changes(): void
    {
        Sanctum::actingAs($this->mechanicUser);

        // First request
        $response1 = $this->getJson('/api/v1/company/config');
        $response1->assertStatus(200);
        $response1->assertJson(['data' => ['vertical' => 'mechanic']]);

        // Change vertical
        $this->mechanicTenant->update(['vertical' => 'retail']);

        // Second request - should get new config (cache invalidated)
        $response2 = $this->getJson('/api/v1/company/config');
        $response2->assertStatus(200);
        $response2->assertJson(['data' => ['vertical' => 'retail']]);

        // Verify configs are different
        $this->assertNotEquals($response1->json('data'), $response2->json('data'));
    }

    public function test_cache_is_invalidated_when_enabled_extras_changes(): void
    {
        Sanctum::actingAs($this->mechanicUser);

        // First request - no extras
        $response1 = $this->getJson('/api/v1/company/config');
        $response1->assertStatus(200);
        $response1->assertJson(['data' => ['enabled_extras' => []]]);

        // Add extras
        $this->mechanicTenant->update([
            'enabled_extras' => json_encode(['Fleet']),
        ]);

        // Second request - should include Fleet (cache invalidated)
        $response2 = $this->getJson('/api/v1/company/config');
        $response2->assertStatus(200);
        $response2->assertJson(['data' => ['enabled_extras' => ['Fleet']]]);

        // Verify Fleet is in all_enabled_modules
        $data = $response2->json('data');
        $this->assertContains('Fleet', $data['all_enabled_modules']);
    }

    public function test_returns_same_config_for_different_users_in_same_tenant(): void
    {
        // Create second user in same tenant
        $secondUser = User::factory()->create([
            'tenant_id' => $this->mechanicTenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $secondUser->id,
            'company_id' => $this->mechanicCompany->id,
            'role' => 'viewer',
        ]);

        // First user request
        Sanctum::actingAs($this->mechanicUser);
        $response1 = $this->getJson('/api/v1/company/config');

        // Second user request
        Sanctum::actingAs($secondUser);
        $response2 = $this->getJson('/api/v1/company/config');

        // Both should get same config (same tenant)
        $this->assertEquals($response1->json('data'), $response2->json('data'));
    }

    public function test_returns_different_config_for_different_tenants(): void
    {
        // Mechanic user request
        Sanctum::actingAs($this->mechanicUser);
        $mechanicResponse = $this->getJson('/api/v1/company/config');

        // Pharmacy user request
        Sanctum::actingAs($this->pharmacyUser);
        $pharmacyResponse = $this->getJson('/api/v1/company/config');

        // Configs should be different
        $this->assertNotEquals($mechanicResponse->json('data'), $pharmacyResponse->json('data'));

        // Verify vertical is different
        $this->assertEquals('mechanic', $mechanicResponse->json('data.vertical'));
        $this->assertEquals('pharmacy', $pharmacyResponse->json('data.vertical'));
    }
}
