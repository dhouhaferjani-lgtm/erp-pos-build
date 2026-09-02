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
     * The guard must not narrow `product` (deliberately multi-location) or
     * `location`, both of which still activate with no
     * `scope_filters.location_id`. `zone` is NOT in this list — gate r1
     * IMPORTANT-4 added the same requirement there; see the zone cases below.
     */
    public function test_activate_draft_leaves_product_scope_unaffected(): void
    {
        $productDraft = $this->createDraft($this->counterUser);

        $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$productDraft->id}/activate-draft")
            ->assertStatus(200);
    }

    public function test_activate_draft_leaves_location_scope_unaffected(): void
    {
        // Separate test on purpose: activating both in one test trips the
        // (pre-existing, correct) overlapping-counting guard, since the two
        // scopes cover the same product/location pairs.
        $locationDraft = $this->createDraft($this->counterUser, [
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
        ]);

        $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$locationDraft->id}/activate-draft")
            ->assertStatus(200);

        $this->assertGreaterThan(
            0,
            $locationDraft->fresh()?->items()->count(),
            'A location-scoped activation must still generate its items.',
        );
    }

    /**
     * Gate r1 IMPORTANT-4 — zone carried the identical hole.
     * `InventoryCountingService::zoneItemSeeds` returns [] with no location, so
     * such a draft activated into a live counting with nothing to count.
     */
    public function test_activate_draft_rejects_zone_scope_without_a_location(): void
    {
        $draft = $this->createDraft($this->counterUser, [
            'scope_type' => CountingScopeType::Zone,
            'scope_filters' => ['zone_ids' => ['4b4e0a9c-1f5f-4b60-9a3f-9f0f3f6d2a11']],
        ]);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(422);
        $this->assertSame(
            'A location must be selected before activation',
            $response->json('error'),
        );
        $this->assertSame(CountingStatus::Draft, $draft->fresh()?->status);
        $this->assertSame(0, $draft->items()->count());
    }

    public function test_create_draft_rejects_zone_scope_without_a_location(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/inventory/countings/drafts', [
                'scope_type' => 'zone',
                'scope_filters' => [
                    'zone_ids' => ['4b4e0a9c-1f5f-4b60-9a3f-9f0f3f6d2a11'],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['scope_filters.location_id']);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /**
     * Gate r1 IMPORTANT-4 — activation never asserted the scope resolved to
     * anything. A draft whose location holds no stock for the chosen products
     * became a LIVE counting with zero items, assignments stamped
     * total_items = 0 and a COUNTING_ACTIVATED event recording items_count: 0.
     * The refusal must roll back inside activateDraft's transaction.
     */
    public function test_activate_draft_refuses_a_scope_that_resolves_to_zero_items(): void
    {
        $emptyWarehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-AD-EMPTY',
            'name' => 'AD Empty Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ]);

        $draft = $this->createDraft($this->counterUser, [
            'scope_type' => CountingScopeType::ProductLocation,
            'scope_filters' => [
                'product_ids' => array_map(fn (Product $p) => $p->id, $this->products),
                'location_id' => $emptyWarehouse->id,
            ],
        ]);

        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$draft->id}/activate-draft");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BUSINESS_ERROR');
        $this->assertStringContainsString(
            'Nothing to count in this scope',
            (string) $response->json('error.message'),
        );

        $fresh = $draft->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(CountingStatus::Draft, $fresh->status, 'The transaction must have rolled the status back.');
        $this->assertNull($fresh->counting_number, 'The reserved counting number must have rolled back too.');
        $this->assertSame(0, $draft->items()->count());
        $this->assertSame(0, $draft->assignments()->count());
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

    /**
     * Gate r1 IMPORTANT-2 (RULED): per-ROW refusal, not a whole-batch 422.
     *
     * A single legacy/malformed offline draft must never block the other 49 from
     * syncing — the client would retry the identical payload forever and the
     * operator would see no per-draft explanation. This endpoint advertises an
     * `errors[]` channel; the location check uses it.
     */
    public function test_batch_create_drafts_reports_a_missing_location_per_row_and_keeps_the_rest(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/inventory/countings/drafts/batch', [
                'drafts' => [
                    [
                        'localId' => 'local-bad',
                        'scopeType' => 'product_location',
                        'scopeFilters' => [
                            'product_ids' => [$this->products[0]->id],
                        ],
                    ],
                    [
                        'localId' => 'local-good',
                        'scopeType' => 'product',
                        'scopeFilters' => [
                            'product_ids' => [$this->products[1]->id],
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.errors.0.localId', 'local-bad');
        $response->assertJsonPath('data.errors.0.error', 'A location must be selected for this scope');
        $response->assertJsonPath('data.success.0.localId', 'local-good');

        $countings = InventoryCounting::query()->where('company_id', $this->company->id)->get();
        $this->assertCount(1, $countings, 'Only the offending draft may be dropped.');
        $this->assertSame(
            CountingScopeType::Product,
            $countings->first()?->scope_type,
            'The healthy sibling draft must have persisted.',
        );
    }

    /**
     * Gate r1 IMPORTANT-1: the batch path accepted an unvalidated location id,
     * so a stale/foreign/garbage uuid survived into an activation that generated
     * ZERO items with no error anywhere.
     */
    public function test_batch_create_drafts_reports_a_foreign_location_per_row(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company AD',
            'legal_name' => 'Other Company AD LLC',
            'tax_id' => 'TAXAD2',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $foreignLocation = Location::create([
            'company_id' => $otherCompany->id,
            'code' => 'WH-OTHER',
            'name' => 'Other Company Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/inventory/countings/drafts/batch', [
                'drafts' => [[
                    'localId' => 'local-foreign',
                    'scopeType' => 'product_location',
                    'scopeFilters' => [
                        'product_ids' => [$this->products[0]->id],
                        'location_id' => $foreignLocation->id,
                    ],
                ]],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.errors.0.localId', 'local-foreign');
        $response->assertJsonPath('data.errors.0.error', 'Location not found for the current company');
        $this->assertSame(
            0,
            InventoryCounting::query()->where('company_id', $this->company->id)->count(),
        );
    }

    /**
     * Gate r1 IMPORTANT-5: an unknown scopeType reached the enum-cast attribute
     * and threw a ValueError, which `catch (\Exception)` does not catch — an
     * uncaught 500 that rolled back every already-persisted draft in the batch.
     */
    public function test_batch_create_drafts_rejects_an_unknown_scope_type(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/inventory/countings/drafts/batch', [
                'drafts' => [[
                    'localId' => 'local-1',
                    'scopeType' => 'productLocation',
                    'scopeFilters' => ['product_ids' => [$this->products[0]->id]],
                ]],
            ]);

        $this->assertApiValidationErrors($response, ['drafts.0.scopeType']);
        $this->assertSame(
            0,
            InventoryCounting::query()->where('company_id', $this->company->id)->count(),
            'Nothing may be persisted for an unknown scope type.',
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
     * Writes `now()` exactly as the seven production writers do.
     */
    public function test_my_drafts_emits_last_modified_at_as_iso_8601(): void
    {
        $draft = $this->createDraft($this->counterUser);
        $draft->last_modified_at = now();
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
