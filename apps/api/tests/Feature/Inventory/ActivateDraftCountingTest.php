<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class ActivateDraftCountingTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $counterUser;

    private User $otherUser;

    private Location $warehouse;

    /** @var array<int, Product> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant AD',
            'slug' => 'test-tenant-ad',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company AD',
            'legal_name' => 'Test Company AD LLC',
            'tax_id' => 'TAXAD',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin AD',
            'email' => 'admin-ad@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->assignRole('admin');
        $this->adminUser->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->counterUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter AD',
            'email' => 'counter-ad@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->counterUser->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->counterUser->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        $this->otherUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other AD',
            'email' => 'other-ad@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->otherUser->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->otherUser->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-AD',
            'name' => 'AD Test Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $stockService = app(StockAdjustmentService::class);
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'sku' => "PROD-AD-{$i}",
                'name' => "AD Product {$i}",
                'type' => ProductType::Part,
                'is_active' => true,
            ]);
            $this->products[] = $product;

            $stockService->receive(
                productId: $product->id,
                locationId: $this->warehouse->id,
                quantity: (string) ($i * 10),
                reference: "PO-AD-{$i}",
                userId: $this->adminUser->id,
            );
        }
    }

    public function test_activate_draft_returns_200_with_counting_number(): void
    {
        $draft = $this->createDraft($this->counterUser);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft", [
                'activate_immediately' => true,
            ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.counting_number'));
        $this->assertMatchesRegularExpression(
            '/^CNT-\d{4}-\d{4}$/',
            $response->json('data.counting_number'),
        );
    }

    public function test_activate_draft_rejects_non_draft_status(): void
    {
        $counting = $this->createDraft($this->counterUser);
        $counting->status = CountingStatus::Count1InProgress;
        $counting->activated_at = now();
        $counting->save();

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/activate-draft");

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'Can only activate draft counts']);
    }

    public function test_activate_draft_rejects_empty_product_list(): void
    {
        $draft = $this->createDraft($this->counterUser, [
            'scope_filters' => ['product_ids' => []],
        ]);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'At least one product',
            $response->json('error'),
        );
    }

    public function test_activate_draft_rejects_without_primary_counter(): void
    {
        $draft = $this->createDraft($this->counterUser, [
            'count_1_user_id' => null,
        ]);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Primary counter must be assigned',
            $response->json('error'),
        );
    }

    public function test_activate_draft_rejects_unauthorized_user(): void
    {
        // Draft created by counterUser
        $draft = $this->createDraft($this->counterUser);

        // otherUser (not creator, not admin) tries to activate
        $response = $this->actingAs($this->otherUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(403);
    }

    public function test_activate_draft_allows_admin_to_activate_others_draft(): void
    {
        // Draft created by counterUser
        $draft = $this->createDraft($this->counterUser);

        // Admin activates it
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(200);
    }

    public function test_show_endpoint_includes_counting_number(): void
    {
        $draft = $this->createDraft($this->counterUser);

        // Activate to generate counting number
        $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response = $this->actingAs($this->counterUser)
            ->getJson("/api/v1/inventory/countings/{$draft->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => ['counting_number'],
        ]);
        $this->assertNotNull($response->json('data.counting_number'));
    }

    /**
     * N-1 / A-3 — a `product_location` draft with no `scope_filters.location_id`
     * silently counted EVERY location.
     *
     * `InventoryCountingService::getStockLevelsForScope()` only applies the
     * location filter when `location_id` is set, so a mobile-created
     * product_location draft activated into items across every location that
     * carried stock for the chosen products. Two guards then misfire on such a
     * counting: `CountingBlockService::scopeCoversLocation()` compares
     * `scope_filters.location_id` (a null never matches any location) and the
     * terminal-sync-health gate resolves terminals per scope location — so the
     * counting escapes exactly the location-scoped protections it needs.
     */
    public function test_activate_draft_rejects_product_location_scope_without_a_location(): void
    {
        $draft = $this->createDraft($this->counterUser, [
            'scope_type' => CountingScopeType::ProductLocation,
            'scope_filters' => [
                'product_ids' => array_map(fn (Product $p) => $p->id, $this->products),
            ],
        ]);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'A location must be selected before activation',
            (string) $response->json('error'),
        );

        $this->assertSame(
            CountingStatus::Draft,
            $draft->fresh()?->status,
            'A refused activation must leave the counting in draft.',
        );
        $this->assertSame(
            0,
            $draft->items()->count(),
            'A refused activation must not have generated items.',
        );
    }

    public function test_activate_draft_with_product_location_scope_confines_items_to_that_location(): void
    {
        $secondWarehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-AD-2',
            'name' => 'AD Second Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);

        // Same products also carry stock in the second location: without the
        // guard the activation swept both.
        $stockService = app(StockAdjustmentService::class);
        foreach ($this->products as $index => $product) {
            $stockService->receive(
                productId: $product->id,
                locationId: $secondWarehouse->id,
                quantity: (string) (($index + 1) * 5),
                reference: 'PO-AD-2-'.($index + 1),
                userId: $this->adminUser->id,
            );
        }

        $draft = $this->createDraft($this->counterUser, [
            'scope_type' => CountingScopeType::ProductLocation,
            'scope_filters' => [
                'product_ids' => array_map(fn (Product $p) => $p->id, $this->products),
                'location_id' => $this->warehouse->id,
            ],
        ]);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(200);

        $locationIds = $draft->fresh()?->items()->pluck('location_id')->unique()->values()->all();
        $this->assertSame(
            [$this->warehouse->id],
            $locationIds,
            'Every generated item must sit in the scoped location.',
        );
    }

    /**
     * The guard must not narrow the other scopes: `product` (deliberately
     * multi-location), `location` and `zone` still activate without a
     * `scope_filters.location_id`.
     */
    public function test_activate_draft_leaves_other_scopes_unaffected(): void
    {
        $draft = $this->createDraft($this->counterUser);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(200);
    }

    public function test_create_draft_rejects_product_location_scope_without_a_location(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/inventory/countings/drafts', [
                'scope_type' => 'product_location',
                'scope_filters' => [
                    'product_ids' => [$this->products[0]->id],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['scope_filters.location_id']);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_create_draft_accepts_product_location_scope_with_a_location(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/inventory/countings/drafts', [
                'scope_type' => 'product_location',
                'scope_filters' => [
                    'product_ids' => [$this->products[0]->id],
                    'location_id' => $this->warehouse->id,
                ],
            ]);

        $response->assertStatus(201);

        $counting = InventoryCounting::findOrFail($response->json('data.id'));
        $this->assertSame($this->warehouse->id, $counting->scope_filters['location_id'] ?? null);
    }

    public function test_batch_create_drafts_rejects_product_location_scope_without_a_location(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/inventory/countings/drafts/batch', [
                'drafts' => [[
                    'localId' => 'local-1',
                    'scopeType' => 'product_location',
                    'scopeFilters' => [
                        'product_ids' => [$this->products[0]->id],
                    ],
                ]],
            ]);

        $this->assertApiValidationErrors($response, ['drafts.0.scopeFilters.location_id']);
        $this->assertSame(
            0,
            InventoryCounting::query()->where('company_id', $this->company->id)->count(),
            'A refused batch must not have persisted a draft.',
        );
    }

    public function test_batch_create_drafts_persists_the_scoped_location_verbatim(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/inventory/countings/drafts/batch', [
                'drafts' => [[
                    'localId' => 'local-1',
                    'scopeType' => 'product_location',
                    'scopeFilters' => [
                        'product_ids' => [$this->products[0]->id],
                        'location_id' => $this->warehouse->id,
                    ],
                ]],
            ]);

        $response->assertStatus(201);
        $serverId = $response->json('data.success.0.serverId');
        $this->assertNotNull($serverId);

        $counting = InventoryCounting::findOrFail($serverId);
        $this->assertSame($this->warehouse->id, $counting->scope_filters['location_id'] ?? null);
        $this->assertSame([$this->products[0]->id], $counting->scope_filters['product_ids'] ?? null);
    }

    /**
     * N-1 / A-11 — `my-drafts` emitted `last_modified_at` as the raw
     * `Y-m-d H:i:s+00` string the writers store, while every neighbouring
     * timestamp on the same payload (`created_at`) is ISO-8601. The mobile
     * client parses one shape, so the odd one out is a silent contract break.
     */
    public function test_my_drafts_emits_last_modified_at_as_iso_8601(): void
    {
        $draft = $this->createDraft($this->counterUser);
        $draft->last_modified_at = now()->toDateTimeString();
        $draft->last_modified_by_user_id = $this->counterUser->id;
        $draft->save();

        $response = $this->actingAs($this->counterUser)
            ->getJson('/api/v1/inventory/countings/my-drafts');

        $response->assertStatus(200);

        $lastModifiedAt = $response->json('data.0.last_modified_at');
        $this->assertIsString($lastModifiedAt);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
            $lastModifiedAt,
            'last_modified_at must be ISO-8601, like its created_at neighbour.',
        );
    }

    public function test_my_drafts_emits_null_last_modified_at_when_never_touched(): void
    {
        $this->createDraft($this->counterUser);

        $response = $this->actingAs($this->counterUser)
            ->getJson('/api/v1/inventory/countings/my-drafts');

        $response->assertStatus(200);
        $this->assertNull($response->json('data.0.last_modified_at'));
    }

    // --- Helpers ---

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDraft(User $createdBy, array $overrides = []): InventoryCounting
    {
        $productIds = array_map(fn (Product $p) => $p->id, $this->products);

        $defaults = [
            'company_id' => $this->company->id,
            'created_by_user_id' => $createdBy->id,
            'status' => CountingStatus::Draft,
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => $productIds],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'count_1_user_id' => $this->counterUser->id,
        ];

        return InventoryCounting::create(array_merge($defaults, $overrides));
    }
}
