<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartnerPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Tenant $tenant;

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
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['partners.view', 'partners.create', 'partners.update', 'partners.delete']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_can_list_partners_with_offset_pagination(): void
    {
        Partner::factory()->count(30)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'type'],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                ],
            ]);

        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(3, $response->json('meta.last_page'));
        $this->assertEquals(10, $response->json('meta.per_page'));
        $this->assertEquals(30, $response->json('meta.total'));
    }

    public function test_can_navigate_to_next_page(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            Partner::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => sprintf('Partner %02d', $i),
            ]);
        }

        // Get first page
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1);

        // Get second page
        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/partners?per_page=10&page=2');

        $response2->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2);

        // Should have different partners
        $firstPageIds = collect($response->json('data'))->pluck('id')->toArray();
        $secondPageIds = collect($response2->json('data'))->pluck('id')->toArray();

        $this->assertEmpty(array_intersect($firstPageIds, $secondPageIds));
    }

    public function test_last_page_returns_remaining_items(): void
    {
        Partner::factory()->count(25)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners?per_page=25');

        $response->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', 25);
    }

    public function test_respects_per_page_parameter(): void
    {
        Partner::factory()->count(50)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners?per_page=15');

        $response->assertOk()
            ->assertJsonCount(15, 'data');

        $this->assertEquals(15, $response->json('meta.per_page'));
        $this->assertEquals(50, $response->json('meta.total'));
    }

    public function test_default_per_page_is_25(): void
    {
        Partner::factory()->count(30)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners');

        $response->assertOk()
            ->assertJsonCount(25, 'data');

        $this->assertEquals(25, $response->json('meta.per_page'));
        $this->assertEquals(30, $response->json('meta.total'));
    }

    public function test_pagination_works_with_search_filter(): void
    {
        Partner::factory()->count(20)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Garage Auto',
        ]);

        Partner::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Company',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners?search=garage&per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_pagination_works_with_type_filter(): void
    {
        Partner::factory()->count(15)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'customer',
        ]);

        Partner::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'supplier',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners?type=customer&per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_pagination_works_with_active_filter(): void
    {
        Partner::factory()->count(20)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        Partner::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners?is_active=1&per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_empty_results_return_empty_array(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_partners_are_isolated_by_company(): void
    {
        // Create partners for our company
        Partner::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Create partners for another company
        $otherCompany = Company::factory()->for($this->tenant)->create();
        Partner::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 5);
    }

    public function test_performance_with_large_dataset(): void
    {
        Partner::factory()->count(100)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $startTime = microtime(true);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/partners?per_page=25');

        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        $response->assertOk();

        // Should complete in less than 500ms (generous threshold for CI environments)
        $this->assertLessThan(500, $executionTime, 'Pagination should complete in less than 500ms');
    }
}
