<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\FraudAlert;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\FraudTriggeredCountingService;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.inventory cluster) — tenant-isolation regression coverage.
 *
 * Inventory: 28 callsites across the Inventory module.
 *
 *   FormRequest validators (bare exists → ScopedExists):
 *     api.inventory.001  CreateCountingRequest:35           products      (tenant + company)
 *     api.inventory.002  CreateCountingRequest:39           locations     (company-scoped)
 *     api.inventory.003  CreateCountingRequest:40           locations     (company-scoped)
 *     api.inventory.004  CreateDraftCountingRequest:56      products
 *     api.inventory.005  AddProductToCountingRequest:28     products
 *     api.inventory.006  AddProductToCountingRequest:29     locations
 *     api.inventory.020  CreateCountingRequest:48           users         (tenant-only)
 *     api.inventory.021  CreateCountingRequest:49           users
 *     api.inventory.022  CreateCountingRequest:50           users
 *     api.inventory.023  CreateDraftCountingRequest:46      users
 *     api.inventory.024  CreateDraftCountingRequest:47      users
 *     api.inventory.025  CreateDraftCountingRequest:48      users
 *     api.inventory.026  UpdateDraftCountingRequest:37      users
 *     api.inventory.027  UpdateDraftCountingRequest:38      users
 *     api.inventory.028  UpdateDraftCountingRequest:39      users
 *
 *   Controller body validators:
 *     api.inventory.007  StockReservationController:171     products
 *     api.inventory.008  StockReservationController:172     locations
 *     api.inventory.009  StockReservationController:259     products
 *     api.inventory.010  StockReservationController:260     locations
 *
 *   Service-tier (unscoped findOrFail / find):
 *     api.inventory.011  StockReservationService:367              Product
 *     api.inventory.012  WeightedAverageCostService:81             Product (lockForUpdate)
 *     api.inventory.013  WeightedAverageCostService:212            Product
 *     api.inventory.014  WeightedAverageCostService:317            Product
 *     api.inventory.015  GoodsReceiptService:85                    Product
 *     api.inventory.016  StockAdjustmentService:465                Product (private helper)
 *     api.inventory.017  StockAdjustmentService:466                Location (private helper)
 *     api.inventory.018  StockAdjustmentService:521                Location (recordMovement)
 *     api.inventory.019  InventoryCountingController:545           Product
 *
 * locations is company-scoped only (no tenant_id) — uses ScopedExists::company.
 * users is tenant-scoped only (no company_id) — uses ScopedExists::tenant.
 * products carries tenant_id + company_id — uses ScopedExists::tenantAndCompany.
 *
 * Service-tier callsites .011-.018 are tested indirectly via the public
 * endpoints that exercise them (public validators reject cross-tenant ids
 * before reaching the services). Direct service-tier tests would require
 * complex setup; the structural defense in `WeightedAverageCostService` etc.
 * scopes lockForUpdate by the input model's own tenant + company, and
 * `StockAdjustmentService::getOrCreateStockLevel` scopes Product lookup by
 * the Location's company_id (cross-company products throw ModelNotFoundException).
 *
 * Routes are gated behind `module:Inventory`, so both test tenants set
 * `enabled_extras: ['Inventory']`.
 */
final class InventoryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private Product $productA;

    private Product $productB;

    private Location $locationA;

    private Location $locationB;

    private InventoryCounting $draftCountingA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-inv-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-inv-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-INV',
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
            'tax_id' => 'TAX-B-INV',
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
            'email' => 'alice-inv-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bob',
            'email' => 'bob-inv-iso@example.com',
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

        $this->productA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Product A',
            'sku' => 'SKU-A',
            'type' => 'part',
            'is_active' => true,
            // Non-zero cost_price so .014's value pin discriminates between
            // same-tenant (returns '10.00') and cross-tenant (returns '0.00').
            'cost_price' => '10.00',
        ]);
        $this->productB = Product::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Product B',
            'sku' => 'SKU-B',
            'type' => 'part',
            'is_active' => true,
        ]);

        $this->locationA = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyA->id,
            'name' => 'Loc A',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->locationB = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyB->id,
            'name' => 'Loc B',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->draftCountingA = InventoryCounting::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'created_by_user_id' => (string) $this->userA->id,
            'status' => CountingStatus::Draft,
            'scope_type' => 'product',
            'scope_filters' => ['product_ids' => []],
            'execution_mode' => 'sequential',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // CreateCountingRequest validators (.001-.003, .020-.022)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_counting_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/inventory/countings', [
                'scope_type' => 'product',
                'scope_filters' => [
                    'product_ids' => [$this->productB->id],
                ],
                'count_1_user_id' => (string) $this->userA->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('scope_filters.product_ids.0', $cross->json('error.errors') ?? []);
    }

    public function test_create_counting_rejects_cross_tenant_location_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/inventory/countings', [
                'scope_type' => 'location',
                'scope_filters' => [
                    'location_ids' => [$this->locationB->id],
                ],
                'count_1_user_id' => (string) $this->userA->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('scope_filters.location_ids.0', $cross->json('error.errors') ?? []);
    }

    public function test_create_counting_rejects_cross_tenant_count_user_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/inventory/countings', [
                'scope_type' => 'product',
                'scope_filters' => [
                    'product_ids' => [$this->productA->id],
                ],
                'count_1_user_id' => (string) $this->userB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('count_1_user_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // CreateDraftCountingRequest validators (.004, .023-.025)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_draft_counting_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/inventory/countings/drafts', [
                'scope_type' => 'product',
                'scope_filters' => [
                    'product_ids' => [$this->productB->id],
                ],
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('scope_filters.product_ids.0', $cross->json('error.errors') ?? []);
    }

    public function test_create_draft_counting_rejects_cross_tenant_count_user_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/inventory/countings/drafts', [
                'scope_type' => 'product',
                'count_1_user_id' => (string) $this->userB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('count_1_user_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // UpdateDraftCountingRequest validators (.026-.028)
    // ──────────────────────────────────────────────────────────────────

    public function test_update_draft_counting_rejects_cross_tenant_count_user_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/inventory/countings/{$this->draftCountingA->id}/draft", [
                'count_1_user_id' => (string) $this->userB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('count_1_user_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // AddProductToCountingRequest validators (.005, .006) +
    // InventoryCountingController.019 response leak (Product::findOrFail)
    // ──────────────────────────────────────────────────────────────────

    public function test_add_product_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/inventory/countings/{$this->draftCountingA->id}/add-product", [
                'product_id' => $this->productB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('product_id', $cross->json('error.errors') ?? []);
    }

    public function test_add_product_rejects_cross_tenant_location_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/inventory/countings/{$this->draftCountingA->id}/add-product", [
                'product_id' => $this->productA->id,
                'location_id' => $this->locationB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('location_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // StockReservationController body validators (.007-.010)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_reservation_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/stock-reservations', [
                'product_id' => $this->productB->id,
                'location_id' => $this->locationA->id,
                'quantity' => '1.0',
                'source_type' => 'sales_order',
                'source_id' => Str::uuid()->toString(),
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('product_id', $cross->json('error.errors') ?? []);
    }

    public function test_create_reservation_rejects_cross_tenant_location_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/stock-reservations', [
                'product_id' => $this->productA->id,
                'location_id' => $this->locationB->id,
                'quantity' => '1.0',
                'source_type' => 'sales_order',
                'source_id' => Str::uuid()->toString(),
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('location_id', $cross->json('error.errors') ?? []);
    }

    /*
     * api.inventory.009 / .010 (StockReservationController::breakdown body
     * validators) cannot be exercised via HTTP because the GET route
     * `/api/v1/stock-reservations/breakdown` is shadowed by the
     * `/api/v1/stock-reservations/{id}` route registered earlier in
     * routes.php (Laravel resolves `breakdown` as an `{id}` parameter and
     * 404s in the show action). The validator hardening in this commit is
     * defense-in-depth: if a future commit reorders the routes so that
     * breakdown is reachable, the validators will already be tenant-scoped.
     * Annotated in the inventory as
     * structurally_protected_by_unreachable_route. Tracked as a follow-up
     * route-ordering fix outside this cluster's scope.
     */
    public function test_breakdown_route_is_shadowed_by_show_route(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson('/api/v1/stock-reservations/breakdown?product_id='.$this->productA->id.'&location_id='.$this->locationA->id);
        // Route `{id}` matches `breakdown` first → show() runs → not found → 404.
        // This documents the pre-existing route-ordering issue.
        $response->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants — pin SQL shape for the validators.
    // ──────────────────────────────────────────────────────────────────

    public function test_create_reservation_validator_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/stock-reservations', [
                'product_id' => $this->productB->id, // intentionally cross-tenant to trigger validator
                'location_id' => $this->locationA->id,
                'quantity' => '1.0',
                'source_type' => 'sales_order',
                'source_id' => Str::uuid()->toString(),
            ])
            ->assertStatus(422);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $productsValidationQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "products"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'exists') || str_contains($sql, 'count(*)'))
            ) {
                $productsValidationQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $productsValidationQuery,
            'Products exists-validation query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $productsValidationQuery,
            'StockReservation product_id validator must filter by tenant_id. Got SQL: '.$productsValidationQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $productsValidationQuery,
            'StockReservation product_id validator must filter by company_id. Got SQL: '.$productsValidationQuery,
        );
    }

    public function test_create_counting_user_validator_query_includes_tenant_predicate(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/inventory/countings', [
                'scope_type' => 'product',
                'scope_filters' => [
                    'product_ids' => [$this->productA->id],
                ],
                'count_1_user_id' => (string) $this->userB->id, // cross-tenant — triggers validator
            ])
            ->assertStatus(422);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $usersValidationQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "users"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'exists') || str_contains($sql, 'count(*)'))
            ) {
                $usersValidationQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $usersValidationQuery,
            'Users exists-validation query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $usersValidationQuery,
            'CreateCounting count_user_id validator must filter by tenant_id (users has no company_id). Got SQL: '.$usersValidationQuery,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Same-tenant cross-company read leak (Codex round-1 Finding 1)
    // StockLevelController + StockMovementController scoped only by
    // tenant_id, leaking same-tenant cross-company stock data when a
    // user with multi-company membership selects company A.
    // ──────────────────────────────────────────────────────────────────

    public function test_stock_levels_index_excludes_same_tenant_cross_company_rows(): void
    {
        // Two companies under the SAME tenant.
        $companyA2 = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A2',
            'legal_name' => 'Company A2 LLC',
            'tax_id' => 'TAX-A2-INV',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $companyA2->id,
            'role' => 'admin',
        ]);
        $locationA2 = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $companyA2->id,
            'name' => 'Loc A2',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->locationA->id,
            'quantity' => '5.0',
            'reserved' => '0.0',
        ]);
        $foreignLevel = StockLevel::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $companyA2->id,
            'product_id' => $this->productA->id,
            'location_id' => $locationA2->id,
            'quantity' => '99.0',
            'reserved' => '0.0',
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson('/api/v1/stock-levels');
        $response->assertStatus(200);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $response->json('data') ?? [];
        $ids = array_column($rows, 'id');
        $this->assertNotContains(
            $foreignLevel->id,
            $ids,
            'Same-tenant cross-company stock level must NOT leak into Company A response.',
        );
    }

    public function test_stock_movements_index_excludes_same_tenant_cross_company_rows(): void
    {
        $companyA2 = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A2 Mvt',
            'legal_name' => 'Company A2 Mvt LLC',
            'tax_id' => 'TAX-A2-MVT',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $companyA2->id,
            'role' => 'admin',
        ]);

        StockMovement::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->locationA->id,
            'movement_type' => 'receipt',
            'quantity' => '1.0',
            'quantity_before' => '0.0',
            'quantity_after' => '1.0',
            'reference' => 'ref-A',
            'user_id' => (string) $this->userA->id,
        ]);
        $foreignMovement = StockMovement::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $companyA2->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->locationA->id,
            'movement_type' => 'receipt',
            'quantity' => '99.0',
            'quantity_before' => '0.0',
            'quantity_after' => '99.0',
            'reference' => 'ref-A2',
            'user_id' => (string) $this->userA->id,
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson('/api/v1/stock-movements');
        $response->assertStatus(200);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $response->json('data') ?? [];
        $ids = array_column($rows, 'id');
        $this->assertNotContains(
            $foreignMovement->id,
            $ids,
            'Same-tenant cross-company stock movement must NOT leak into Company A response.',
        );
    }

    /**
     * Codex round-2 Finding 1 — StockReservationService::reserveForWorkOrder
     * derived Company from an unscoped StockLevel lookup-by-product_id,
     * letting a Workshop approval cross-company-reserve foreign stock.
     * Now the method requires explicit tenantId + companyId and scopes
     * the StockLevel lookup. A forged cross-company productId yields
     * RuntimeException → no reservation is created.
     */
    public function test_reserve_for_work_order_rejects_cross_company_product(): void
    {
        // Foreign-tenant stock level seeded for productB, locationB.
        StockLevel::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'product_id' => $this->productB->id,
            'location_id' => $this->locationB->id,
            'quantity' => '100.0',
            'reserved' => '0.0',
        ]);

        /** @var StockReservationService $service */
        $service = app(StockReservationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no StockLevel row found/');

        // Caller scope is tenantA + companyA, but the productId belongs to
        // tenantB / companyB. Pre-fix this would have happily reserved
        // 1 unit against tenantB's stock; post-fix it must throw.
        $service->reserveForWorkOrder(
            tenantId: $this->tenantA->id,
            companyId: $this->companyA->id,
            productId: $this->productB->id,
            quantity: '1.0',
            workOrderLineId: Str::uuid()->toString(),
            workOrderId: Str::uuid()->toString(),
            expiresAt: null,
        );
    }

    /**
     * Codex round-1 Finding 2 — WAC service-tier scope is not pinned by
     * regression tests. Pin the SQL shape of recordPurchase's product
     * lockForUpdate so reverting api.inventory.012 (line 83-87) breaks
     * this test.
     */
    public function test_wac_record_purchase_locks_product_with_tenant_and_company_predicates(): void
    {
        /** @var WeightedAverageCostService $wac */
        $wac = app(WeightedAverageCostService::class);

        DB::enableQueryLog();

        $wac->recordPurchase(
            product: $this->productA,
            location: $this->locationA,
            quantity: 1.0,
            landedUnitCost: 10.0,
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Locate the products lockForUpdate query — the scoped product
        // re-read added at WeightedAverageCostService.php:83-87. SQLite
        // (default test DB) elides `for update`, so we match on the
        // tenant_id + company_id + id predicates instead.
        $productLockQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "products"')
                && str_contains($sql, 'tenant_id')
                && str_contains($sql, 'company_id')
                && str_contains($sql, '"id" =')
            ) {
                $productLockQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $productLockQuery,
            'WAC product lockForUpdate query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $productLockQuery,
            'WAC recordPurchase product lock must filter by tenant_id. Got SQL: '.$productLockQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $productLockQuery,
            'WAC recordPurchase product lock must filter by company_id. Got SQL: '.$productLockQuery,
        );
    }

    public function test_stock_levels_index_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson('/api/v1/stock-levels')
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $stockLevelQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (str_contains($sql, 'from "stock_levels"')) {
                $stockLevelQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $stockLevelQuery,
            'StockLevel index query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $stockLevelQuery,
            'StockLevel index must filter by tenant_id. Got SQL: '.$stockLevelQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $stockLevelQuery,
            'StockLevel index must filter by company_id. Got SQL: '.$stockLevelQuery,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // BatchExpiry FormRequest validators + service-tier finds
    // (api.unmapped.006-010, api.unmapped.014 — reassigned to api.inventory).
    //
    // BatchExpiry routes are gated by `module:Inventory`. Both test tenants
    // already enable Inventory above. The schema:
    //   - products: tenant_id + company_id (ScopedExists::tenantAndCompany)
    //   - locations: company_id only (no tenant_id; ScopedExists::company)
    //
    // Pre-fix: bare `exists:products,id` / `exists:locations,id` rules
    // allowed any UUID with the correct shape to satisfy the FK validator
    // — including foreign-tenant ids that pointed at someone else's data.
    // ──────────────────────────────────────────────────────────────────

    public function test_create_batch_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/batches', [
                'product_id' => $this->productB->id, // foreign-tenant product
                'batch_number' => 'BATCH-CROSS-001',
                'expiry_date' => now()->addYear()->toDateString(),
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('product_id', $cross->json('error.errors') ?? []);
    }

    public function test_write_off_batch_rejects_cross_tenant_location_id(): void
    {
        // Seed a same-tenant batch the user owns and a foreign-tenant
        // location the user must NOT be able to reference.
        /** @var Batch $batchA */
        $batchA = Batch::create([
            'uuid' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'batch_number' => 'BATCH-WO-A',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/batches/{$batchA->uuid}/write-off", [
                'quantity' => '1',
                'location_id' => $this->locationB->id, // foreign-company location
                'reason' => 'expiry',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('location_id', $cross->json('error.errors') ?? []);
    }

    public function test_transfer_batch_rejects_cross_tenant_from_location_id(): void
    {
        /** @var Batch $batchA */
        $batchA = Batch::create([
            'uuid' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'batch_number' => 'BATCH-TR-A',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/batches/{$batchA->uuid}/transfer", [
                'from_location_id' => $this->locationB->id, // foreign-company
                'to_location_id' => $this->locationA->id,
                'quantity' => '1',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('from_location_id', $cross->json('error.errors') ?? []);
    }

    public function test_transfer_batch_rejects_cross_tenant_to_location_id(): void
    {
        /** @var Batch $batchA */
        $batchA = Batch::create([
            'uuid' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'batch_number' => 'BATCH-TR2-A',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/batches/{$batchA->uuid}/transfer", [
                'from_location_id' => $this->locationA->id,
                'to_location_id' => $this->locationB->id, // foreign-company
                'quantity' => '1',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('to_location_id', $cross->json('error.errors') ?? []);
    }

    public function test_pos_available_batches_rejects_cross_tenant_location_id(): void
    {
        // GET /api/v1/pos/products/{productId}/batches?location_id=...&quantity=...
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson(
                "/api/v1/pos/products/{$this->productA->id}/batches"
                .'?location_id='.$this->locationB->id // foreign-company
                .'&quantity=1'
            );
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('location_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // BatchController::productBatchStock — Codex round-1 Finding 1.
    //
    // GET /api/v1/products/{productId}/batch-stock previously called
    // BatchRepository::getByProduct($productId), which scoped only by
    // product_id with no tenant_id / company_id predicates. A tenant-A
    // user passing tenant-B's productId received tenant-B batch rows.
    // Round-2 fix scopes the repository read by tenant + company resolved
    // from CompanyContext.
    // ──────────────────────────────────────────────────────────────────

    public function test_product_batch_stock_does_not_leak_cross_tenant_batches(): void
    {
        // Seed a tenant-B batch tied to productB. If the repository read
        // is unscoped, this row will appear in tenant-A's response.
        Batch::create([
            'uuid' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'product_id' => $this->productB->id,
            'batch_number' => 'BATCH-LEAK-B',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        DB::enableQueryLog();

        // Tenant-A user + company-A header, but URL targets tenant-B's productId.
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/products/{$this->productB->id}/batch-stock");

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertSame(
            [],
            $payload,
            'productBatchStock must not return foreign-tenant batch records.',
        );

        // Pin the SQL invariant: the product_batches read must filter by
        // tenant_id AND company_id.
        $batchQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "product_batches"')
                && str_contains($sql, '"product_id" =')
                && ! str_contains($sql, 'count(*)')
            ) {
                $batchQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $batchQuery,
            'product_batches lookup query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $batchQuery,
            'product_batches read must filter by tenant_id. Got SQL: '.$batchQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $batchQuery,
            'product_batches read must filter by company_id. Got SQL: '.$batchQuery,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // BatchWriteOffService::calculateWriteOffAmount (api.unmapped.014).
    // Pre-fix: bare Product::find($productId) used the batch's product_id
    // unscoped to look up the WAC. Same-tenant batches anchor a same-tenant
    // product transitively (Batch carries tenant_id + company_id, FKed to
    // products), so cross-tenant exfiltration via the public write-off route
    // is structurally blocked by the upstream BatchController guard
    // (findBatchOrFail at line 59 enforces $batch->company_id === current
    // company). The structural defense here scopes the Product lookup by
    // the source batch's tenant_id + company_id so a service-direct caller
    // (queue job, cross-module orchestrator) gets the same protection.
    // ──────────────────────────────────────────────────────────────────

    public function test_batch_write_off_query_scopes_product_by_batch_tenant_and_company(): void
    {
        // Service-direct test pin for the calculateWriteOffAmount SQL
        // shape. The HTTP-level write-off path requires substantial seeding
        // (inventory_batch_stock + GL accounts + chart-of-accounts purposes),
        // so we instead invoke the private method via reflection to pin the
        // SQL invariant on its own. The structural fix is the predicate
        // shape; the public surface is also covered by the validator-tier
        // tests above and the upstream BatchController::findBatchOrFail
        // company-id check.
        /** @var BatchWriteOffService $service */
        $service = $this->app->make(BatchWriteOffService::class);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('calculateWriteOffAmount');
        $method->setAccessible(true);

        DB::enableQueryLog();

        // Same-tenant call: returns the WAC * quantity.
        $sameTenantAmount = (string) $method->invoke(
            $service,
            $this->productA->id,           // productId
            '1',                           // quantity
            $this->tenantA->id,            // tenantId
            $this->companyA->id,           // companyId
        );

        // Cross-tenant call: same productId BUT tenantB / companyB.
        // Pre-fix this would still find the product (Product::find($id) is
        // unscoped). Post-fix it must return '0.00' because the scoped
        // chain finds nothing.
        $crossTenantAmount = (string) $method->invoke(
            $service,
            $this->productA->id,           // productA's UUID
            '1',                           // quantity
            $this->tenantB->id,            // foreign tenant
            $this->companyB->id,           // foreign company
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            '0.00',
            $crossTenantAmount,
            'calculateWriteOffAmount must return 0.00 when productId belongs '
                .'to a different tenant + company than passed in.',
        );

        // Find the products lookup that fires inside
        // calculateWriteOffAmount.
        $productLookupQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "products"')
                && str_contains($sql, '"id" =')
                && str_contains($sql, 'limit 1')
                && ! str_contains($sql, 'count(*)')
                && ! str_contains($sql, 'inner join')
            ) {
                $productLookupQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $productLookupQuery,
            'BatchWriteOffService Product lookup query must be captured. '
                .'Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $productLookupQuery,
            'BatchWriteOffService Product lookup must filter by tenant_id. Got SQL: '.$productLookupQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $productLookupQuery,
            'BatchWriteOffService Product lookup must filter by company_id. Got SQL: '.$productLookupQuery,
        );

        // Same-tenant path returns cost_price * quantity. productA is
        // seeded with cost_price = '10.00' (no weighted_average_cost
        // column on the model), so 10.00 * 1 = 10.00. This makes the
        // value pin discriminate: pre-fix the unscoped Product::find
        // would return '10.00' for BOTH calls (because productA still
        // matches by id alone), while post-fix the scoped lookup
        // returns null for cross-tenant → '0.00'.
        $this->assertSame('10.00', $sameTenantAmount);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.inventory.017 — StockAdjustmentService::getOrCreateStockLevel
    // Location lookup must be scoped by company_id so a forged location
    // from another company cannot be used to mix stock-level rows.
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function it_scopes_get_or_create_stock_level_by_company(): void
    {
        // Pin the SQL shape: the Location lookup inside getOrCreateStockLevel
        // must include a company_id predicate so a forged cross-company
        // locationId cannot be used to seed a mixed-company StockLevel.
        StockLevel::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->locationA->id,
            'quantity' => '5.00',
            'reserved' => '0.00',
        ]);

        /** @var StockAdjustmentService $svc */
        $svc = $this->app->make(StockAdjustmentService::class);

        DB::enableQueryLog();

        // Use adjust() which calls getOrCreateStockLevel() internally with
        // a company-scoped path; same-company call so it succeeds.
        $svc->adjust(
            productId: $this->productA->id,
            locationId: $this->locationA->id,
            newQuantity: '6.00',
            reason: 'TEST-017',
            userId: (string) $this->userA->id,
            expectedCompanyId: $this->companyA->id,
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Find the Location SELECT that fires inside getOrCreateStockLevel.
        $locationQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "locations"')
                && str_contains($sql, '"id" =')
            ) {
                $locationQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $locationQuery,
            'A Location lookup query must fire inside getOrCreateStockLevel. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"company_id"',
            $locationQuery,
            'getOrCreateStockLevel Location lookup must filter by company_id. Got SQL: '.$locationQuery,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.inventory.018 — StockAdjustmentService::recordMovement
    // Location lookup in recordMovement must be scoped by company_id.
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function it_scopes_record_movement_location_lookup_by_company(): void
    {
        DB::enableQueryLog();

        /** @var StockAdjustmentService $svc */
        $svc = $this->app->make(StockAdjustmentService::class);

        // First seed a valid stock level for productA / locationA so
        // getOrCreateStockLevel succeeds; then verify the Location query
        // inside recordMovement is company-scoped.
        StockLevel::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->locationA->id,
            'quantity' => '10.00',
            'reserved' => '0.00',
        ]);

        $svc->receive(
            productId: $this->productA->id,
            locationId: $this->locationA->id,
            quantity: '1.00',
            reference: 'TEST-018',
            userId: (string) $this->userA->id,
            expectedCompanyId: $this->companyA->id,
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Find the Location SELECT that fires inside recordMovement.
        $locationQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "locations"')
                && str_contains($sql, '"id" =')
            ) {
                $locationQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $locationQuery,
            'A Location lookup query must fire inside recordMovement. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"company_id"',
            $locationQuery,
            'recordMovement Location lookup must filter by company_id. Got SQL: '.$locationQuery,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.inventory.029 — CountingItemController::triggerThirdCount
    // item_ids.* validator must be scoped to the parent counting_id.
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function it_rejects_cross_company_item_id_in_trigger_third_count_validator(): void
    {
        // Create a counting for tenantB / companyB with one item.
        $countingB = InventoryCounting::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'created_by_user_id' => (string) $this->userB->id,
            'status' => CountingStatus::Draft,
            'scope_type' => 'product',
            'scope_filters' => ['product_ids' => []],
            'execution_mode' => 'sequential',
        ]);

        $itemB = InventoryCountingItem::create([
            'counting_id' => $countingB->id,
            'product_id' => $this->productB->id,
            'location_id' => $this->locationB->id,
            'theoretical_qty' => '0.00',
        ]);

        // userA tries to trigger a third count on draftCountingA but injects
        // itemB (from companyB) as the item_id. The scoped validator must
        // reject this with a 422 because itemB.counting_id !== draftCountingA.id.
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/inventory/countings/{$this->draftCountingA->id}/trigger-third-count", [
                'item_ids' => [(string) $itemB->id],
            ]);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.inventory.030 — FraudTriggeredCountingService::createCountingFromAlert
    // System-user lookup must be scoped to alert.tenant_id; must fail loud
    // if no system user exists for that tenant (no cross-tenant fallback).
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function it_scopes_fraud_system_user_to_alert_tenant(): void
    {
        // Create an alert for tenantB.
        $alert = FraudAlert::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'user_id' => $this->userB->id,
            'alert_type' => 'abandoned_drafts',
            'severity' => 'medium',
            'description' => 'Test alert',
            'detected_at' => now(),
            'flagged_products' => [
                [
                    'product_id' => $this->productB->id,
                    'product_name' => 'Product B',
                    'count' => 3,
                ],
            ],
            'status' => 'open',
        ]);

        // There is NO system@autoerp.local user for tenantB. The old code
        // fell back to User::first() (which would return userA, crossing tenants).
        // The fixed code must throw a RuntimeException instead.
        /** @var FraudTriggeredCountingService $svc */
        $svc = $this->app->make(FraudTriggeredCountingService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No system user found for tenant/');

        $svc->createCountingFromAlert($alert);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.inventory.031 — InventoryCountingController::createDraft
    // tenant_id must be stamped on the new InventoryCounting record.
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function it_persists_tenant_id_on_inventory_counting_draft_create(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/inventory/countings/drafts', [
                'scope_type' => 'product',
                'scope_filters' => ['product_ids' => []],
            ]);

        $response->assertStatus(201);

        $id = $response->json('data.id');
        $this->assertNotNull($id, 'Response must include data.id');

        /** @var InventoryCounting $counting */
        $counting = InventoryCounting::findOrFail($id);
        $this->assertSame(
            $this->tenantA->id,
            $counting->tenant_id,
            'createDraft must persist tenant_id on the InventoryCounting row.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.inventory.032 — WeightedAverageCostService
    // StockLevel lock tuples in recordPurchase/Sale/Return must include
    // company_id so a forged location from another company cannot be used.
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function it_locks_stock_level_tuples_with_company_id(): void
    {
        /** @var WeightedAverageCostService $wac */
        $wac = $this->app->make(WeightedAverageCostService::class);

        // Seed a stock level so lockForUpdate finds a row (otherwise
        // recordSale throws InsufficientStock before the lock query fires).
        StockLevel::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'location_id' => $this->locationA->id,
            'quantity' => '50.00',
            'reserved' => '0.00',
        ]);

        DB::enableQueryLog();

        $wac->recordSale(
            product: $this->productA,
            location: $this->locationA,
            quantity: 1.0,
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Find the StockLevel SELECT (lock) that fires inside recordSale.
        $stockLevelLockQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "stock_levels"')
                && str_contains($sql, '"product_id"')
                && str_contains($sql, '"location_id"')
            ) {
                $stockLevelLockQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $stockLevelLockQuery,
            'StockLevel lock query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"company_id"',
            $stockLevelLockQuery,
            'WAC StockLevel lock tuple must include company_id. Got SQL: '.$stockLevelLockQuery,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.inventory.033 — StockReservationService::releaseBySource
    // Must accept caller-supplied tenant+company scope and filter
    // reservations so a forged sourceId from another company cannot
    // trigger release of cross-tenant reservations.
    // ──────────────────────────────────────────────────────────────────

    /** @test */
    public function it_scopes_release_by_source_to_caller_tenant_and_company(): void
    {
        /** @var StockReservationService $svc */
        $svc = $this->app->make(StockReservationService::class);

        // Seed a stock level for companyB so a reservation can be created.
        StockLevel::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'product_id' => $this->productB->id,
            'location_id' => $this->locationB->id,
            'quantity' => '10.00',
            'reserved' => '5.00',
        ]);

        // Create a reservation for companyB using a known sourceId.
        $sourceId = Str::uuid()->toString();
        $reservationB = StockReservation::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyB->id,
            'product_id' => $this->productB->id,
            'location_id' => $this->locationB->id,
            'quantity' => '1.00',
            'source_type' => ReservationSource::SalesOrder,
            'source_id' => $sourceId,
            'priority' => 0,
        ]);

        // userA (companyA) calls releaseBySource with companyA's scope
        // but passes companyB's sourceId. The scoped filter must return
        // zero rows → no reservations released (no cross-company release).
        $released = $svc->releaseBySource(
            sourceType: ReservationSource::SalesOrder,
            sourceId: $sourceId,
            reason: ReleaseReason::Cancelled,
            releasedBy: (string) $this->userA->id,
            expectedTenantId: $this->tenantA->id,
            expectedCompanyId: $this->companyA->id,
        );

        $this->assertSame(
            0,
            $released,
            'releaseBySource with companyA scope must not release companyB reservations.',
        );

        // The companyB reservation must still be active (not released).
        $reservationB->refresh();
        $this->assertNull(
            $reservationB->released_at,
            'CompanyB reservation must remain active after cross-company release attempt.',
        );
    }

    /**
     * Authenticate $user and pin the company context header to $company.
     */
    private function actingAsForTenant(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
