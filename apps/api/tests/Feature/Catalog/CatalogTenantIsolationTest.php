<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Enums\Vertical;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\CompositeItemVariant;
use App\Modules\Catalog\Domain\Entities\Modifier;
use App\Modules\Catalog\Domain\Entities\ModifierGroup;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
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

    private CompositeItem $compositeItemB;

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
            'vertical_type' => 'generic',
        ]);
        $this->compositeItemB = CompositeItem::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'code' => 'CI-B',
            'name' => 'Composite B',
            'base_price' => 5.00,
            'vertical_type' => 'generic',
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

    // ──────────────────────────────────────────────────────────────────
    // Recipe-line dynamic component_id (Codex round-1 Finding 1) —
    // both branches (product, composite_item) must reject cross-tenant ids.
    // ──────────────────────────────────────────────────────────────────

    public function test_create_recipe_line_rejects_cross_tenant_product_component_id(): void
    {
        /** @var Recipe $recipeA */
        $recipeA = Recipe::create([
            'composite_item_id' => $this->compositeItemA->id,
            'version' => 1,
            'is_active' => false,
            'yield_quantity' => 1,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/recipes/{$recipeA->id}/lines", [
                'component_type' => 'product',
                'component_id' => $this->productB->id,
                'quantity' => 1.0,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('component_id', $cross->json('error.errors') ?? []);
    }

    public function test_create_recipe_line_rejects_cross_tenant_composite_item_component_id(): void
    {
        /** @var Recipe $recipeA */
        $recipeA = Recipe::create([
            'composite_item_id' => $this->compositeItemA->id,
            'version' => 1,
            'is_active' => false,
            'yield_quantity' => 1,
        ]);
        /** @var CompositeItem $foreignComposite */
        $foreignComposite = CompositeItem::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'code' => 'CI-B-RL',
            'name' => 'Foreign Composite',
            'base_price' => 1.00,
            'vertical_type' => 'generic',
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/recipes/{$recipeA->id}/lines", [
                'component_type' => 'composite_item',
                'component_id' => $foreignComposite->id,
                'quantity' => 1.0,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('component_id', $cross->json('error.errors') ?? []);
    }

    public function test_update_recipe_line_rejects_cross_tenant_component_id(): void
    {
        /** @var Recipe $recipeA */
        $recipeA = Recipe::create([
            'composite_item_id' => $this->compositeItemA->id,
            'version' => 1,
            'is_active' => false,
            'yield_quantity' => 1,
        ]);
        /** @var Product $sameTenantProduct */
        $sameTenantProduct = Product::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Product A2',
            'sku' => 'SKU-A2-RL',
            'type' => 'part',
            'is_active' => true,
        ]);
        /** @var RecipeLine $line */
        $line = RecipeLine::create([
            'recipe_id' => $recipeA->id,
            'component_type' => 'product',
            'component_id' => $sameTenantProduct->id,
            'quantity' => 1.0,
            'is_optional' => false,
            'is_scalable' => true,
            'wastage_percent' => 0,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/recipes/{$recipeA->id}/lines/{$line->id}", [
                'component_type' => 'product',
                'component_id' => $this->productB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('component_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // Product create / update default_tax_configuration_id validators
    // (api.unmapped.011 / 012 — reassigned to api.catalog).
    //
    // tax_configurations is a country-scoped global reference table: no
    // tenant_id / company_id columns; rows are partitioned by country_code
    // and shared across every tenant in a country. The bare
    // `exists:tax_configurations,id` validator is therefore a scanner
    // false-positive (no cross-tenant exfiltration is structurally
    // possible). The CreateProductRequest / UpdateProductRequest
    // annotations document this; the tests below pin the same-tenant
    // control flow and the rejection of a non-existent UUID — the
    // cross-tenant pattern is not testable because it doesn't exist.
    //
    // See docs/superpowers/audits/2026-05-04-scanner-tax-configurations-false-positive.md
    // and the api.catalog.002 / 005 precedent
    // (CompositeItem variant) closed via the same annotation.
    // ──────────────────────────────────────────────────────────────────

    public function test_create_product_accepts_real_tax_configuration_id_country_scoped(): void
    {
        // Seed a country-scoped tax configuration (no tenant_id / company_id).
        $this->seedFranceCountryRow();
        $taxConfigId = (string) Str::uuid();
        DB::table('tax_configurations')->insert([
            'id' => $taxConfigId,
            'country_code' => 'FR',
            'tax_type' => 'PERCENTAGE',
            'name' => 'TVA Standard FR',
            'percentage_rate' => 20.00,
            'applies_to' => 'LINE_ITEMS',
            'is_default' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/products', [
                'name' => 'Tax-Wired Product',
                'sku' => 'SKU-TAX-A',
                'type' => 'part',
                'default_tax_configuration_id' => $taxConfigId,
                'is_active' => true,
            ]);
        $response->assertStatus(201);
    }

    public function test_create_product_rejects_nonexistent_tax_configuration_id(): void
    {
        // The bare exists validator must still reject non-existent UUIDs
        // (defense against typos / random ids). Cross-tenant rejection
        // is N/A for a country-scoped table.
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/products', [
                'name' => 'Bad-Tax Product',
                'sku' => 'SKU-TAX-BAD',
                'type' => 'part',
                'default_tax_configuration_id' => (string) Str::uuid(),
                'is_active' => true,
            ]);
        $response->assertStatus(422);
        $this->assertArrayHasKey('default_tax_configuration_id', $response->json('error.errors') ?? []);
    }

    public function test_update_product_accepts_real_tax_configuration_id_country_scoped(): void
    {
        $this->seedFranceCountryRow();
        $taxConfigId = (string) Str::uuid();
        DB::table('tax_configurations')->insert([
            'id' => $taxConfigId,
            'country_code' => 'FR',
            'tax_type' => 'PERCENTAGE',
            'name' => 'TVA Update FR',
            'percentage_rate' => 10.00,
            'applies_to' => 'LINE_ITEMS',
            'is_default' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/products/{$this->productA->id}", [
                'default_tax_configuration_id' => $taxConfigId,
            ]);
        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────────────────────────
    // CategoryController parent-validation post-validator find chains
    // (api.unmapped.018 / 019 — reassigned to api.catalog).
    //
    // The pre-fix scanner flagged the `Category::where('company_id', $companyId)
    // ->find($validated['parent_id'])` chain as `unscoped_eloquent_find`
    // because the AST visitor's `chainIsScoped` walk treats `Category::where`
    // (a StaticCall) terminally and only checks SCOPE_METHODS. The
    // production chain *is* company-scoped (categories has no tenant_id),
    // but the scanner cannot see that. The forward fix prepends `::query()`
    // to the chain so the scanner recognises the where('company_id') link.
    //
    // Behaviour tests for cross-tenant parent_id at the validator tier
    // already exist (api.catalog.014 / 015 above). The structural tests
    // below pin the SQL shape of the post-validator parent lookup so a
    // future regression that drops the company_id predicate would fail
    // a CI invariant rather than only a behavioural assertion.
    // ──────────────────────────────────────────────────────────────────

    public function test_create_category_parent_lookup_query_includes_company_predicate(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/categories', [
                'name' => 'Child of A',
                'parent_id' => $this->categoryA->id,
            ])
            ->assertStatus(201);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Two categories queries fire: the validator's exists check and
        // the controller's parent-verification find. The find query is
        // distinct because it does NOT include `count(*)` and does NOT
        // include `exists`; it's a SELECT * limit 1.
        $parentLookupQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "categories"')
                && str_contains($sql, '"id" =')
                && str_contains($sql, 'select * from')
                && ! str_contains($sql, 'count(*)')
            ) {
                $parentLookupQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $parentLookupQuery,
            'CategoryController::store parent lookup query must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"company_id"',
            $parentLookupQuery,
            'CategoryController::store parent lookup must filter by company_id. Got SQL: '.$parentLookupQuery,
        );
    }

    public function test_update_category_parent_lookup_query_includes_company_predicate(): void
    {
        // Seed a sibling same-tenant category so the parent_id validator
        // passes and the controller reaches the post-validator find chain.
        /** @var Category $sibling */
        $sibling = Category::create([
            'company_id' => $this->companyA->id,
            'name' => 'Sibling A',
            'slug' => 'sibling-a',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->putJson("/api/v1/categories/{$this->categoryA->id}", [
                'name' => 'Updated A',
                'parent_id' => $sibling->id,
            ])
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Round-2 tightening (Codex Finding 2 NON-BLOCKING): the previous
        // matcher captured the LAST `from "categories" where "company_id" = ?
        // and "categories"."id" = ?` query, which let a regression that drops
        // company_id from the explicit parent-verify still pass — the primary
        // load (line 164-166) would still match.
        //
        // New invariant: count the post-fix shape — `from "categories" ...
        // "company_id" = ? ... "categories"."id" = ? ... select * ... limit 1`
        // (ignoring count(*) and the unscoped `parent` BelongsTo lazy-load
        // fired by Category::booted()::updated → updatePath, which emits
        // `where "categories"."id" = ?` with NO company_id and is a separate
        // domain-tier concern tracked outside this cluster).
        //
        // CategoryController::update emits TWO of those scoped lookups:
        //   1. Primary load (line 164-166): findOrFail($id) on a query already
        //      scoped to company_id.
        //   2. Parent-verify (line 186-188): find($parent_id) on the same
        //      scoped query.
        // Pre-fix parent-verify was `Category::where('id', $parent_id)
        // ->where('company_id', $companyId)->first()` — emitted `where "id" = ?
        // and "company_id" = ?` (column "id", NOT table-qualified). A future
        // regression that drops `where('company_id', ...)` from this chain
        // would emit `where "id" = ?` only and the count below would drop to
        // 1, failing the invariant.
        $scopedCategoryLookups = [];
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "categories"')
                && str_contains($sql, '"company_id" = ?')
                && str_contains($sql, '"categories"."id" = ?')
                && str_contains($sql, 'select * from')
                && str_contains($sql, 'limit 1')
                && ! str_contains($sql, 'count(*)')
            ) {
                $scopedCategoryLookups[] = $sql;
            }
        }

        $this->assertGreaterThanOrEqual(
            2,
            count($scopedCategoryLookups),
            'CategoryController::update must emit at least 2 scoped single-row category SELECTs '
                .'(primary load + parent-verify, both scoped by company_id with table-qualified id). '
                .'A regression that drops company_id from the explicit parent-verify lookup would '
                .'leave only the primary load matching. Captured: '
                .json_encode($scopedCategoryLookups)
                .' Full log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // ProductController + EnrichmentReviewController bare-where reads
    // (Codex round-1 Finding 1 BLOCKING — scanner StaticCall blind spot).
    //
    // Pre-fix shape:
    //   Product::where('company_id', $companyId)->where('id', $product)->first()
    //   EnrichmentResult::where('company_id', $companyId)->where('id', $id)->first()
    //
    // The static-call form is invisible to the chained MethodCall AST visitor
    // and `products` / `enrichment_results` both carry tenant_id + company_id.
    // Round-2 fix prepends `::query()` and adds `where('tenant_id', ...)` so
    // the scanner sees both predicates and a same-tenant cross-company is
    // structurally blocked even before the company_id filter.
    // ──────────────────────────────────────────────────────────────────

    public function test_show_product_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/products/{$this->productB->id}");
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/products/{$this->productA->id}");
        $same->assertStatus(200);
    }

    public function test_update_product_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/products/{$this->productB->id}", [
                'name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');

        $this->assertSame(
            'Product B',
            $this->productB->fresh()?->name,
            'Cross-tenant update must NOT have mutated the foreign product.',
        );
    }

    public function test_destroy_product_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/products/{$this->productB->id}");
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');

        $this->assertNotNull(
            $this->productB->fresh(),
            'Cross-tenant product must NOT have been soft-deleted.',
        );
    }

    public function test_stock_levels_rejects_cross_tenant_product_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/products/{$this->productB->id}/stock-levels");
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');
    }

    public function test_show_product_query_includes_tenant_and_company_predicates(): void
    {
        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/products/{$this->productA->id}")
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $productLookup = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "products"')
                && str_contains($sql, '"id" = ?')
                && str_contains($sql, 'limit 1')
                && ! str_contains($sql, 'count(*)')
                && ! str_contains($sql, 'inner join')
            ) {
                $productLookup = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $productLookup,
            'ProductController::show product lookup must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $productLookup,
            'ProductController::show must filter by tenant_id. Got SQL: '.$productLookup,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $productLookup,
            'ProductController::show must filter by company_id. Got SQL: '.$productLookup,
        );
    }

    public function test_show_enrichment_result_rejects_cross_tenant_id(): void
    {
        $resultB = $this->seedEnrichmentResultForTenantB();

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/enrichment-results/{$resultB->id}");
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'ENRICHMENT_RESULT_NOT_FOUND');
    }

    public function test_accept_enrichment_result_rejects_cross_tenant_id(): void
    {
        $resultB = $this->seedEnrichmentResultForTenantB();

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/enrichment-results/{$resultB->id}/accept", [
                'accepted_fields' => ['name'],
            ]);
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'ENRICHMENT_RESULT_NOT_FOUND');

        $this->assertSame(
            EnrichmentReviewStatus::PendingReview,
            $resultB->fresh()?->status,
            'Cross-tenant accept must NOT have transitioned the foreign enrichment result.',
        );
    }

    public function test_reject_enrichment_result_rejects_cross_tenant_id(): void
    {
        $resultB = $this->seedEnrichmentResultForTenantB();

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/enrichment-results/{$resultB->id}/reject", [
                'reason' => 'cross-tenant attempt',
            ]);
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'ENRICHMENT_RESULT_NOT_FOUND');

        $this->assertSame(
            EnrichmentReviewStatus::PendingReview,
            $resultB->fresh()?->status,
            'Cross-tenant reject must NOT have transitioned the foreign enrichment result.',
        );
    }

    public function test_show_enrichment_result_query_includes_tenant_and_company_predicates(): void
    {
        $resultA = $this->seedEnrichmentResultForTenantA();

        DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/enrichment-results/{$resultA->id}")
            ->assertStatus(200);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $enrichmentLookup = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "enrichment_results"')
                && str_contains($sql, '"id" = ?')
                && str_contains($sql, 'limit 1')
                && ! str_contains($sql, 'count(*)')
                && ! str_contains($sql, 'inner join')
            ) {
                $enrichmentLookup = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $enrichmentLookup,
            'EnrichmentReviewController::show enrichment_results lookup must be captured. Log: '
                .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $enrichmentLookup,
            'EnrichmentReviewController::show must filter by tenant_id. Got SQL: '.$enrichmentLookup,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $enrichmentLookup,
            'EnrichmentReviewController::show must filter by company_id. Got SQL: '.$enrichmentLookup,
        );
    }

    private function seedEnrichmentResultForTenantA(): EnrichmentResult
    {
        return EnrichmentResult::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'tracking_id' => Str::uuid()->toString(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->buildEnrichedProductData('Product A enriched'),
            'enrichment_quality' => 'good',
        ]);
    }

    private function seedEnrichmentResultForTenantB(): EnrichmentResult
    {
        return EnrichmentResult::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'product_id' => $this->productB->id,
            'tracking_id' => Str::uuid()->toString(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => $this->buildEnrichedProductData('Product B enriched'),
            'enrichment_quality' => 'good',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEnrichedProductData(string $name): array
    {
        return [
            'name' => $name,
            'brand' => null,
            'description' => null,
            'classification' => [],
            'ingredients' => [],
            'images' => [],
            'confidence_score' => 75,
            'enrichment_tier' => null,
            'field_confidence' => null,
            'enrichment_sources' => null,
            'assigned_barcode' => null,
            'assigned_barcode_type' => null,
        ];
    }

    /**
     * Seed FR country row (tax_configurations FK requires it).
     */
    private function seedFranceCountryRow(): void
    {
        if (DB::table('countries')->where('code', 'FR')->exists()) {
            return;
        }
        DB::table('countries')->insert([
            'code' => 'FR',
            'name' => 'France',
            'currency_code' => 'EUR',
            'default_locale' => 'fr_FR',
            'is_active' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.018 — CompositeItemController route-anchored lookups
    // ──────────────────────────────────────────────────────────────────

    public function test_show_composite_item_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/composite-items/{$this->compositeItemB->id}");
        $cross->assertStatus(404);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/composite-items/{$this->compositeItemA->id}");
        $same->assertStatus(200);
    }

    public function test_update_composite_item_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/composite-items/{$this->compositeItemB->id}", [
                'name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);

        $this->assertSame(
            'Composite B',
            $this->compositeItemB->fresh()?->name,
            'Cross-tenant update must NOT mutate the foreign composite item.',
        );
    }

    public function test_destroy_composite_item_rejects_cross_tenant_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/composite-items/{$this->compositeItemB->id}");
        $cross->assertStatus(404);

        $this->assertNotNull(
            $this->compositeItemB->fresh(),
            'Cross-tenant composite item must NOT be deleted.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.019 — RecipeController: CompositeItem + Recipe reads
    // ──────────────────────────────────────────────────────────────────

    public function test_show_recipe_rejects_cross_tenant_via_composite_item(): void
    {
        /** @var Recipe $recipeB */
        $recipeB = Recipe::create([
            'composite_item_id' => $this->compositeItemB->id,
            'version' => 1,
            'is_active' => true,
            'yield_quantity' => 1,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/recipes/{$recipeB->id}");
        $cross->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.020 — RecipeLineController::store recipe lookup
    // ──────────────────────────────────────────────────────────────────

    public function test_create_recipe_line_rejects_cross_tenant_recipe_id(): void
    {
        /** @var Recipe $recipeB */
        $recipeB = Recipe::create([
            'composite_item_id' => $this->compositeItemB->id,
            'version' => 1,
            'is_active' => true,
            'yield_quantity' => 1,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/recipes/{$recipeB->id}/lines", [
                'component_type' => 'product',
                'component_id' => $this->productA->id,
                'quantity' => 1.0,
            ]);
        // Cross-tenant recipe ownership check must yield 404 (not 403).
        $cross->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.021 — CompositeItemVariantController
    // ──────────────────────────────────────────────────────────────────

    public function test_show_composite_item_variant_rejects_cross_tenant_via_composite_item(): void
    {
        /** @var CompositeItemVariant $variantB */
        $variantB = CompositeItemVariant::create([
            'composite_item_id' => $this->compositeItemB->id,
            'code' => 'VAR-B',
            'name' => 'Variant B',
            'price_adjustment_type' => 'absolute',
            'price_adjustment' => 0,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/composite-item-variants/{$variantB->id}", [
                'name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);

        $this->assertSame(
            'Variant B',
            $variantB->fresh()?->name,
            'Cross-tenant update must NOT mutate the foreign variant.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.022 — ScopedExists::tenantOrSystem for units table
    // ──────────────────────────────────────────────────────────────────

    public function test_units_validator_accepts_system_units(): void
    {
        // System rows have tenant_id = NULL.
        $unitCategoryId = $this->seedUnitCategory();
        $systemUnitId = (string) Str::uuid();
        DB::table('units')->insert([
            'id' => $systemUnitId,
            'tenant_id' => null,
            'category_id' => $unitCategoryId,
            'code' => 'kg-sys',
            'name' => 'Kilogram (system)',
            'symbol' => 'kg',
            'conversion_factor' => 1000,
            'decimal_places' => 2,
            'is_base_unit' => false,
            'is_system' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var Recipe $recipeA */
        $recipeA = Recipe::create([
            'composite_item_id' => $this->compositeItemA->id,
            'version' => 1,
            'is_active' => true,
            'yield_quantity' => 1,
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/recipes/{$recipeA->id}/lines", [
                'component_type' => 'product',
                'component_id' => $this->productA->id,
                'quantity' => 1.0,
                'unit_id' => $systemUnitId,
            ]);
        $response->assertStatus(201);
    }

    public function test_units_validator_accepts_caller_tenant_units(): void
    {
        $unitCategoryId = $this->seedUnitCategory();
        $tenantUnitId = (string) Str::uuid();
        DB::table('units')->insert([
            'id' => $tenantUnitId,
            'tenant_id' => $this->tenantA->id,
            'category_id' => $unitCategoryId,
            'code' => 'litre-a',
            'name' => 'Litre (Tenant A)',
            'symbol' => 'L',
            'conversion_factor' => 1,
            'decimal_places' => 2,
            'is_base_unit' => false,
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var Recipe $recipeA */
        $recipeA = Recipe::create([
            'composite_item_id' => $this->compositeItemA->id,
            'version' => 2,
            'is_active' => false,
            'yield_quantity' => 1,
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/recipes/{$recipeA->id}/lines", [
                'component_type' => 'product',
                'component_id' => $this->productA->id,
                'quantity' => 1.0,
                'unit_id' => $tenantUnitId,
            ]);
        $response->assertStatus(201);
    }

    public function test_units_validator_rejects_other_tenant_units(): void
    {
        $unitCategoryId = $this->seedUnitCategory();
        $foreignUnitId = (string) Str::uuid();
        DB::table('units')->insert([
            'id' => $foreignUnitId,
            'tenant_id' => $this->tenantB->id,
            'category_id' => $unitCategoryId,
            'code' => 'box-b',
            'name' => 'Box (Tenant B)',
            'symbol' => 'box',
            'conversion_factor' => 1,
            'decimal_places' => 0,
            'is_base_unit' => false,
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var Recipe $recipeA */
        $recipeA = Recipe::create([
            'composite_item_id' => $this->compositeItemA->id,
            'version' => 3,
            'is_active' => false,
            'yield_quantity' => 1,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/recipes/{$recipeA->id}/lines", [
                'component_type' => 'product',
                'component_id' => $this->productA->id,
                'quantity' => 1.0,
                'unit_id' => $foreignUnitId,
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('unit_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.023 — Country_code coherence on default_tax_configuration_id
    // ──────────────────────────────────────────────────────────────────

    public function test_create_composite_item_rejects_foreign_country_tax_configuration(): void
    {
        $this->seedFranceCountryRow();
        $this->seedTunisiaCountryRow();
        $tnTaxId = (string) Str::uuid();
        DB::table('tax_configurations')->insert([
            'id' => $tnTaxId,
            'country_code' => 'TN',
            'tax_type' => 'PERCENTAGE',
            'name' => 'TVA Tunisie',
            'percentage_rate' => 19.00,
            'applies_to' => 'LINE_ITEMS',
            'is_default' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // companyA is FR; TN tax_configuration should be rejected.
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/composite-items', [
                'code' => 'CI-TAX-TN',
                'name' => 'Composite TN Tax',
                'base_price' => 1.00,
                'default_tax_configuration_id' => $tnTaxId,
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('default_tax_configuration_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.024 — ModifierController component_type Enum validation
    // ──────────────────────────────────────────────────────────────────

    public function test_modifier_update_rejects_invalid_component_type_enum_value(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/modifiers/{$this->modifierA->id}", [
                'component_type' => 'invalid_type',
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('component_type', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.025 — CompositeItemController::checkAvailability location_id scope
    // ──────────────────────────────────────────────────────────────────

    public function test_check_availability_rejects_cross_company_location_id(): void
    {
        // Seed a location belonging to companyB.
        $locationBId = (string) Str::uuid();
        DB::table('locations')->insert([
            'id' => $locationBId,
            'company_id' => $this->companyB->id,
            'name' => 'Location B',
            'type' => 'shop',
            'is_default' => false,
            'is_active' => true,
            'pos_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/composite-items/{$this->compositeItemA->id}/availability?location_id={$locationBId}");
        $cross->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.026 — NoCircularCompositeItemReference cross-tenant safety
    // ──────────────────────────────────────────────────────────────────

    public function test_no_circular_composite_item_rule_silently_ignores_cross_tenant_id(): void
    {
        // A cross-tenant compositeItemId passed as component_id must NOT
        // surface cross-tenant data — the rule must treat it as "no cycle".
        /** @var Recipe $recipeA */
        $recipeA = Recipe::create([
            'composite_item_id' => $this->compositeItemA->id,
            'version' => 1,
            'is_active' => false,
            'yield_quantity' => 1,
        ]);

        // compositeItemB belongs to tenantB. The component_id validator
        // (ScopedExists) must reject it before the circular rule fires.
        // So we test that the REQUEST itself rejects cross-tenant, not that the rule fires.
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/recipes/{$recipeA->id}/lines", [
                'component_type' => 'composite_item',
                'component_id' => $this->compositeItemB->id,
                'quantity' => 1.0,
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('component_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────

    private function seedUnitCategory(): string
    {
        $existing = DB::table('unit_categories')->where('code', 'mass-iso-test')->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();
        DB::table('unit_categories')->insert([
            'id' => $id,
            'code' => 'mass-iso-test',
            'name' => 'Mass (isolation test)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedTunisiaCountryRow(): void
    {
        if (DB::table('countries')->where('code', 'TN')->exists()) {
            return;
        }
        DB::table('countries')->insert([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'default_locale' => 'ar_TN',
            'is_active' => true,
        ]);
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
