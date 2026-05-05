<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Coupon\Application\Services\CouponApplicationService;
use App\Modules\Coupon\Domain\Entities\Coupon;
use App\Modules\Coupon\Domain\Entities\CouponUsage;
use App\Modules\Coupon\Domain\Enums\CouponStatus;
use App\Modules\Coupon\Domain\Enums\CouponType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Pricing\Domain\PartnerPriceList;
use App\Modules\Pricing\Domain\PriceList;
use App\Modules\Pricing\Domain\PriceListItem;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.pricing cluster) — tenant-isolation regression coverage.
 *
 * The api.pricing cluster has 10 inventoried callsites:
 *   FormRequest validators (9 inline $request->validate() in PricingController):
 *     - api.pricing.003  addItem            product_id        -> products
 *     - api.pricing.004  assignToPartner    partner_id        -> partners
 *     - api.pricing.005  getPrice           product_id        -> products
 *     - api.pricing.006  getPrice           partner_id        -> partners
 *     - api.pricing.007  getQuantityBreaks  product_id        -> products
 *     - api.pricing.008  getBulkPrices      product_ids.*     -> products
 *     - api.pricing.009  getBulkPrices      partner_id        -> partners
 *     - api.pricing.010  checkMargin        product_id        -> products
 *   Controller findOrFail (1):
 *     - api.pricing.002  PricingController::checkMargin       Product::findOrFail
 *   Domain-service findOrFail (1):
 *     - api.pricing.001  PricingService::getPrice (line 62)   Product::findOrFail
 *
 * Hostile-grep blind spots also closed in this cluster commit:
 *     - PricingController inline validate price_lists,id at getQuantityBreaks (line 293)
 *     - PriceList::findOrFail($id) chains in show / update / destroy / addItem /
 *       assignToPartner — every PriceList route-anchored read now leads with
 *       tenant_id + company_id predicates per the cluster invariant.
 *
 * Schema:
 *   - products:    tenant_id + company_id (BOTH required in the cluster invariant).
 *   - partners:    tenant_id + company_id.
 *   - price_lists: tenant_id + company_id.
 */
final class PricingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Product $productA;

    private Product $productB;

    private Partner $partnerA;

    private Partner $partnerB;

    private PriceList $priceListA;

    private PriceList $priceListB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-pricing-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-pricing-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-PRICING',
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
            'tax_id' => 'TAX-B-PRICING',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        // The pricing.view / pricing.manage permissions are not in the
        // canonical seeder list, so the route middleware can:pricing.* would
        // 403 even an admin. Register them per-tenant here so the test
        // exercises the isolation predicate, not a missing-permission gate.
        Permission::findOrCreate('pricing.view', 'sanctum');
        Permission::findOrCreate('pricing.manage', 'sanctum');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findOrCreate('pricing.view', 'sanctum');
        Permission::findOrCreate('pricing.manage', 'sanctum');

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-pricing-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->givePermissionTo(['pricing.view', 'pricing.manage']);

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        $this->productA = Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'sku' => 'SKU-A-PRICING',
            'name' => 'Product A',
            'type' => 'part',
            'cost_price' => '10.00',
            'sale_price' => '20.00',
            'is_active' => true,
        ]);
        $this->productB = Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'sku' => 'SKU-B-PRICING',
            'name' => 'Product B',
            'type' => 'part',
            'cost_price' => '10.00',
            'sale_price' => '20.00',
            'is_active' => true,
        ]);

        $this->partnerA = Partner::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Partner A',
            'type' => 'customer',
        ]);
        $this->partnerB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Partner B',
            'type' => 'customer',
        ]);

        $this->priceListA = PriceList::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'code' => 'PL-A',
            'name' => 'Price List A',
            'currency' => 'EUR',
            'is_active' => true,
        ]);
        $this->priceListB = PriceList::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'code' => 'PL-B',
            'name' => 'Price List B',
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        PriceListItem::create([
            'id' => Str::uuid()->toString(),
            'price_list_id' => $this->priceListA->id,
            'product_id' => $this->productA->id,
            'price' => '15.00',
            'min_quantity' => '1',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // FormRequest validators (inline $request->validate)
    // ──────────────────────────────────────────────────────────────────

    public function test_add_item_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/price-lists/{$this->priceListA->id}/items", [
                'product_id' => $this->productB->id,
                'price' => '10.00',
                'min_quantity' => '1',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('product_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/price-lists/{$this->priceListA->id}/items", [
                'product_id' => $this->productA->id,
                'price' => '12.00',
                'min_quantity' => '5',
            ]);
        $same->assertStatus(201);
    }

    public function test_assign_to_partner_rejects_cross_tenant_partner_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/price-lists/{$this->priceListA->id}/partners", [
                'partner_id' => $this->partnerB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('partner_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/price-lists/{$this->priceListA->id}/partners", [
                'partner_id' => $this->partnerA->id,
            ]);
        $same->assertStatus(201);
    }

    public function test_get_price_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/get-price', [
                'product_id' => $this->productB->id,
                'quantity' => '1',
                'currency' => 'EUR',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('product_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/get-price', [
                'product_id' => $this->productA->id,
                'quantity' => '1',
                'currency' => 'EUR',
            ]);
        $same->assertStatus(200);
    }

    public function test_get_price_rejects_cross_tenant_partner_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/get-price', [
                'product_id' => $this->productA->id,
                'partner_id' => $this->partnerB->id,
                'quantity' => '1',
                'currency' => 'EUR',
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('partner_id', $cross->json('error.errors') ?? []);
    }

    public function test_get_quantity_breaks_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/quantity-breaks', [
                'price_list_id' => $this->priceListA->id,
                'product_id' => $this->productB->id,
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('product_id', $cross->json('error.errors') ?? []);
    }

    public function test_get_quantity_breaks_rejects_cross_tenant_price_list_id(): void
    {
        // Hostile-grep blind-spot bundled fix: the inline validator at line 293
        // was bare exists:price_lists,id, allowing any tenant's price_list UUID.
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/quantity-breaks', [
                'price_list_id' => $this->priceListB->id,
                'product_id' => $this->productA->id,
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('price_list_id', $cross->json('error.errors') ?? []);
    }

    public function test_get_bulk_prices_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/bulk-prices', [
                'product_ids' => [$this->productB->id],
                'currency' => 'EUR',
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('product_ids.0', $cross->json('error.errors') ?? []);
    }

    public function test_get_bulk_prices_rejects_cross_tenant_partner_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/bulk-prices', [
                'product_ids' => [$this->productA->id],
                'partner_id' => $this->partnerB->id,
                'currency' => 'EUR',
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('partner_id', $cross->json('error.errors') ?? []);
    }

    public function test_check_margin_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->productB->id,
                'sell_price' => '20.00',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('product_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->productA->id,
                'sell_price' => '20.00',
            ]);
        $same->assertStatus(200);
    }

    // ──────────────────────────────────────────────────────────────────
    // PriceList route-param findOrFail blind-spots (show / update /
    //   destroy / addItem-priceList / assignToPartner-priceList)
    // ──────────────────────────────────────────────────────────────────

    public function test_show_price_list_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/price-lists/{$this->priceListB->id}");
        $cross->assertStatus(404);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/price-lists/{$this->priceListA->id}");
        $same->assertStatus(200);
    }

    public function test_update_price_list_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/price-lists/{$this->priceListB->id}", [
                'name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);

        $this->assertSame(
            'Price List B',
            $this->priceListB->fresh()?->name,
            'Cross-tenant price_list must remain unchanged.',
        );
    }

    public function test_destroy_price_list_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/price-lists/{$this->priceListB->id}");
        $cross->assertStatus(404);

        $this->assertNotNull(
            $this->priceListB->fresh(),
            'Cross-tenant price_list must NOT have been deleted.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Round-2 fixes (Opus Findings 1, 2, 4)
    // ──────────────────────────────────────────────────────────────────

    public function test_index_returns_only_same_tenant_price_lists(): void
    {
        // Opus Finding 1 (CRITICAL): pre-fix `index` paginated all tenants'
        // price-lists. Tenant-A user must see priceListA only, NOT priceListB.
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson('/api/v1/price-lists');
        $response->assertStatus(200);

        $data = $response->json('data') ?? [];
        $ids = array_column($data, 'id');

        $this->assertContains($this->priceListA->id, $ids, 'Same-tenant price_list must appear in /price-lists.');
        $this->assertNotContains($this->priceListB->id, $ids, 'Cross-tenant price_list must NOT appear in /price-lists.');
    }

    public function test_index_query_includes_tenant_and_company_predicates(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson('/api/v1/price-lists')
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        $listingQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "price_lists"')
                && (str_contains($sql, 'limit 20') || str_contains($sql, '"latest"') || str_contains($sql, 'order by'))
                && ! str_contains($sql, 'count(*)')
            ) {
                $listingQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $listingQuery,
            'PriceList listing query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $listingQuery,
            'PriceList listing must filter by tenant_id. Got SQL: '.$listingQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $listingQuery,
            'PriceList listing must filter by company_id. Got SQL: '.$listingQuery,
        );
    }

    public function test_remove_from_partner_rejects_cross_tenant_partner_id(): void
    {
        // Opus round-1 Finding 4 + round-2 Finding C (test honesty
        // strengthening): pre-fix the $partnerId route segment was passed
        // straight into the PartnerPriceList lookup with no Partner tenant
        // validation. Round-2 fix pre-loads Partner tenant-scoped before
        // the PartnerPriceList lookup.
        //
        // Honest test pin: seed a cross-tenant PartnerPriceList row
        // (priceListA tenant-A + partnerB tenant-B) so the firstOrFail
        // would NOT 404 on a missing join row pre-fix. Without this seed
        // the test "passes for the wrong reason" — firstOrFail returns
        // 404 anyway because no row exists. The cross-tenant PartnerPriceList
        // is a structurally-possible legacy/migration corruption scenario
        // (no FK constraint enforces tenant match across the two FKs).
        // Pre-fix DELETE would silently succeed and remove the assignment;
        // post-fix the Partner pre-load 404s before reaching the assignment.
        PartnerPriceList::create([
            'id' => Str::uuid()->toString(),
            'price_list_id' => $this->priceListA->id,
            'partner_id' => $this->partnerB->id,
            'is_active' => true,
            'priority' => 0,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/price-lists/{$this->priceListA->id}/partners/{$this->partnerB->id}");
        $cross->assertStatus(404);

        // Post-condition: cross-tenant PartnerPriceList row must still exist.
        $this->assertDatabaseHas('partner_price_lists', [
            'price_list_id' => $this->priceListA->id,
            'partner_id' => $this->partnerB->id,
        ]);
    }

    public function test_get_price_falls_back_to_same_tenant_default_only(): void
    {
        // Opus Finding 2 (IMPORTANT): pre-fix getDefaultPriceListPrice could
        // pick a foreign-tenant default price-list when the same-tenant
        // didn't have one. Set up: tenant-B has a default EUR price-list
        // AND a PriceListItem entry linking that default to productA's UUID
        // (cross-tenant data corruption scenario — productA belongs to
        // tenant-A but a foreign PriceListItem references its UUID). Pre-fix
        // getPrice would return tenant-B's price_list_id + price; post-fix
        // the default-list query rejects priceListB before getPriceFromList
        // is even called.
        //
        // The cross-tenant PriceListItem seed is what makes this test pin
        // the production path — without it, getPriceFromList returns null
        // (no item match) and the response.data.price_list_id falls through
        // to null, which would assertNotSame === priceListB.id even pre-fix.
        $this->priceListB->update(['is_default' => true]);
        PriceListItem::create([
            'id' => Str::uuid()->toString(),
            'price_list_id' => $this->priceListB->id,
            'product_id' => $this->productA->id, // cross-tenant FK on UUID; no DB constraint enforces tenant match
            'price' => '99.00', // distinct from priceListA's 15.00
            'min_quantity' => '1',
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/get-price', [
                'product_id' => $this->productA->id,
                'quantity' => '1',
                'currency' => 'EUR',
            ]);
        $response->assertStatus(200);

        $this->assertNotSame(
            $this->priceListB->id,
            $response->json('data.price_list_id'),
            'getPrice must NOT leak a foreign-tenant price_list_id via the default-list fallback.',
        );
        $this->assertNotSame(
            '99.00',
            $response->json('data.price'),
            'getPrice must NOT return a foreign-tenant price via the default-list fallback.',
        );
    }

    public function test_get_partner_price_join_filters_price_lists_by_tenant(): void
    {
        // Opus round-2 Finding B (NICE-TO-HAVE): the getPartnerPrice DB::table
        // join now filters price_lists.tenant_id + price_lists.company_id.
        // Capture the SQL emitted under a same-tenant partner_id and assert
        // BOTH literals appear. Pre-fix the join was tenant-blind.
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/get-price', [
                'product_id' => $this->productA->id,
                'partner_id' => $this->partnerA->id,
                'quantity' => '1',
                'currency' => 'EUR',
            ])
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        $partnerJoinQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "partner_price_lists"')
                && str_contains($sql, 'inner join "price_lists"')
            ) {
                $partnerJoinQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $partnerJoinQuery,
            'partner_price_lists -> price_lists join query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"price_lists"."tenant_id"',
            $partnerJoinQuery,
            'getPartnerPrice join must filter price_lists by tenant_id. Got SQL: '.$partnerJoinQuery,
        );
        $this->assertStringContainsString(
            '"price_lists"."company_id"',
            $partnerJoinQuery,
            'getPartnerPrice join must filter price_lists by company_id. Got SQL: '.$partnerJoinQuery,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants (bar-raising pattern)
    // ──────────────────────────────────────────────────────────────────

    public function test_check_margin_validator_query_includes_tenant_and_company_predicates(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/pricing/check-margin', [
                'product_id' => $this->productA->id,
                'sell_price' => '20.00',
            ])
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        // Find the products exists-validation query.
        $productsValidatorQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "products"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'exists') || str_contains($sql, 'count(*)'))
            ) {
                $productsValidatorQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $productsValidatorQuery,
            'Products exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $productsValidatorQuery,
            'checkMargin product_id validator must filter by tenant_id. Got SQL: '.$productsValidatorQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $productsValidatorQuery,
            'checkMargin product_id validator must filter by company_id. Got SQL: '.$productsValidatorQuery,
        );
    }

    public function test_show_price_list_query_includes_tenant_and_company_predicates(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/price-lists/{$this->priceListA->id}")
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        $priceListQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "price_lists"')
                && str_contains($sql, '"id" =')
                && ! str_contains($sql, 'count(*)')
            ) {
                $priceListQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $priceListQuery,
            'PriceList lookup query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $priceListQuery,
            'PriceList route-anchored lookup must filter by tenant_id. Got SQL: '.$priceListQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $priceListQuery,
            'PriceList route-anchored lookup must filter by company_id. Got SQL: '.$priceListQuery,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // CouponApplicationService::recordUsage — service-tier scoped guard
    // (api.unmapped.017 — reassigned to api.pricing).
    //
    // Pre-fix: `Coupon::findOrFail($couponId)` had no tenant/company
    // predicate, so a service caller pinned to tenant-A could decrement
    // / mark-exhaust a foreign-tenant coupon by passing its UUID. Post-fix:
    // the service derives the tenant + company from the injected
    // CompanyContext and rejects cross-tenant couponIds with
    // ModelNotFoundException before any usage row is created.
    // ──────────────────────────────────────────────────────────────────

    public function test_record_usage_rejects_cross_tenant_coupon_id(): void
    {
        /** @var Coupon $couponB */
        $couponB = Coupon::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Coupon B',
            'code' => 'COUPONB',
            'type' => CouponType::Standard,
            'status' => CouponStatus::Active,
            'discount_type' => 'percentage',
            'discount_value' => '10.00',
            'max_uses' => 5,
            'use_count' => 0,
        ]);

        // Pin company context to tenant-A; the tenant-B coupon must NOT
        // be findable.
        /** @var CompanyContext $context */
        $context = app(CompanyContext::class);
        $context->setCompanyId($this->companyA->id);

        /** @var CouponApplicationService $service */
        $service = app(CouponApplicationService::class);

        $thrown = null;
        try {
            $service->recordUsage(
                couponId: $couponB->id,
                receiptId: Str::uuid()->toString(),
                partnerId: null,
                discountAmount: '5.00',
            );
        } catch (ModelNotFoundException $e) {
            $thrown = $e;
        }

        $this->assertNotNull(
            $thrown,
            'recordUsage must reject cross-tenant coupon id with ModelNotFoundException.',
        );

        // Post-condition: the foreign coupon's use_count must NOT have
        // incremented and no CouponUsage row must reference it.
        $couponB->refresh();
        $this->assertSame(
            0,
            $couponB->use_count,
            'Cross-tenant recordUsage must NOT decrement use_count on the foreign coupon.',
        );
        $this->assertSame(
            CouponStatus::Active,
            $couponB->status,
            'Cross-tenant recordUsage must NOT change status on the foreign coupon.',
        );
        $this->assertSame(
            0,
            CouponUsage::where('coupon_id', $couponB->id)->count(),
            'Cross-tenant recordUsage must NOT create a CouponUsage row for the foreign coupon.',
        );
    }

    public function test_record_usage_accepts_same_tenant_coupon_id(): void
    {
        /** @var Coupon $couponA */
        $couponA = Coupon::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Coupon A',
            'code' => 'COUPONA',
            'type' => CouponType::Standard,
            'status' => CouponStatus::Active,
            'discount_type' => 'percentage',
            'discount_value' => '10.00',
            'max_uses' => 5,
            'use_count' => 0,
        ]);

        /** @var CompanyContext $context */
        $context = app(CompanyContext::class);
        $context->setCompanyId($this->companyA->id);

        /** @var CouponApplicationService $service */
        $service = app(CouponApplicationService::class);

        $service->recordUsage(
            couponId: $couponA->id,
            receiptId: Str::uuid()->toString(),
            partnerId: null,
            discountAmount: '5.00',
        );

        $couponA->refresh();
        $this->assertSame(
            1,
            $couponA->use_count,
            'Same-tenant recordUsage must increment use_count on the local coupon.',
        );
        $this->assertSame(
            1,
            CouponUsage::where('coupon_id', $couponA->id)->count(),
            'Same-tenant recordUsage must create a CouponUsage row.',
        );
    }

    public function test_record_usage_query_includes_tenant_and_company_predicates(): void
    {
        /** @var Coupon $couponA */
        $couponA = Coupon::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Coupon A SQL',
            'code' => 'COUPONAQL',
            'type' => CouponType::Standard,
            'status' => CouponStatus::Active,
            'discount_type' => 'percentage',
            'discount_value' => '10.00',
            'max_uses' => 5,
            'use_count' => 0,
        ]);

        /** @var CompanyContext $context */
        $context = app(CompanyContext::class);
        $context->setCompanyId($this->companyA->id);

        DB::enableQueryLog();
        /** @var CouponApplicationService $service */
        $service = app(CouponApplicationService::class);
        $service->recordUsage(
            couponId: $couponA->id,
            receiptId: Str::uuid()->toString(),
            partnerId: null,
            discountAmount: '5.00',
        );
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $couponLookupQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "coupons"')
                && str_contains($sql, '"id" =')
                && ! str_contains($sql, 'count(*)')
            ) {
                $couponLookupQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $couponLookupQuery,
            'CouponApplicationService::recordUsage Coupon lookup query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $couponLookupQuery,
            'recordUsage must filter Coupon lookup by tenant_id. Got SQL: '.$couponLookupQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $couponLookupQuery,
            'recordUsage must filter Coupon lookup by company_id. Got SQL: '.$couponLookupQuery,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // CouponController route-anchored reads — Codex round-1 second-layer
    // finding: pre-fix chains were `Coupon::query()->forCompany($companyId)
    // ->findOrFail($id)` (company-only scope; missing tenant_id predicate
    // per the cluster invariant). Round-2 remediation adds forTenant()
    // to the chain in show/update/destroy/revoke/reactivate (5 routes).
    // ──────────────────────────────────────────────────────────────────

    public function test_show_coupon_query_includes_tenant_and_company_predicates(): void
    {
        /** @var Coupon $couponA */
        $couponA = Coupon::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Coupon A Show SQL',
            'code' => 'COUPONASQL',
            'type' => CouponType::Standard,
            'status' => CouponStatus::Active,
            'discount_type' => 'percentage',
            'discount_value' => '10.00',
            'max_uses' => 5,
            'use_count' => 0,
        ]);

        DB::enableQueryLog();
        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/coupons/{$couponA->id}")
            ->assertStatus(200);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $couponQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "coupons"')
                && str_contains($sql, '"id" =')
                && ! str_contains($sql, 'count(*)')
            ) {
                $couponQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $couponQuery,
            'CouponController::show coupon lookup query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $couponQuery,
            'CouponController::show must filter coupon lookup by tenant_id. Got SQL: '.$couponQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $couponQuery,
            'CouponController::show must also filter coupon lookup by company_id. Got SQL: '.$couponQuery,
        );
    }

    public function test_show_coupon_rejects_cross_tenant_id(): void
    {
        /** @var Coupon $couponB */
        $couponB = Coupon::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Coupon B',
            'code' => 'COUPONB-CROSS',
            'type' => CouponType::Standard,
            'status' => CouponStatus::Active,
            'discount_type' => 'percentage',
            'discount_value' => '10.00',
            'max_uses' => 5,
            'use_count' => 0,
        ]);

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/coupons/{$couponB->id}")
            ->assertStatus(404);
    }

    /**
     * Authenticate `$user` and pin the company context header to `$company`.
     */
    private function actingAsForTenant(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
