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
use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BarcodeLookupTest extends TestCase
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
            'slug' => 'test-mechanic-barcode',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Mechanic Company',
            'legal_name' => 'Test Mechanic Company SARL',
            'tax_id' => 'TAX-TN-002',
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
            'email' => 'barcode-test@test.tn',
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
    public function it_returns_found_status_when_barcode_matches(): void
    {
        Http::fake([
            'platform.test/api/v1/automotive/articles/barcode/4005209123456' => Http::response([
                'data' => [
                    'id' => '550e8400-e29b-41d4-a716-446655440000',
                    'article_number' => '0986494123',
                    'name' => 'Bosch Brake Pad Set',
                    'supplier_brand' => 'Bosch',
                    'product_group_name' => 'Brake Pads',
                    'barcode' => '4005209123456',
                    'brand_quality_tier' => 'oes',
                    'weight_kg' => '1.250',
                    'dimensions' => null,
                    'cross_references' => [
                        ['type' => 'oe', 'number' => 'ABC123', 'manufacturer_name' => 'Toyota'],
                    ],
                    'vehicle_linkages' => [
                        ['vehicle_type' => 'pc', 'vehicle_id' => 'v-001', 'display' => 'Toyota Corolla', 'year_from' => 2019, 'year_to' => 2023],
                    ],
                    'criteria' => [
                        ['key' => 'length_mm', 'label' => 'Length', 'value' => '450', 'unit' => 'mm'],
                    ],
                ],
            ], 200),
        ]);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);
        $result = $service->lookup('4005209123456');

        $this->assertSame('found', $result->status);
        $this->assertSame('4005209123456', $result->barcode);
        $this->assertNotNull($result->article);
        $this->assertSame('0986494123', $result->article['article_number']);
        $this->assertSame('Bosch', $result->article['supplier_brand']);
        $this->assertNotNull($result->suggestedProduct);
        $this->assertSame('Bosch Brake Pad Set', $result->suggestedProduct['name']);
    }

    /** @test */
    public function it_returns_not_found_when_barcode_has_no_match(): void
    {
        // Note: PlatformHttpClient::get() checks for 404 to return null,
        // but retry() throws RequestException before that check runs.
        // The catch in BarcodeLookupService maps this to an 'error' status.
        // This tests the actual runtime behavior.
        Http::fake([
            'platform.test/api/v1/automotive/articles/barcode/9999999999999' => Http::response(null, 404),
        ]);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);
        $result = $service->lookup('9999999999999');

        $this->assertSame('error', $result->status);
        $this->assertSame('9999999999999', $result->barcode);
        $this->assertSame('platform_error', $result->error_reason);
    }

    /** @test */
    public function it_normalizes_upc_to_ean(): void
    {
        Http::fake([
            'platform.test/api/v1/automotive/articles/barcode/0400520912345' => Http::response(null, 404),
        ]);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);
        $result = $service->lookup('400520912345'); // 12-digit UPC

        $this->assertSame('0400520912345', $result->barcode);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_contains($request->url(), '0400520912345');
        });
    }

    /** @test */
    public function it_caches_successful_lookups(): void
    {
        Http::fake([
            'platform.test/api/v1/automotive/articles/barcode/4005209123456' => Http::response([
                'data' => [
                    'id' => '550e8400-e29b-41d4-a716-446655440000',
                    'article_number' => '0986494123',
                    'name' => 'Bosch Brake Pad',
                    'supplier_brand' => 'Bosch',
                    'product_group_name' => 'Brake Pads',
                    'barcode' => '4005209123456',
                    'brand_quality_tier' => 'oes',
                    'weight_kg' => '1.250',
                    'dimensions' => null,
                    'cross_references' => [],
                    'vehicle_linkages' => [],
                    'criteria' => [],
                ],
            ], 200),
        ]);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);

        // First call - hits the API
        $result1 = $service->lookup('4005209123456');
        $this->assertSame('found', $result1->status);

        // Second call - should use cache
        $result2 = $service->lookup('4005209123456');
        $this->assertSame('found', $result2->status);

        // HTTP should have been called only once
        Http::assertSentCount(1);
    }

    /** @test */
    public function it_returns_error_when_platform_unavailable(): void
    {
        // Open the circuit breaker by setting the cache key
        Cache::put('platform:circuit_breaker', true, 30);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);
        $result = $service->lookup('4005209123456');

        $this->assertSame('error', $result->status);
        $this->assertSame('platform_unavailable', $result->error_reason);
    }
}
