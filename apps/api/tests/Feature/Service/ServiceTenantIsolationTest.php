<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.service cluster) — tenant-isolation regression coverage.
 *
 * Inventory: 3 callsites in ServiceCatalogService:
 *   - api.service.001: updateService line 65 — `Service::findOrFail`
 *   - api.service.002: deleteService line 90 — `Service::findOrFail`
 *   - api.service.003: getService  line 101 — `Service::with('category')->findOrFail`
 *
 * Plus scanner-blind sibling reads in ServiceController + ServiceCategoryController
 * (route-anchored `where('company_id')->where('id')->first()` chains missing
 * `tenant_id`) and ServiceCategory CRUD findOrFails inside ServiceCatalogService
 * (updateCategory/deleteCategory/getCategory). All folded into the cluster fix
 * per the api.cart precedent — every read on a route-param anchor MUST lead
 * with `where('tenant_id')->where('company_id')` (Treasury Finding 14 invariant).
 *
 * Defense-in-depth: ServiceCatalogService methods that take a $companyId
 * parameter assert it matches the active CompanyContext.
 */
final class ServiceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private Service $serviceA;

    private Service $serviceB;

    private ServiceCategory $categoryA;

    private ServiceCategory $categoryB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-service-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-service-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-SVC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-SVC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-svc-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bob',
            'email' => 'bob-svc-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->userB->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        $this->categoryA = ServiceCategory::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Maintenance A',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $this->categoryB = ServiceCategory::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Maintenance B',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->serviceA = Service::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'code' => 'SRV-A-001',
            'name' => 'Service A',
            'pricing_type' => 'flat_rate',
            'base_price' => '50.00',
            'currency' => 'EUR',
            'is_active' => true,
        ]);
        $this->serviceB = Service::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'code' => 'SRV-B-001',
            'name' => 'Service B',
            'pricing_type' => 'flat_rate',
            'base_price' => '60.00',
            'currency' => 'EUR',
            'is_active' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Service controller route-anchored lookups (scanner-blind)
    // ──────────────────────────────────────────────────────────────────

    public function test_service_show_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/services/{$this->serviceB->id}");
        $cross->assertStatus(404);

        $same = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/services/{$this->serviceA->id}");
        $same->assertStatus(200);
    }

    public function test_service_update_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->patchJson("/api/v1/services/{$this->serviceB->id}", [
                'name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);

        $this->assertSame(
            'Service B',
            $this->serviceB->fresh()?->name,
            'Cross-tenant service must NOT have been updated.',
        );
    }

    public function test_service_destroy_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->deleteJson("/api/v1/services/{$this->serviceB->id}");
        $cross->assertStatus(404);

        $this->assertNotNull(
            $this->serviceB->fresh(),
            'Cross-tenant service must NOT have been deleted.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // ServiceCategory controller route-anchored lookups (scanner-blind)
    // ──────────────────────────────────────────────────────────────────

    public function test_category_show_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/service-categories/{$this->categoryB->id}");
        $cross->assertStatus(404);

        $same = $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/service-categories/{$this->categoryA->id}");
        $same->assertStatus(200);
    }

    public function test_category_update_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->patchJson("/api/v1/service-categories/{$this->categoryB->id}", [
                'name' => 'Hijacked Category',
            ]);
        $cross->assertStatus(404);

        $this->assertSame(
            'Maintenance B',
            $this->categoryB->fresh()?->name,
            'Cross-tenant category must NOT have been updated.',
        );
    }

    public function test_category_destroy_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->deleteJson("/api/v1/service-categories/{$this->categoryB->id}");
        $cross->assertStatus(404);

        $this->assertNotNull(
            $this->categoryB->fresh(),
            'Cross-tenant category must NOT have been deleted.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Service-tier findOrFail defense-in-depth (api.service.001/002/003)
    // ──────────────────────────────────────────────────────────────────

    public function test_service_catalog_update_service_refuses_cross_tenant_id(): void
    {
        // Pin context to companyA, then call updateService with serviceB's id.
        // The scoped findOrFail at line 65 must throw ModelNotFoundException.
        app(CompanyContext::class)->setCompanyId($this->companyA->id);
        $service = app(ServiceCatalogService::class);

        $this->expectException(ModelNotFoundException::class);
        $service->updateService($this->serviceB->id, ['name' => 'Hijacked']);
    }

    public function test_service_catalog_delete_service_refuses_cross_tenant_id(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);
        $service = app(ServiceCatalogService::class);

        $this->expectException(ModelNotFoundException::class);
        $service->deleteService($this->serviceB->id);
    }

    public function test_service_catalog_get_service_refuses_cross_tenant_id(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);
        $service = app(ServiceCatalogService::class);

        $this->expectException(ModelNotFoundException::class);
        $service->getService($this->serviceB->id);
    }

    public function test_service_catalog_assert_company_matches_context_rejects_drift(): void
    {
        app(CompanyContext::class)->setCompanyId($this->companyA->id);
        $service = app(ServiceCatalogService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match active CompanyContext');

        $service->createService($this->companyB->id, [
            'code' => 'SRV-DRIFT',
            'name' => 'Drift Test',
            'pricing_type' => 'flat_rate',
            'base_price' => '10.00',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants (bar-raising, per Codex Treasury R3-F14)
    // ──────────────────────────────────────────────────────────────────

    public function test_service_show_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/services/{$this->serviceA->id}")
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $serviceQuery = $this->findFirstQueryFor($log, 'services', '"id" =');

        $this->assertNotNull(
            $serviceQuery,
            'Service route-anchored lookup query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $serviceQuery,
            'Service route-anchored lookup must filter by tenant_id. Got SQL: '.$serviceQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $serviceQuery,
            'Service route-anchored lookup must also filter by company_id. Got SQL: '.$serviceQuery,
        );
    }

    public function test_category_show_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForCompany($this->userA, $this->companyA)
            ->getJson("/api/v1/service-categories/{$this->categoryA->id}")
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $categoryQuery = $this->findFirstQueryFor($log, 'service_categories', '"id" =');

        $this->assertNotNull(
            $categoryQuery,
            'ServiceCategory route-anchored lookup query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $categoryQuery,
            'ServiceCategory route-anchored lookup must filter by tenant_id. Got SQL: '.$categoryQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $categoryQuery,
            'ServiceCategory route-anchored lookup must also filter by company_id. Got SQL: '.$categoryQuery,
        );
    }

    public function test_service_catalog_update_service_query_includes_tenant_and_company_predicates(): void
    {
        // Drive the api.service.001 callsite through the controller so its
        // SQL is captured. PATCH /api/v1/services/{id} → ServiceController::update
        // → ServiceCatalogService::updateService (the inventoried call).
        DB::enableQueryLog();

        $this->actingAsForCompany($this->userA, $this->companyA)
            ->patchJson("/api/v1/services/{$this->serviceA->id}", [
                'name' => 'Renamed',
            ])
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Locate the lookup queries (excluding Eloquent's post-update
        // by-id refresh, which is structurally protected by the just-verified
        // model identity). We expect AT LEAST TWO scoped reads:
        //   - controller-tier read in ServiceController::update
        //   - service-tier findOrFail at api.service.001 (line 65)
        // Both must carry the Treasury R3-F14 invariant: tenant_id + company_id.
        $scopedServiceLookups = array_values(array_filter(
            $log,
            static fn (array $e): bool => str_contains((string) $e['query'], 'from "services"')
                && str_contains((string) $e['query'], '"id" =')
                && str_contains((string) $e['query'], '"tenant_id"')
                && str_contains((string) $e['query'], '"company_id"'),
        ));

        $this->assertGreaterThanOrEqual(
            2,
            count($scopedServiceLookups),
            'Expected ≥2 services lookups carrying both tenant_id and company_id '
                .'(controller-tier read + service-tier api.service.001 findOrFail). '
                .'Got '.count($scopedServiceLookups).'. Full log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
    }

    /**
     * @param  array<int, array{query: string, bindings: array<int, mixed>, time: float|null}>  $log
     */
    private function findFirstQueryFor(array $log, string $table, string $marker): ?string
    {
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (str_contains($sql, "from \"{$table}\"") && str_contains($sql, $marker)) {
                return $sql;
            }
        }

        return null;
    }

    /**
     * Authenticate $user and pin the company context header to $company.
     */
    private function actingAsForCompany(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
