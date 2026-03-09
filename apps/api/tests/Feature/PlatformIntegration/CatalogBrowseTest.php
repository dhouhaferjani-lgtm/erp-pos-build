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
use App\Modules\Product\Domain\AutomotiveProductMetadata;
use App\Modules\Product\Domain\Enums\PlatformLinkStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CatalogBrowseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Mechanic',
            'slug' => 'test-mechanic-catalog',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Mechanic Company',
            'legal_name' => 'Test Mechanic Company SARL',
            'tax_id' => 'TAX-TN-003',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'catalog-test@test.tn',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-api-key']);
    }

    /** @test */
    public function it_returns_manufacturers_from_platform(): void
    {
        Http::fake([
            'platform.test/api/v1/automotive/manufacturers*' => Http::response([
                'data' => [
                    ['id' => 'mfr-001', 'name' => 'Toyota', 'code' => 'TOYOTA'],
                    ['id' => 'mfr-002', 'name' => 'Volkswagen', 'code' => 'VW'],
                    ['id' => 'mfr-003', 'name' => 'BMW', 'code' => 'BMW'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/platform/catalog/manufacturers');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Toyota')
            ->assertJsonPath('data.1.name', 'Volkswagen')
            ->assertJsonPath('data.2.name', 'BMW');
    }

    /** @test */
    public function it_returns_model_series_for_manufacturer(): void
    {
        Http::fake([
            'platform.test/api/v1/automotive/manufacturers/mfr-001/model-series*' => Http::response([
                'data' => [
                    ['id' => 'ms-001', 'name' => 'Corolla', 'year_from' => 1966, 'year_to' => null],
                    ['id' => 'ms-002', 'name' => 'Camry', 'year_from' => 1982, 'year_to' => null],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/platform/catalog/manufacturers/mfr-001/model-series');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Corolla')
            ->assertJsonPath('data.1.name', 'Camry');
    }

    /** @test */
    public function it_returns_vehicle_articles(): void
    {
        Http::fake([
            'platform.test/api/v1/automotive/vehicles/pc/v-001/articles*' => Http::response([
                'data' => [
                    'articles' => [
                        [
                            'id' => 'art-001',
                            'article_number' => '0986494123',
                            'name' => 'Brake Pad Set',
                            'supplier_brand' => 'Bosch',
                        ],
                        [
                            'id' => 'art-002',
                            'article_number' => 'W712/52',
                            'name' => 'Oil Filter',
                            'supplier_brand' => 'Mann-Filter',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/platform/catalog/vehicles/pc/v-001/articles');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.articles')
            ->assertJsonPath('data.articles.0.article_number', '0986494123');
    }

    /** @test */
    public function it_returns_search_tree_roots(): void
    {
        Http::fake([
            'platform.test/api/v1/automotive/search-tree/roots*' => Http::response([
                'data' => [
                    ['id' => 'node-001', 'name' => 'Engine', 'has_children' => true],
                    ['id' => 'node-002', 'name' => 'Brakes', 'has_children' => true],
                    ['id' => 'node-003', 'name' => 'Suspension', 'has_children' => true],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/platform/catalog/search-tree/roots');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Engine')
            ->assertJsonPath('data.1.name', 'Brakes');
    }

    /** @test */
    public function it_enriches_articles_with_local_inventory_status(): void
    {
        $platformArticleId = '550e8400-e29b-41d4-a716-446655440000';

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Local Brake Pad',
            'sku' => 'LOCAL-BP-001',
            'sale_price' => 45.50,
        ]);

        AutomotiveProductMetadata::factory()->create([
            'product_id' => $product->id,
            'platform_article_id' => $platformArticleId,
            'platform_link_status' => PlatformLinkStatus::Linked,
        ]);

        Http::fake([
            'platform.test/api/v1/automotive/vehicles/pc/v-001/articles*' => Http::response([
                'data' => [
                    'articles' => [
                        [
                            'id' => $platformArticleId,
                            'article_number' => '0986494123',
                            'name' => 'Brake Pad Set',
                            'supplier_brand' => 'Bosch',
                        ],
                        [
                            'id' => 'art-unknown-999',
                            'article_number' => 'UNKNOWN-001',
                            'name' => 'Unknown Part',
                            'supplier_brand' => 'Generic',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/platform/catalog/vehicles/pc/v-001/articles');

        $response->assertStatus(200);

        $articles = $response->json('data.articles');
        $this->assertCount(2, $articles);

        // First article should be enriched with local inventory
        $this->assertTrue($articles[0]['local_inventory']['in_stock']);
        $this->assertSame($product->id, $articles[0]['local_inventory']['product_id']);

        // Second article should not be in local stock
        $this->assertFalse($articles[1]['local_inventory']['in_stock']);
    }
}
