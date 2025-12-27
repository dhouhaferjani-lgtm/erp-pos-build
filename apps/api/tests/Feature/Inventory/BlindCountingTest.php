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
use App\Modules\Inventory\Domain\Enums\AssignmentStatus;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingAssignment;
use App\Modules\Inventory\Domain\InventoryCountingItem;
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

/**
 * CRITICAL TESTS: Verify blind counting methodology.
 *
 * These tests ensure that counters NEVER see theoretical_qty values
 * through any API endpoint, preventing confirmation bias.
 */
class BlindCountingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $counterUser;

    private Location $warehouse;

    private Product $product;

    private InventoryCounting $counting;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant and company
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
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

        // Create admin user with full permissions
        $this->adminUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
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

        // Create counter user with limited permissions
        $this->counterUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Counter User',
            'email' => 'counter@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->counterUser->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $this->counterUser->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Create warehouse
        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        // Create product with stock
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        // Receive stock to establish theoretical quantity
        $service = app(StockAdjustmentService::class);
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->adminUser->id,
        );

        // Create a counting session with counter assigned
        $this->counting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'status' => CountingStatus::Count1InProgress,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'created_by_user_id' => $this->adminUser->id,
            'count_1_user_id' => $this->counterUser->id, // Set counter on counting itself
        ]);

        // Create counting item with theoretical quantity
        InventoryCountingItem::create([
            'counting_id' => $this->counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'theoretical_qty' => '100.0000',
        ]);

        // Create assignment record
        InventoryCountingAssignment::create([
            'counting_id' => $this->counting->id,
            'user_id' => $this->counterUser->id,
            'count_number' => 1,
            'status' => AssignmentStatus::Pending,
            'assigned_at' => now(),
        ]);
    }

    /**
     * CRITICAL TEST: Counter view must NEVER include theoretical_qty.
     */
    public function test_counter_view_excludes_theoretical_qty(): void
    {
        $response = $this->actingAs($this->counterUser)
            ->getJson("/api/v1/inventory/countings/{$this->counting->id}/counter-view");

        $response->assertStatus(200);

        // The response MUST NOT contain theoretical_qty
        $response->assertJsonMissingPath('data.theoretical_qty');

        // Also verify the JSON doesn't contain the string at all
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('theoretical_qty', $content);
        $this->assertStringNotContainsString('theoretical', $content);
    }

    /**
     * CRITICAL TEST: Items to count endpoint must NEVER include theoretical_qty.
     */
    public function test_items_to_count_excludes_theoretical_qty(): void
    {
        $response = $this->actingAs($this->counterUser)
            ->getJson("/api/v1/inventory/countings/{$this->counting->id}/items/to-count");

        $response->assertStatus(200);

        // Check each item doesn't have theoretical_qty
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('theoretical_qty', $content);
        $this->assertStringNotContainsString('theoretical', $content);
    }

    /**
     * CRITICAL TEST: Barcode lookup must NEVER reveal theoretical_qty.
     */
    public function test_barcode_lookup_excludes_theoretical_qty(): void
    {
        // Update product with barcode
        $this->product->update(['barcode' => '1234567890123']);

        $response = $this->actingAs($this->counterUser)
            ->getJson("/api/v1/inventory/countings/{$this->counting->id}/lookup?barcode=1234567890123");

        $response->assertStatus(200);

        // The response MUST NOT contain theoretical_qty
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('theoretical_qty', $content);
        $this->assertStringNotContainsString('theoretical', $content);
    }

    /**
     * CRITICAL TEST: My tasks endpoint must NEVER include theoretical_qty.
     */
    public function test_my_tasks_excludes_theoretical_qty(): void
    {
        $response = $this->actingAs($this->counterUser)
            ->getJson('/api/v1/inventory/countings/my-tasks');

        $response->assertStatus(200);

        // The response MUST NOT contain theoretical_qty anywhere
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('theoretical_qty', $content);
        $this->assertStringNotContainsString('theoretical', $content);
    }

    /**
     * Test that admin CAN see theoretical_qty in reconciliation view.
     */
    public function test_admin_can_see_theoretical_qty_in_reconciliation(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/inventory/countings/{$this->counting->id}/reconciliation");

        $response->assertStatus(200);

        // Admin reconciliation view SHOULD include theoretical_qty for comparison
        $response->assertJsonStructure([
            'data' => [
                'items' => [
                    '*' => ['theoretical_qty'],
                ],
            ],
        ]);
    }

    /**
     * Test counter can submit count without seeing theoretical quantity.
     */
    public function test_counter_can_submit_count_blindly(): void
    {
        $item = InventoryCountingItem::where('counting_id', $this->counting->id)->firstOrFail();

        // Counter submits their count (they don't know theoretical is 100)
        $response = $this->actingAs($this->counterUser)
            ->postJson("/api/v1/inventory/countings/{$this->counting->id}/items/{$item->id}/count", [
                'quantity' => 98, // Different from theoretical (100)
                'notes' => 'Counted carefully',
            ]);

        $response->assertStatus(200);

        // Verify the count was recorded
        $item->refresh();
        $this->assertEquals('98.0000', $item->count_1_qty);
    }

    /**
     * Test that only the assigned counter can submit counts.
     */
    public function test_only_assigned_counter_can_submit_count(): void
    {
        $item = InventoryCountingItem::where('counting_id', $this->counting->id)->firstOrFail();

        // Admin (not assigned as counter) tries to submit a count
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/inventory/countings/{$this->counting->id}/items/{$item->id}/count", [
                'quantity' => 100,
            ]);

        // Should fail because admin is not assigned to count
        $response->assertStatus(403);
    }

    /**
     * Test counter cannot access counting they're not assigned to.
     */
    public function test_counter_cannot_access_unassigned_counting(): void
    {
        // Create another counting without assigning counterUser
        $otherCounting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'status' => CountingStatus::Count1InProgress,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'created_by_user_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->counterUser)
            ->getJson("/api/v1/inventory/countings/{$otherCounting->id}/counter-view");

        $response->assertStatus(403);
    }

    /**
     * Test that previous counts are hidden during parallel counting.
     */
    public function test_previous_counts_hidden_in_parallel_mode(): void
    {
        // Create second counter
        $secondCounter = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Counter',
            'email' => 'counter2@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $secondCounter->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $secondCounter->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        // Create a counting with parallel mode and 2 counters
        $parallelCounting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'status' => CountingStatus::Count2InProgress, // Counter 2's turn
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
            'execution_mode' => CountingExecutionMode::Parallel,
            'requires_count_2' => true,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'created_by_user_id' => $this->adminUser->id,
            'count_1_user_id' => $this->adminUser->id,
            'count_2_user_id' => $secondCounter->id,
        ]);

        // Create item with count 1 already submitted
        $item = InventoryCountingItem::create([
            'counting_id' => $parallelCounting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'theoretical_qty' => '100.0000',
            'count_1_qty' => '95.0000', // Counter 1 already submitted
            'count_1_at' => now(),
        ]);

        // Create assignment for counter 2
        InventoryCountingAssignment::create([
            'counting_id' => $parallelCounting->id,
            'user_id' => $secondCounter->id,
            'count_number' => 2,
            'status' => AssignmentStatus::Pending,
            'assigned_at' => now(),
        ]);

        // Second counter views items to count
        $response = $this->actingAs($secondCounter)
            ->getJson("/api/v1/inventory/countings/{$parallelCounting->id}/items/to-count");

        $response->assertStatus(200);

        // Response MUST NOT contain count_1_qty (would bias counter 2)
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringNotContainsString('count_1_qty', $content);
        $this->assertStringNotContainsString('"95"', $content);
        $this->assertStringNotContainsString('95.0000', $content);
    }
}
