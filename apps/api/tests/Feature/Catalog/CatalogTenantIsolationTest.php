<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\Vertical;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.catalog cluster) — tenant-isolation regression coverage.
 *
 * Inventory: 17 callsites split across the Catalog and Product modules.
 *
 *   Validators (bare exists):
 *     api.catalog.001 UpdateCompositeItemRequest:39  categories
 *     api.catalog.002 UpdateCompositeItemRequest:45  tax_configurations  (country-scoped — annotated)
 *     api.catalog.003 StoreModifierRequest:28        products
 *     api.catalog.004 StoreCompositeItemRequest:37   categories
 *     api.catalog.005 StoreCompositeItemRequest:43   tax_configurations  (country-scoped — annotated)
 *     api.catalog.006 ModifierGroupController:138    modifier_groups
 *     api.catalog.007 ModifierController:58          products
 *     api.catalog.014 CategoryController:114         categories
 *     api.catalog.015 CategoryController:160         categories
 *     api.catalog.016 CategoryController:219         categories  (reorder.*.id)
 *     api.catalog.017 CategoryController:221         categories  (reorder.*.parent_id)
 *
 *   Service-tier (unscoped findOrFail):
 *     api.catalog.008 ModifierGroupController:65     ModifierGroup
 *     api.catalog.009 ModifierGroupController:93     ModifierGroup
 *     api.catalog.010 ModifierGroupController:120    ModifierGroup
 *     api.catalog.011 ModifierController:30          ModifierGroup
 *     api.catalog.012 ModifierController:46          Modifier (whereHas via group)
 *     api.catalog.013 ModifierController:77          Modifier (whereHas via group)
 *
 * The 002 / 005 callsites point at tax_configurations, which is a
 * country-scoped global reference table (no tenant_id / company_id columns).
 * Cross-tenant assignment is structurally impossible because the company's
 * country_code blocks foreign rows at the tax-application tier; these are
 * therefore not tested here and instead annotated as
 * structurally_protected_by_country_scoped.
 *
 * categories has only company_id (no tenant_id), so its scoped chains stay
 * single-predicate (ScopedExists::company). modifier_groups and products
 * carry tenant_id + company_id and use the two-predicate template. The
 * modifiers table has no scope columns of its own; the controller now
 * scopes Modifier reads via whereHas('group', ...).
 *
 * The Inventory-module-gated routes (modifier groups, modifiers) require
 * `enabled_extras: ['Inventory']` on each test tenant.
 */
final class CatalogTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private Category $categoryA;

    private Category $categoryB;

    private CompositeItem $compositeItemA;

    private ModifierGroup $modifierGroupA;

    private ModifierGroup $modifierGroupB;

    private Modifier $modifierA;

    private Modifier $modifierB;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-catalog-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-catalog-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::CoffeeShop,
            'enabled_extras' => ['Inventory'],
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-CAT',
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
            'tax_id' => 'TAX-B-CAT',
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
            'email' => 'alice-catalog-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bob',
            'email' => 'bob-catalog-iso@example.com',
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

        $this->categoryA = Category::create([
            'company_id' => $this->companyA->id,
            'name' => 'Category A',
            'slug' => 'cat-a',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $this->categoryB = Category::create([
            'company_id' => $this->companyB->id,
            'name' => 'Category B',
            'slug' => 'cat-b',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->compositeItemA = CompositeItem::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'code' => 'CI-A',
            'name' => 'Composite A',
            'base_price' => 5.00,
        ]);

        $this->modifierGroupA = ModifierGroup::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'code' => 'MG-A',
            'name' => 'Group A',
            'selection_type' => 'single',
        ]);
        $this->modifierGroupB = ModifierGroup::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'code' => 'MG-B',
            'name' => 'Group B',
            'selection_type' => 'single',
        ]);

        $this->modifierA = Modifier::create([
            'modifier_group_id' => $this->modifierGroupA->id,
            'code' => 'MOD-A',
            'name' => 'Modifier A',
            'price_adjustment' => 0,
        ]);
        $this->modifierB = Modifier::create([
            'modifier_group_id' => $this->modifierGroupB->id,
            'code' => 'MOD-B',
            'name' => 'Modifier B',
            'price_adjustment' => 0,
        ]);

        $this->productA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Product A',
            'sku' => 'SKU-A',
            'type' => 'part',
            'is_active' => true,
        ]);
        $this->productB = Product::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Product B',
            'sku' => 'SKU-B',
            'type' => 'part',
            'is_active' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // ModifierGroupController route-anchored lookups (api.catalog.008-010)
    // ──────────────────────────────────────────────────────────────────

    public function test_show_modifier_group_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/modifier-groups/{$this->modifierGroupB->id}");
        $cross->assertStatus(404);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/modifier-groups/{$this->modifierGroupA->id}");
        $same->assertStatus(200);
    }

    public function test_update_modifier_group_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/modifier-groups/{$this->modifierGroupB->id}", [
                'name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);

        $this->assertSame(
            'Group B',
            $this->modifierGroupB->fresh()?->name,
            'Cross-tenant update must NOT have mutated the foreign modifier group.',
        );
    }

    public function test_destroy_modifier_group_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/modifier-groups/{$this->modifierGroupB->id}");
        $cross->assertStatus(404);

        $this->assertNotNull(
            $this->modifierGroupB->fresh(),
            'Cross-tenant modifier group must NOT have been deleted.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // ModifierGroupController::assignToItem validator (api.catalog.006)
    // ──────────────────────────────────────────────────────────────────

    public function test_assign_to_item_rejects_cross_tenant_modifier_group_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/composite-items/{$this->compositeItemA->id}/modifier-groups", [
                'modifier_group_id' => $this->modifierGroupB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('modifier_group_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // ModifierController route-anchored lookups (api.catalog.011-013)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_modifier_rejects_cross_tenant_group_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/modifier-groups/{$this->modifierGroupB->id}/modifiers", [
                'code' => 'NEW-MOD',
                'name' => 'New Modifier',
                'price_adjustment' => 0,
            ]);
        $cross->assertStatus(404);
    }

    public function test_update_modifier_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/modifiers/{$this->modifierB->id}", [
                'name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);

        $this->assertSame(
            'Modifier B',
            $this->modifierB->fresh()?->name,
            'Cross-tenant update must NOT have mutated the foreign modifier.',
        );
    }

    public function test_destroy_modifier_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/modifiers/{$this->modifierB->id}");
        $cross->assertStatus(404);

        $this->assertNotNull(
            $this->modifierB->fresh(),
            'Cross-tenant modifier must NOT have been deleted.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // ModifierController body validators (api.catalog.007)
    // ──────────────────────────────────────────────────────────────────

    public function test_update_modifier_rejects_cross_tenant_component_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/modifiers/{$this->modifierA->id}", [
                'component_id' => $this->productB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('component_id', $cross->json('error.errors') ?? []);
    }

    public function test_update_modifier_accepts_same_tenant_component_id(): void
    {
        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/modifiers/{$this->modifierA->id}", [
                'component_id' => $this->productA->id,
            ]);
        $same->assertStatus(200);
    }

    // ──────────────────────────────────────────────────────────────────
    // StoreModifierRequest body validator (api.catalog.003)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_modifier_rejects_cross_tenant_component_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/modifier-groups/{$this->modifierGroupA->id}/modifiers", [
                'code' => 'CROSS-MOD',
                'name' => 'Cross Modifier',
                'price_adjustment' => 0,
                'component_id' => $this->productB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('component_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // CompositeItem create / update category_id (api.catalog.001 / 004)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_composite_item_rejects_cross_tenant_category_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/composite-items', [
                'code' => 'NEW-CI',
                'name' => 'New Composite',
                'base_price' => 1.00,
                'category_id' => $this->categoryB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('category_id', $cross->json('error.errors') ?? []);
    }

    public function test_update_composite_item_rejects_cross_tenant_category_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/composite-items/{$this->compositeItemA->id}", [
                'category_id' => $this->categoryB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('category_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // CategoryController body validators (api.catalog.014-017)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_category_rejects_cross_tenant_parent_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/categories', [
                'name' => 'New Category',
                'parent_id' => $this->categoryB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('parent_id', $cross->json('error.errors') ?? []);
    }

    public function test_update_category_rejects_cross_tenant_parent_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->putJson("/api/v1/categories/{$this->categoryA->id}", [
                'name' => 'Updated A',
                'parent_id' => $this->categoryB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('parent_id', $cross->json('error.errors') ?? []);
    }

    public function test_reorder_rejects_cross_tenant_category_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/categories/reorder', [
                'categories' => [
                    [
                        'id' => $this->categoryB->id,
                        'sort_order' => 99,
                    ],
                ],
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('categories.0.id', $cross->json('error.errors') ?? []);

        $this->assertSame(
            0,
            $this->categoryB->fresh()?->sort_order,
            'Cross-tenant reorder must NOT mutate the foreign category sort_order.',
        );
    }

    public function test_reorder_rejects_cross_tenant_parent_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/categories/reorder', [
                'categories' => [
                    [
                        'id' => $this->categoryA->id,
                        'sort_order' => 1,
                        'parent_id' => $this->categoryB->id,
                    ],
                ],
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('categories.0.parent_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants — pin SQL shape, not just behavior.
    // ──────────────────────────────────────────────────────────────────

    public function test_show_modifier_group_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/modifier-groups/{$this->modifierGroupA->id}")
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $groupQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "modifier_groups"')
                && str_contains($sql, 'limit 1')
            ) {
                $groupQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $groupQuery,
            'ModifierGroup lookup query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $groupQuery,
            'ModifierGroup route-anchored lookup must filter by tenant_id. Got SQL: '.$groupQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $groupQuery,
            'ModifierGroup route-anchored lookup must also filter by company_id. Got SQL: '.$groupQuery,
        );
    }

    public function test_create_modifier_validator_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/modifier-groups/{$this->modifierGroupA->id}/modifiers", [
                'code' => 'STRUCT-MOD',
                'name' => 'Struct Modifier',
                'price_adjustment' => 0,
                'component_id' => $this->productA->id,
            ])
            ->assertStatus(201);

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
            'StoreModifierRequest component_id validator must filter by tenant_id. Got SQL: '.$productsValidationQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $productsValidationQuery,
            'StoreModifierRequest component_id validator must filter by company_id. Got SQL: '.$productsValidationQuery,
        );
    }

    public function test_update_modifier_query_scopes_via_group_tenant_and_company(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/modifiers/{$this->modifierA->id}", [
                'name' => 'Renamed',
            ])
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Modifier scoping is enforced via whereHas('group', ...) using
        // whereRaw predicates (whereRaw is required because the generic
        // Builder<TRelatedModel> in the closure does not narrow to
        // ModifierGroup at PHPStan level 8 — see also BatchTraceabilityController).
        // The resulting SQL contains a subquery against modifier_groups with
        // unquoted predicates `tenant_id = ?` and `company_id = ?`.
        $modifierQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "modifiers"')
                && str_contains($sql, 'from "modifier_groups"')
                && str_contains($sql, 'limit 1')
            ) {
                $modifierQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $modifierQuery,
            'Modifier whereHas subquery must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            'tenant_id = ?',
            $modifierQuery,
            'Modifier whereHas must filter by group.tenant_id. Got SQL: '.$modifierQuery,
        );
        $this->assertStringContainsString(
            'company_id = ?',
            $modifierQuery,
            'Modifier whereHas must filter by group.company_id. Got SQL: '.$modifierQuery,
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
