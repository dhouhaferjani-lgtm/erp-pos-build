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
use App\Modules\Product\Application\Jobs\SendBrandMappingJob;
use App\Modules\Product\Domain\Brand;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class BarcodeLookupBrandResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_EAN_13 = '4006381333931';

    private Tenant $tenant;

    private Company $company;

    private BarcodeLookupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Brand Lookup Tenant',
            'slug' => 'brand-lookup-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand Lookup Company',
            'legal_name' => 'Brand Lookup Company SARL',
            'tax_id' => 'TAX-TN-BRAND',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand Lookup User',
            'email' => 'brand-lookup@test.tn',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-api-key']);
        Cache::flush();

        $this->service = app(BarcodeLookupService::class);
    }

    public function test_found_lookup_with_external_mapping_suggests_local_brand_and_skips_push(): void
    {
        Queue::fake();
        $canonical = (string) Str::uuid();
        $brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Avène',
            'slug' => 'avene',
            'canonical_brand_id' => $canonical,
            'is_active' => true,
        ]);
        $this->fakeFoundLookup([
            'canonical_brand_id' => $canonical,
            'external_brand_id' => $brand->id,
        ]);

        $result = $this->service->lookup(self::VALID_EAN_13);

        $this->assertSame($brand->id, $result->suggestedProduct['brand_id'] ?? null);
        Queue::assertNotPushed(SendBrandMappingJob::class);
    }

    public function test_found_lookup_without_mapping_creates_brand_and_pushes(): void
    {
        Queue::fake();
        $canonical = (string) Str::uuid();
        $this->fakeFoundLookup([
            'brand' => 'Avène',
            'canonical_brand_id' => $canonical,
            'external_brand_id' => null,
        ]);

        $result = $this->service->lookup(self::VALID_EAN_13);

        $brand = Brand::where('tenant_id', $this->tenant->id)->where('slug', 'avene')->sole();
        $this->assertSame($canonical, $brand->canonical_brand_id);
        $this->assertSame($brand->id, $result->suggestedProduct['brand_id'] ?? null);
        Queue::assertPushed(SendBrandMappingJob::class);
    }

    public function test_cached_found_result_resolves_brand_per_request(): void
    {
        Queue::fake();
        $canonical = (string) Str::uuid();
        $this->fakeFoundLookup([
            'brand' => 'Avène',
            'canonical_brand_id' => $canonical,
            'external_brand_id' => null,
        ]);

        $first = $this->service->lookup(self::VALID_EAN_13);
        $brandId = $first->suggestedProduct['brand_id'];
        Brand::query()->whereKey($brandId)->delete();

        $second = $this->service->lookup(self::VALID_EAN_13);

        $this->assertNotNull($second->suggestedProduct['brand_id'] ?? null);
        $this->assertNotSame($brandId, $second->suggestedProduct['brand_id']);
        Http::assertSentCount(1);
    }

    public function test_found_lookup_with_legacy_payload_has_null_brand_id(): void
    {
        Queue::fake();
        $this->fakeFoundLookup([]);

        $result = $this->service->lookup(self::VALID_EAN_13);

        $this->assertNull($result->suggestedProduct['brand_id'] ?? null);
        Queue::assertNotPushed(SendBrandMappingJob::class);
    }

    /**
     * @param  array<string, mixed>  $productOverrides
     */
    private function fakeFoundLookup(array $productOverrides): void
    {
        Http::fake([
            'platform.test/api/v1/products/lookup' => Http::response([
                'status' => 'found',
                'product' => array_merge([
                    'id' => (string) Str::uuid(),
                    'barcode' => self::VALID_EAN_13,
                    'name' => 'Avène Cleanance Gel',
                    'brand' => 'Avène',
                    'description' => 'Purifying cleansing gel',
                    'classification' => ['category' => 'facial_cleanser'],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 95,
                    'enrichment_tier' => 'gold',
                ], $productOverrides),
            ], 200),
        ]);
    }
}
