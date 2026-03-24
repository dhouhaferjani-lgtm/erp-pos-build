<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductPaginationTest extends TestCase
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
        $this->user->givePermissionTo(['products.view', 'products.create', 'products.update', 'products.delete']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_can_list_products_with_offset_pagination(): void
    {
        // Create 30 products
        Product::factory()->count(30)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'sku', 'type'],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                ],
                'aggregates' => [
                    'total_products',
                    'total_active',
                    'average_price',
                ],
            ]);

        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(10, $response->json('meta.per_page'));
        $this->assertEquals(30, $response->json('meta.total'));
        $this->assertEquals(3, $response->json('meta.last_page'));
    }

    public function test_can_navigate_to_next_page(): void
    {
        // Create 30 products with specific names
        for ($i = 1; $i <= 30; $i++) {
            Product::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'name' => sprintf('Product %02d', $i),
            ]);
        }

        // Get first page
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?per_page=10&page=1');

        $response->assertOk();
        $this->assertEquals(1, $response->json('meta.current_page'));

        // Get second page
        $response2 = $this->actingAs($this->user)
            ->getJson('/api/v1/products?per_page=10&page=2');

        $response2->assertOk()
            ->assertJsonCount(10, 'data');

        $this->assertEquals(2, $response2->json('meta.current_page'));

        // Should have different products
        $firstPageIds = collect($response->json('data'))->pluck('id')->toArray();
        $secondPageIds = collect($response2->json('data'))->pluck('id')->toArray();

        $this->assertEmpty(array_intersect($firstPageIds, $secondPageIds));
    }

    public function test_last_page_calculation(): void
    {
        // Create exactly 25 products
        Product::factory()->count(25)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?per_page=25');

        $response->assertOk()
            ->assertJsonCount(25, 'data');

        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(1, $response->json('meta.last_page'));
        $this->assertEquals(25, $response->json('meta.total'));
    }

    public function test_respects_per_page_parameter(): void
    {
        Product::factory()->count(50)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?per_page=15');

        $response->assertOk()
            ->assertJsonCount(15, 'data');

        $this->assertEquals(15, $response->json('meta.per_page'));
    }

    public function test_limits_per_page_to_maximum_2000(): void
    {
        Product::factory()->count(150)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?per_page=2500');

        $response->assertOk()
            ->assertJsonCount(150, 'data');

        $this->assertEquals(2000, $response->json('meta.per_page'));
    }

    public function test_default_per_page_is_25(): void
    {
        Product::factory()->count(30)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(25, 'data');

        $this->assertEquals(25, $response->json('meta.per_page'));
    }

    public function test_pagination_works_with_search_filter(): void
    {
        // Create products with "widget" in the name
        Product::factory()->count(20)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Super Widget',
        ]);

        // Create products without "widget"
        Product::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Other Product',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?search=widget&per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data');

        $this->assertEquals(20, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.last_page'));
    }

    public function test_pagination_works_with_type_filter(): void
    {
        Product::factory()->count(15)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'part',
        ]);

        Product::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'service',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?type=part&per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data');

        $this->assertEquals(15, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.last_page'));
    }

    public function test_pagination_works_with_active_filter(): void
    {
        Product::factory()->count(20)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        Product::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?is_active=1&per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data');

        $this->assertEquals(20, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.last_page'));
    }

    public function test_empty_results_return_empty_array(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertEquals(0, $response->json('meta.total'));
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(1, $response->json('meta.last_page'));
    }

    public function test_products_are_isolated_by_company(): void
    {
        // Create products for our company
        Product::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Create products for another company
        $otherCompany = Company::factory()->for($this->tenant)->create();
        Product::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_performance_with_large_dataset(): void
    {
        // Create 100 products to test performance
        Product::factory()->count(100)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $startTime = microtime(true);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?per_page=25');

        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $response->assertOk();

        // Should complete in less than 500ms (increased for aggregate calculations)
        $this->assertLessThan(500, $executionTime, 'Pagination should complete in less than 500ms');
    }

    public function test_calculates_aggregates_correctly(): void
    {
        // Create 15 active products
        Product::factory()->count(15)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
            'sale_price' => '100.00',
        ]);

        // Create 5 inactive products
        Product::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => false,
            'sale_price' => '50.00',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products');

        $response->assertOk();

        $this->assertEquals(20, $response->json('aggregates.total_products'));
        $this->assertEquals(15, $response->json('aggregates.total_active'));

        // Average price should be 87.50 ((15 * 100 + 5 * 50) / 20)
        $avgPrice = (float) $response->json('aggregates.average_price');
        $this->assertEqualsWithDelta(87.50, $avgPrice, 0.01);
    }

    public function test_aggregates_respect_filters(): void
    {
        // Create 10 active parts
        Product::factory()->count(10)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'part',
            'is_active' => true,
        ]);

        // Create 5 active services
        Product::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'service',
            'is_active' => true,
        ]);

        // Create 3 inactive parts
        Product::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'part',
            'is_active' => false,
        ]);

        // Filter by type=part
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products?type=part');

        $response->assertOk();

        // Should only count the filtered products
        $this->assertEquals(13, $response->json('aggregates.total_products')); // 10 active + 3 inactive parts
        $this->assertEquals(10, $response->json('aggregates.total_active')); // Only active parts
    }
}
