<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.cart cluster) — tenant-isolation regression coverage.
 *
 * Inventory: 2 callsites in CartConversionService:
 *   - api.cart.001: convertToPurchaseOrder line 49 — `Partner::find($partnerId)`
 *     anchored on a CatalogCartItem.preferred_supplier_partner_id.
 *   - api.cart.002: convertToSalesOrder line 139 — `Partner::findOrFail($customerId)`
 *     anchored on a request body param.
 *
 * Plus 9 CatalogCartController route-anchored bare-where chains the scanner
 * missed (each `CatalogCart::query()->where('company_id', $company->id)
 * ->findOrFail($id)`). Per the cluster invariant Codex established in
 * Treasury round-3 Finding 14, these all need tenant_id added.
 *
 * The convert() endpoint's `customer_id` body param now also carries a
 * ScopedExists::tenantAndCompany on partners — defense-in-depth so a
 * cross-tenant id never reaches the service-layer findOrFail.
 */
final class CartTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private CatalogCart $cartA;

    private CatalogCart $cartB;

    private Partner $supplierB;

    private Partner $customerA;

    private Partner $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-cart-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-cart-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-CART',
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
            'tax_id' => 'TAX-B-CART',
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
            'email' => 'alice-cart-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bob',
            'email' => 'bob-cart-iso@example.com',
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

        $this->cartA = CatalogCart::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'name' => 'Cart A',
        ]);
        $this->cartB = CatalogCart::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'user_id' => $this->userB->id,
            'name' => 'Cart B',
        ]);

        $this->supplierB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Supplier B',
            'type' => 'supplier',
        ]);
        $this->customerA = Partner::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Customer A',
            'type' => 'customer',
        ]);
        $this->customerB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Customer B',
            'type' => 'customer',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // CatalogCart route-anchored lookups (controller bare-where blind spots)
    // ──────────────────────────────────────────────────────────────────

    public function test_show_rejects_cross_tenant_cart_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/catalog-carts/{$this->cartB->id}");
        $cross->assertStatus(404);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/catalog-carts/{$this->cartA->id}");
        $same->assertStatus(200);
    }

    public function test_destroy_rejects_cross_tenant_cart_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/catalog-carts/{$this->cartB->id}");
        $cross->assertStatus(404);

        $this->assertNotNull(
            $this->cartB->fresh(),
            'Cross-tenant cart must NOT have been deleted.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // convert() — customer_id body param (api.cart.002 service-tier path)
    // ──────────────────────────────────────────────────────────────────

    public function test_convert_to_sales_order_rejects_cross_tenant_customer_id(): void
    {
        // Seed a cart item so convert has something to operate on.
        CatalogCartItem::create([
            'cart_id' => $this->cartA->id,
            'source' => 'manual',
            'article_name' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '10.00',
        ]);
        /** @var CatalogCartItem $item */
        $item = $this->cartA->items()->first();

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/catalog-carts/{$this->cartA->id}/convert", [
                'item_ids' => [$item->id],
                'type' => 'sales_order',
                'customer_id' => $this->customerB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('customer_id', $cross->json('error.errors') ?? []);
    }

    public function test_convert_to_sales_order_accepts_same_tenant_customer_id(): void
    {
        CatalogCartItem::create([
            'cart_id' => $this->cartA->id,
            'source' => 'manual',
            'article_name' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '10.00',
        ]);
        /** @var CatalogCartItem $item */
        $item = $this->cartA->items()->first();

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/catalog-carts/{$this->cartA->id}/convert", [
                'item_ids' => [$item->id],
                'type' => 'sales_order',
                'customer_id' => $this->customerA->id,
            ]);
        $same->assertStatus(201);
    }

    // ──────────────────────────────────────────────────────────────────
    // convert() — preferred_supplier_partner_id sourced from cart item
    // (api.cart.001 service-tier path — Partner::find with potentially
    //  cross-tenant id; pre-fix it could have leaked supplier names.)
    //
    // Test seeds a cart item carrying tenant-B's supplier id. The convert
    // endpoint creates a purchase order; with the fix the cross-tenant
    // Partner::find returns null and a same-tenant fallback partner is
    // created (the existing fallback path), so no cross-tenant data leak.
    // ──────────────────────────────────────────────────────────────────

    public function test_convert_to_purchase_order_does_not_link_cross_tenant_supplier(): void
    {
        CatalogCartItem::create([
            'cart_id' => $this->cartA->id,
            'source' => 'manual',
            'article_name' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '10.00',
            'preferred_supplier_partner_id' => $this->supplierB->id,
        ]);
        /** @var CatalogCartItem $item */
        $item = $this->cartA->items()->first();

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/catalog-carts/{$this->cartA->id}/convert", [
                'item_ids' => [$item->id],
                'type' => 'purchase_order',
            ]);
        $response->assertStatus(201);

        // Critical post-condition: the resulting PO partner must NOT be
        // tenant-B's supplier. Pre-fix Partner::find would have happily
        // returned tenant-B's supplier (UUIDs collide across tenants only
        // by accident, but the read shape was unscoped).
        $documents = $response->json('data');
        $this->assertIsArray($documents);
        $this->assertNotEmpty($documents);
        $documentId = $documents[0]['id'];

        $document = Document::query()->where('id', $documentId)->first();
        $this->assertNotNull($document);
        $this->assertNotSame(
            $this->supplierB->id,
            $document->partner_id,
            'Converted purchase order must NOT have linked tenant-B supplier.',
        );
        $this->assertSame(
            $this->tenantA->id,
            $document->tenant_id,
            'Document must be tenant-A scoped.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants
    // ──────────────────────────────────────────────────────────────────

    public function test_show_query_includes_tenant_and_company_predicates(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/catalog-carts/{$this->cartA->id}")
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        $cartQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "catalog_carts"')
                && str_contains($sql, 'limit 1')
            ) {
                $cartQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $cartQuery,
            'CatalogCart lookup query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $cartQuery,
            'CatalogCart route-anchored lookup must filter by tenant_id. Got SQL: '.$cartQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $cartQuery,
            'CatalogCart route-anchored lookup must also filter by company_id. Got SQL: '.$cartQuery,
        );
    }

    public function test_convert_validator_query_includes_tenant_and_company_predicates(): void
    {
        CatalogCartItem::create([
            'cart_id' => $this->cartA->id,
            'source' => 'manual',
            'article_name' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '10.00',
        ]);
        /** @var CatalogCartItem $item */
        $item = $this->cartA->items()->first();

        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/catalog-carts/{$this->cartA->id}/convert", [
                'item_ids' => [$item->id],
                'type' => 'sales_order',
                'customer_id' => $this->customerA->id,
            ])
            ->assertStatus(201);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        // Locate the partners exists-validation query for customer_id.
        $partnersValidationQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "partners"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'exists') || str_contains($sql, 'count(*)'))
            ) {
                $partnersValidationQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $partnersValidationQuery,
            'Partners exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $partnersValidationQuery,
            'convert customer_id validator must filter by tenant_id. Got SQL: '.$partnersValidationQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $partnersValidationQuery,
            'convert customer_id validator must filter by company_id. Got SQL: '.$partnersValidationQuery,
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
