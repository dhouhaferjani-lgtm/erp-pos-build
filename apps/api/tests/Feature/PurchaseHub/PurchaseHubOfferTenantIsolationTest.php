<?php

declare(strict_types=1);

namespace Tests\Feature\PurchaseHub;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\PurchaseHub\Application\Services\PurchaseHubService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tenant-isolation regression coverage for PurchaseHubService offer
 * cache.
 *
 * Master plan §M2.5 — one CrossTenantRoute annotation on
 * PurchaseHubOfferController::index() pointed at PurchaseHubService::
 * getOffers(), which cached under the GLOBAL key
 * `purchase_hub:offers` (TTL 300s). The fresh-fetch path tenant-tagged
 * outbound calls via PlatformHttpClient headers, but cache hits
 * returned another tenant's previously-cached payload without
 * re-stamping headers — a cross-tenant cache leak.
 *
 * The fix appends the active CompanyContext's tenant_id + company_id
 * to every cache key. Cache invalidation in placeOrder uses the same
 * scoped key.
 *
 * Test strategy: we avoid mocking `PlatformHttpClient` (which is
 * `final`) by using `Http::fake()` on the underlying Laravel HTTP
 * client. The fake matches whatever URL the platform client builds.
 * The fixed PLATFORM_API_URL env var is set in TestCase / phpunit.xml,
 * and a missing URL just lets the fake intercept it anyway.
 */
final class PurchaseHubOfferTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private CompanyContext $companyContext;

    private PurchaseHubService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-hub-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-hub-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = $this->makeCompany($this->tenantA->id, 'Company A', 'TAX-HA');
        $this->companyB = $this->makeCompany($this->tenantB->id, 'Company B', 'TAX-HB');

        $this->companyContext = new CompanyContext();
        $this->app->instance(CompanyContext::class, $this->companyContext);

        $this->service = $this->app->make(PurchaseHubService::class);
    }

    public function test_offers_cache_is_keyed_per_tenant_and_company(): void
    {
        // Pre-populate each tenant's cache entry directly with a known
        // payload. The fixed service must read tenant A's value when
        // the context is set to tenant A, and tenant B's when set to B.
        Cache::put(
            $this->expectedCacheKey($this->tenantA->id, $this->companyA->id),
            [['id' => 'offer-A1', 'tenant' => 'A']],
            300,
        );
        Cache::put(
            $this->expectedCacheKey($this->tenantB->id, $this->companyB->id),
            [['id' => 'offer-B1', 'tenant' => 'B']],
            300,
        );

        // Block the platform with a fake — any HTTP call indicates a
        // cache miss that the new key behavior is meant to PREVENT.
        Http::fake(['*' => Http::response([['id' => 'should-not-be-fetched']], 200)]);

        $this->companyContext->setCompanyId($this->companyA->id);
        $a = $this->service->getOffers();
        $this->assertEquals([['id' => 'offer-A1', 'tenant' => 'A']], $a);

        $this->companyContext->setCompanyId($this->companyB->id);
        $b = $this->service->getOffers();
        $this->assertEquals([['id' => 'offer-B1', 'tenant' => 'B']], $b);

        // Assert no HTTP requests were made — both calls were cache HITS
        // under their respective scoped keys, with NO cross-contamination.
        Http::assertNothingSent();
    }

    public function test_tenant_b_cannot_read_tenant_a_cached_value_via_global_key(): void
    {
        // Populate only tenant A's scoped key. The old global key
        // `purchase_hub:offers` (no scope suffix) is NOT set.
        Cache::put(
            $this->expectedCacheKey($this->tenantA->id, $this->companyA->id),
            [['id' => 'offer-A1', 'tenant' => 'A']],
            300,
        );

        // Tenant B's lookup MUST miss the cache and hit the platform.
        // PlatformHttpClient::get() unwraps response['data'], so wrap.
        Http::fake([
            '*' => Http::response(['data' => [['id' => 'offer-B1-fresh']]], 200),
        ]);

        $this->companyContext->setCompanyId($this->companyB->id);
        $b = $this->service->getOffers();

        // Critical: B did NOT receive A's cached payload.
        $this->assertNotEquals([['id' => 'offer-A1', 'tenant' => 'A']], $b);
        // And B's response is the fresh-fetched B payload.
        $this->assertEquals([['id' => 'offer-B1-fresh']], $b);
        Http::assertSentCount(1);
    }

    public function test_place_order_only_invalidates_calling_tenant_cache(): void
    {
        Cache::put(
            $this->expectedCacheKey($this->tenantA->id, $this->companyA->id),
            [['id' => 'offer-A1']],
            300,
        );
        Cache::put(
            $this->expectedCacheKey($this->tenantB->id, $this->companyB->id),
            [['id' => 'offer-B1']],
            300,
        );

        Http::fake([
            '*' => Http::response(['id' => 'order-A1'], 200),
        ]);

        $this->companyContext->setCompanyId($this->companyA->id);
        $this->service->placeOrder(['offer_id' => 'offer-A1']);

        // Tenant A's cache was wiped.
        $this->assertNull(
            Cache::get($this->expectedCacheKey($this->tenantA->id, $this->companyA->id)),
        );
        // Tenant B's cache is intact.
        $this->assertEquals(
            [['id' => 'offer-B1']],
            Cache::get($this->expectedCacheKey($this->tenantB->id, $this->companyB->id)),
        );
    }

    private function expectedCacheKey(string $tenantId, string $companyId): string
    {
        return 'purchase_hub:offers:'.$tenantId.':'.$companyId;
    }

    private function makeCompany(string $tenantId, string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => $taxId,
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
    }
}
