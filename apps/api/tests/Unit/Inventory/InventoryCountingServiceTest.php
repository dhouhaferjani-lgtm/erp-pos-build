<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Domain\Enums\AssignmentStatus;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryCountingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $counterUser;

    private User $counter2User;

    private Location $warehouse;

    /** @var array<int, Product> */
    private array $products = [];

    private InventoryCountingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-svc',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
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
            'name' => 'Admin User',
            'email' => 'admin-svc@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->adminUser->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.receive',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->adminUser->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->counterUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter User',
            'email' => 'counter-svc@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->counterUser->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $this->counterUser->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        $this->counter2User = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter 2 User',
            'email' => 'counter2-svc@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->counter2User->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $this->counter2User->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-SVC',
            'name' => 'Service Test Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $stockService = app(StockAdjustmentService::class);
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'sku' => "PROD-SVC-{$i}",
                'name' => "Test Product {$i}",
                'type' => ProductType::Part,
                'is_active' => true,
            ]);
            $this->products[] = $product;

            $stockService->receive(
                productId: $product->id,
                locationId: $this->warehouse->id,
                quantity: (string) ($i * 10),
                reference: "PO-SVC-{$i}",
                userId: $this->adminUser->id,
            );
        }

        $this->service = app(InventoryCountingService::class);
    }

    // --- generateCountingNumber ---

    public function test_generate_counting_number_format(): void
    {
        $counting = $this->service->create([
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => [$this->products[0]->id]],
            'count_1_user_id' => $this->counterUser->id,
        ], $this->adminUser, $this->company->id);

        $this->assertMatchesRegularExpression('/^CNT-\d{4}-\d{4}$/', $counting->counting_number);
    }

    public function test_generate_counting_number_sequential(): void
    {
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $counting = $this->service->create([
                'scope_type' => CountingScopeType::Product,
                'scope_filters' => ['product_ids' => [$this->products[0]->id]],
                'count_1_user_id' => $this->counterUser->id,
            ], $this->adminUser, $this->company->id);
            $numbers[] = $counting->counting_number;
        }

        $year = now()->year;
        $this->assertEquals("CNT-{$year}-0001", $numbers[0]);
        $this->assertEquals("CNT-{$year}-0002", $numbers[1]);
        $this->assertEquals("CNT-{$year}-0003", $numbers[2]);
    }

    public function test_generate_counting_number_isolated_per_company(): void
    {
        // Create counting for company A
        $this->service->create([
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => [$this->products[0]->id]],
            'count_1_user_id' => $this->counterUser->id,
        ], $this->adminUser, $this->company->id);

        // Create company B
        $companyB = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAXB',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $warehouseB = Location::create([
            'company_id' => $companyB->id,
            'code' => 'WH-B',
            'name' => 'Warehouse B',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyB->id,
            'sku' => 'PROD-B-1',
            'name' => 'Product B',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        $stockService = app(StockAdjustmentService::class);
        $stockService->receive(
            productId: $productB->id,
            locationId: $warehouseB->id,
            quantity: '50',
            reference: 'PO-B',
            userId: $this->adminUser->id,
        );

        app(CompanyContext::class)->setCompanyId($companyB->id);

        $countingB = $this->service->create([
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => [$productB->id]],
            'count_1_user_id' => $this->counterUser->id,
        ], $this->adminUser, $companyB->id);

        $year = now()->year;
        $this->assertEquals("CNT-{$year}-0001", $countingB->counting_number);
    }

    public function test_generate_counting_number_pads_to_four_digits(): void
    {
        $counting = $this->service->create([
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => [$this->products[0]->id]],
            'count_1_user_id' => $this->counterUser->id,
        ], $this->adminUser, $this->company->id);

        $year = now()->year;
        $this->assertEquals("CNT-{$year}-0001", $counting->counting_number);
    }

    public function test_create_sets_counting_number_and_tenant_id(): void
    {
        $counting = $this->service->create([
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => [$this->products[0]->id]],
            'count_1_user_id' => $this->counterUser->id,
        ], $this->adminUser, $this->company->id);

        $this->assertNotNull($counting->counting_number);
        $this->assertEquals($this->adminUser->tenant_id, $counting->tenant_id);
    }

    // --- activateDraft ---

    public function test_activate_draft_transitions_to_count1_in_progress(): void
    {
        $draft = $this->createDraft();

        $result = $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
            activateImmediately: true,
        );

        $this->assertEquals(CountingStatus::Count1InProgress, $result->status);
        $this->assertNotNull($result->activated_at);
    }

    public function test_activate_draft_transitions_to_scheduled(): void
    {
        $draft = $this->createDraft();

        $result = $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
            activateImmediately: false,
        );

        $this->assertEquals(CountingStatus::Scheduled, $result->status);
    }

    public function test_activate_draft_generates_counting_number(): void
    {
        $draft = $this->createDraft();
        $this->assertNull($draft->counting_number);

        $result = $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
        );

        $this->assertMatchesRegularExpression('/^CNT-\d{4}-\d{4}$/', $result->counting_number);
    }

    public function test_activate_draft_preserves_existing_counting_number(): void
    {
        $draft = $this->createDraft();
        $draft->counting_number = 'CNT-2026-9999';
        $draft->save();

        $result = $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
        );

        $this->assertEquals('CNT-2026-9999', $result->counting_number);
    }

    public function test_activate_draft_sets_tenant_id(): void
    {
        $draft = $this->createDraft();
        $this->assertNull($draft->tenant_id);

        $result = $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
        );

        $this->assertEquals($this->adminUser->tenant_id, $result->tenant_id);
    }

    public function test_activate_draft_generates_items_from_scope(): void
    {
        $productIds = array_map(fn (Product $p) => $p->id, $this->products);
        $draft = $this->createDraft(['scope_filters' => ['product_ids' => $productIds]]);

        $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
        );

        $items = $draft->items()->get();
        $this->assertCount(3, $items);

        // Verify theoretical quantities match stock
        foreach ($items as $item) {
            $productIndex = array_search($item->product_id, $productIds, true);
            $this->assertNotFalse($productIndex);
            $expectedQty = ($productIndex + 1) * 10;
            $this->assertEquals(
                number_format($expectedQty, 4, '.', ''),
                $item->theoretical_qty,
            );
        }
    }

    public function test_activate_draft_creates_assignments(): void
    {
        $draft = $this->createDraft([
            'count_1_user_id' => $this->counterUser->id,
            'count_2_user_id' => $this->counter2User->id,
            'requires_count_2' => true,
        ]);

        $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
        );

        $assignments = $draft->assignments()->orderBy('count_number')->get();
        $this->assertCount(2, $assignments);
        $this->assertEquals(1, $assignments[0]->count_number);
        $this->assertEquals((string) $this->counterUser->id, $assignments[0]->user_id);
        $this->assertEquals(2, $assignments[1]->count_number);
        $this->assertEquals((string) $this->counter2User->id, $assignments[1]->user_id);
    }

    public function test_activate_draft_starts_first_assignment_when_immediate(): void
    {
        $draft = $this->createDraft();

        $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
            activateImmediately: true,
        );

        $assignment = $draft->assignments()->where('count_number', 1)->first();
        $this->assertNotNull($assignment);
        $this->assertEquals(AssignmentStatus::InProgress, $assignment->status);
        $this->assertNotNull($assignment->started_at);
    }

    public function test_activate_draft_records_activated_event(): void
    {
        $draft = $this->createDraft();

        $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
        );

        $event = InventoryCountingEvent::where('counting_id', $draft->id)
            ->where('event_type', InventoryCountingEvent::COUNTING_ACTIVATED)
            ->first();

        $this->assertNotNull($event);
        $this->assertArrayHasKey('items_count', $event->event_data);
        $this->assertArrayHasKey('activate_immediately', $event->event_data);
    }

    public function test_activate_draft_rejects_non_draft(): void
    {
        $draft = $this->createDraft();
        $draft->status = CountingStatus::Count1InProgress;
        $draft->activated_at = now();
        $draft->save();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
        );
    }

    public function test_category_scope_generates_correct_items(): void
    {
        $categoryA = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Category A',
            'slug' => 'category-a',
            'path' => 'category-a',
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $categoryB = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Category B',
            'slug' => 'category-b',
            'path' => 'category-b',
            'depth' => 0,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        // Product 0 -> Category A, Product 1 -> Category B, Product 2 -> no category
        $this->products[0]->update(['category_id' => $categoryA->id]);
        $this->products[1]->update(['category_id' => $categoryB->id]);

        $draft = $this->createDraft([
            'scope_type' => CountingScopeType::Category,
            'scope_filters' => ['category_ids' => [$categoryA->id]],
        ]);

        $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
        );

        $items = $draft->items()->get();
        $this->assertCount(1, $items);
        $this->assertEquals($this->products[0]->id, $items->first()->product_id);
    }

    public function test_category_scope_excludes_zero_stock(): void
    {
        $categoryA = Category::create([
            'company_id' => $this->company->id,
            'name' => 'Category A ZS',
            'slug' => 'category-a-zs',
            'path' => 'category-a-zs',
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        // Create a product with zero stock in category A
        $zeroStockProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-ZERO',
            'name' => 'Zero Stock Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'category_id' => $categoryA->id,
        ]);

        // Also assign product 0 to category A (has stock)
        $this->products[0]->update(['category_id' => $categoryA->id]);

        $draft = $this->createDraft([
            'scope_type' => CountingScopeType::Category,
            'scope_filters' => ['category_ids' => [$categoryA->id]],
        ]);

        $this->service->activateDraft(
            $draft,
            $this->company->id,
            $this->adminUser,
        );

        $items = $draft->items()->get();
        // Only the product with stock should be counted
        $this->assertCount(1, $items);
        $this->assertEquals($this->products[0]->id, $items->first()->product_id);
        // Zero stock product must not appear
        $this->assertFalse($items->contains('product_id', $zeroStockProduct->id));
    }

    // --- Helpers ---

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDraft(array $overrides = []): InventoryCounting
    {
        $defaults = [
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->adminUser->id,
            'status' => CountingStatus::Draft,
            'scope_type' => CountingScopeType::Product,
            'scope_filters' => ['product_ids' => [$this->products[0]->id]],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'count_1_user_id' => $this->counterUser->id,
        ];

        return InventoryCounting::create(array_merge($defaults, $overrides));
    }
}
