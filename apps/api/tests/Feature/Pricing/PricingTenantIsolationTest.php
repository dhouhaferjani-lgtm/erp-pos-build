<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Pricing\Domain\PriceList;
use App\Modules\Pricing\Domain\PriceListItem;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
