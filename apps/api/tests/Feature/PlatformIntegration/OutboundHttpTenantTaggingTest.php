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
use App\Modules\PlatformIntegration\Application\Services\CatalogBrowseService;
use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Concern 1 — Outbound HTTP from PlatformHttpClient must carry
 * X-Tenant-Id + X-Company-Id headers reflecting CompanyContext at
 * request time. The headers are MANDATORY; an empty CompanyContext
 * must throw rather than silently send a headerless request (a
 * silent omission would hide upstream tenant-binding bugs and
 * defeat the platform-side enforcement that Finding J tracks).
 *
 * The test surface covers the load-bearing point
 * (PlatformHttpClient::buildRequest) plus the four service-layer
 * callsites that exercise it, plus a cross-tenant control verifying
 * per-call rebinding (no leakage of prior tenant context).
 */
final class OutboundHttpTenantTaggingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Company $companyA;

    private Tenant $tenantB;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'platform-outbound-tenant-a',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A SARL',
            'tax_id' => 'TAX-A-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'platform-outbound-tenant-b',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B SARL',
            'tax_id' => 'TAX-B-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-api-key']);

        Cache::flush();
        app(CompanyContext::class)->clear();
    }

    public function test_platform_http_client_get_attaches_tenant_and_company_headers(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);

        Http::fake([
            'platform.test/api/v1/automotive/manufacturers*' => Http::response(['data' => []], 200),
        ]);

        /** @var PlatformHttpClient $client */
        $client = app(PlatformHttpClient::class);
        $client->get('/api/v1/automotive/manufacturers');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Tenant-Id', $this->tenantA->id)
                && $request->hasHeader('X-Company-Id', $this->companyA->id);
        });
    }

    public function test_platform_http_client_post_attaches_tenant_and_company_headers(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);

        Http::fake([
            'platform.test/api/v1/automotive/articles/search-by-criteria' => Http::response(['data' => []], 200),
        ]);

        /** @var PlatformHttpClient $client */
        $client = app(PlatformHttpClient::class);
        $client->post('/api/v1/automotive/articles/search-by-criteria', ['q' => 'test']);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Tenant-Id', $this->tenantA->id)
                && $request->hasHeader('X-Company-Id', $this->companyA->id);
        });
    }

    public function test_platform_http_client_post_raw_attaches_tenant_and_company_headers(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);

        Http::fake([
            'platform.test/api/v1/products/lookup' => Http::response(['status' => 'not_found'], 200),
        ]);

        /** @var PlatformHttpClient $client */
        $client = app(PlatformHttpClient::class);
        $client->postRaw('/api/v1/products/lookup', ['barcode' => '4006381333931']);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Tenant-Id', $this->tenantA->id)
                && $request->hasHeader('X-Company-Id', $this->companyA->id);
        });
    }

    public function test_platform_http_client_get_raw_attaches_tenant_and_company_headers(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);

        Http::fake([
            'platform.test/api/v1/products/lookup-status/*' => Http::response(['status' => 'pending'], 200),
        ]);

        /** @var PlatformHttpClient $client */
        $client = app(PlatformHttpClient::class);
        $client->getRaw('/api/v1/products/lookup-status/abc-123');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Tenant-Id', $this->tenantA->id)
                && $request->hasHeader('X-Company-Id', $this->companyA->id);
        });
    }

    public function test_barcode_lookup_service_propagates_tenant_headers(): void
    {
        $this->seedUserAndBindCompany($this->tenantA, $this->companyA, 'a');

        Http::fake([
            'platform.test/api/v1/products/lookup' => Http::response(['status' => 'not_found'], 200),
        ]);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);
        $service->lookup('4006381333931');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Tenant-Id', $this->tenantA->id)
                && $request->hasHeader('X-Company-Id', $this->companyA->id);
        });
    }

    public function test_catalog_browse_service_propagates_tenant_headers(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);

        Http::fake([
            'platform.test/api/v1/automotive/manufacturers*' => Http::response(['data' => []], 200),
        ]);

        /** @var CatalogBrowseService $service */
        $service = app(CatalogBrowseService::class);
        $service->getManufacturers();

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Tenant-Id', $this->tenantA->id)
                && $request->hasHeader('X-Company-Id', $this->companyA->id);
        });
    }

    public function test_product_submission_service_propagates_tenant_headers(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);

        Http::fake([
            'platform.test/api/v1/products/upload-url' => Http::response(['url' => 'https://upload.example.test/sig'], 200),
        ]);

        /** @var ProductSubmissionService $service */
        $service = app(ProductSubmissionService::class);
        $service->requestUploadUrl('photo.jpg', 'image/jpeg', 1024);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Tenant-Id', $this->tenantA->id)
                && $request->hasHeader('X-Company-Id', $this->companyA->id);
        });
    }

    public function test_cross_tenant_rebind_does_not_leak_prior_tenant_headers(): void
    {
        $context = app(CompanyContext::class);

        // First call: bound to tenant A
        $context->setCompanyId($this->companyA->id);

        Http::fake([
            'platform.test/api/v1/automotive/manufacturers*' => Http::response(['data' => []], 200),
        ]);

        /** @var PlatformHttpClient $client */
        $client = app(PlatformHttpClient::class);
        $client->get('/api/v1/automotive/manufacturers');

        // Second call: rebind to tenant B
        $context->clear();
        $context->setCompanyId($this->companyB->id);

        $client->get('/api/v1/automotive/manufacturers');

        // Capture both requests, assert each carries its own tenant
        $sent = Http::recorded();
        $this->assertCount(2, $sent);

        /** @var Request $firstRequest */
        $firstRequest = $sent[0][0];
        $this->assertTrue(
            $firstRequest->hasHeader('X-Tenant-Id', $this->tenantA->id),
            'First request should carry tenant A headers'
        );
        $this->assertTrue(
            $firstRequest->hasHeader('X-Company-Id', $this->companyA->id),
            'First request should carry company A headers'
        );

        /** @var Request $secondRequest */
        $secondRequest = $sent[1][0];
        $this->assertTrue(
            $secondRequest->hasHeader('X-Tenant-Id', $this->tenantB->id),
            'Second request should carry tenant B headers (no leak from prior context)'
        );
        $this->assertTrue(
            $secondRequest->hasHeader('X-Company-Id', $this->companyB->id),
            'Second request should carry company B headers (no leak from prior context)'
        );
    }

    public function test_outbound_with_empty_company_context_throws_loud(): void
    {
        // Explicit clear: no CompanyContext bound.
        app(CompanyContext::class)->clear();

        Http::fake([
            'platform.test/*' => Http::response(['data' => []], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/company context|tenant context|CompanyContext/i');

        /** @var PlatformHttpClient $client */
        $client = app(PlatformHttpClient::class);
        $client->get('/api/v1/automotive/manufacturers');
    }

    public function test_outbound_with_empty_company_context_does_not_silently_send(): void
    {
        app(CompanyContext::class)->clear();

        Http::fake([
            'platform.test/*' => Http::response(['data' => []], 200),
        ]);

        try {
            /** @var PlatformHttpClient $client */
            $client = app(PlatformHttpClient::class);
            $client->get('/api/v1/automotive/manufacturers');
            $this->fail('Expected RuntimeException for empty CompanyContext');
        } catch (\RuntimeException $e) {
            // Expected — confirm no request was sent (fail-loud, not fail-then-send).
            Http::assertNothingSent();
        }
    }

    /**
     * Helper: seed a User + UserCompanyMembership and bind CompanyContext.
     * Used by callers that exercise the BarcodeLookupService vertical
     * resolution path (which calls $context->requireCompany()->tenant->vertical).
     */
    private function seedUserAndBindCompany(Tenant $tenant, Company $company, string $emailSuffix): void
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User '.$emailSuffix,
            'email' => 'platform-outbound-'.$emailSuffix.'@test.local',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);
    }
}
