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
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
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
use Tests\Traits\AssertsApiValidation;

class ReconciliationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $adminUser;

    private User $counter1;

    private User $counter2;

    private User $counter3;

    private Location $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

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

        // Create admin
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

        // Create counters
        $this->counter1 = $this->createCounter('counter1@example.com', 'Counter One');
        $this->counter2 = $this->createCounter('counter2@example.com', 'Counter Two');
        $this->counter3 = $this->createCounter('counter3@example.com', 'Counter Three');

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-001',
            'name' => 'Test Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);

        // Receive stock
        $service = app(StockAdjustmentService::class);
        $service->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: '100.00',
            reference: 'PO-001',
            userId: $this->adminUser->id,
        );
    }

    private function createCounter(string $email, string $name): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $user->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);

        return $user;
    }

    public function test_single_count_matching_theoretical_auto_resolves(): void
    {
        $counting = $this->createCountingSession(false, false);
        $item = $this->createCountingItem($counting, '100.0000');

        $this->assignCounter($counting, $this->counter1, 1);

        // Submit count matching theoretical
        $response = $this->actingAs($this->counter1)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/count", [
                'quantity' => 100,
            ]);

        $response->assertStatus(200);

        $item->refresh();
        $this->assertEquals('100.0000', $item->count_1_qty);
        $this->assertEquals('100.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoAllMatch, $item->resolution_method);
        $this->assertFalse($item->is_flagged);
    }

    public function test_single_count_with_variance_flags_item(): void
    {
        $counting = $this->createCountingSession(false, false);
        $item = $this->createCountingItem($counting, '100.0000');

        $this->assignCounter($counting, $this->counter1, 1);

        // Submit count different from theoretical (5% variance)
        $response = $this->actingAs($this->counter1)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/count", [
                'quantity' => 95,
            ]);

        $response->assertStatus(200);

        $item->refresh();
        $this->assertEquals('95.0000', $item->count_1_qty);
        $this->assertEquals('95.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoCountersAgree, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
    }

    public function test_double_count_both_agree_auto_resolves(): void
    {
        $counting = $this->createCountingSession(true, false);
        $item = $this->createCountingItem($counting, '100.0000');

        $this->assignCounter($counting, $this->counter1, 1);
        $this->assignCounter($counting, $this->counter2, 2);

        // Counter 1 submits
        $this->actingAs($this->counter1)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/count", [
                'quantity' => 98,
            ]);

        // Counter 2 submits same value
        $response = $this->actingAs($this->counter2)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/count", [
                'quantity' => 98,
            ]);

        $response->assertStatus(200);

        $item->refresh();
        $this->assertEquals('98.0000', $item->count_1_qty);
        $this->assertEquals('98.0000', $item->count_2_qty);
        $this->assertEquals('98.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoCountersAgree, $item->resolution_method);
    }

    public function test_double_count_disagreement_flags_for_third_count(): void
    {
        $counting = $this->createCountingSession(true, true);
        $item = $this->createCountingItem($counting, '100.0000');

        $this->assignCounter($counting, $this->counter1, 1);
        $this->assignCounter($counting, $this->counter2, 2);
        $this->assignCounter($counting, $this->counter3, 3);

        // Counter 1: 95
        $this->actingAs($this->counter1)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/count", [
                'quantity' => 95,
            ]);

        // Counter 2: 102 (disagrees)
        $this->actingAs($this->counter2)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/count", [
                'quantity' => 102,
            ]);

        $item->refresh();
        $this->assertNull($item->final_qty);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('counter_disagreement', $item->flag_reason);
    }

    public function test_triple_count_majority_wins(): void
    {
        $counting = $this->createCountingSession(true, true);
        $item = $this->createCountingItem($counting, '100.0000');

        $this->assignCounter($counting, $this->counter1, 1);
        $this->assignCounter($counting, $this->counter2, 2);
        $this->assignCounter($counting, $this->counter3, 3);

        // Manually set counts 1 and 2 as disagreeing
        $item->update([
            'count_1_qty' => '95.0000',
            'count_1_at' => now(),
            'count_2_qty' => '102.0000',
            'count_2_at' => now(),
            'is_flagged' => true,
            'flag_reason' => 'counter_disagreement',
        ]);

        // Move counting to Count3InProgress for counter 3 to submit
        $counting->update(['status' => CountingStatus::Count3InProgress]);

        // Counter 3 submits (agrees with counter 1)
        $response = $this->actingAs($this->counter3)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/count", [
                'quantity' => 95,
            ]);

        $response->assertStatus(200);

        $item->refresh();
        $this->assertEquals('95.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::ThirdCountDecisive, $item->resolution_method);
    }

    public function test_manual_override_requires_notes(): void
    {
        $counting = $this->createCountingSession(false, false);
        $item = $this->createCountingItem($counting, '100.0000');
        $item->update([
            'count_1_qty' => '95.0000',
            'is_flagged' => true,
        ]);

        // Try override without notes
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/inventory/countings/items/{$item->id}/override", [
                'quantity' => 97,
            ]);

        $this->assertApiValidationErrors($response, ['notes']);
    }

    public function test_manual_override_with_notes_succeeds(): void
    {
        $counting = $this->createCountingSession(false, false);
        $item = $this->createCountingItem($counting, '100.0000');
        $item->update([
            'count_1_qty' => '95.0000',
            'is_flagged' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/inventory/countings/items/{$item->id}/override", [
                'quantity' => 97,
                'notes' => 'Verified with physical recount and checked against delivery note',
            ]);

        $response->assertStatus(200);

        $item->refresh();
        $this->assertEquals('97.0000', $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::ManualOverride, $item->resolution_method);
    }

    /**
     * LEDGER C-14(iv) — the typed 422 `COUNTING_TRANSITION_REFUSED` message was
     * hardcoded English inside `CountingTransitionException::__construct()` and
     * rendered verbatim into the response body, so an operator on a French (or
     * Arabic) tenant got an English refusal inside a translated toast wrapper
     * (`counting.messages.overrideFailed` = "Failed to apply override: {{error}}").
     *
     * The operator-facing text now comes from the backend catalogue
     * (`inventory.counting.transition_refused`) with both statuses rendered from
     * `inventory.counting.status.*`, exactly like the house pattern used by
     * `InsufficientStockForFulfilmentException::TRANSLATION_KEY`. `getMessage()`
     * stays English — it is the developer/log string.
     */
    public function test_counting_transition_refusal_message_is_localised(): void
    {
        $counting = $this->createCountingSession(false, false);
        $item = $this->createCountingItem($counting, '100.0000');
        $counting->update(['status' => CountingStatus::Finalized]);

        $response = $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'fr'])
            ->postJson("/api/v1/inventory/countings/items/{$item->id}/override", [
                'quantity' => 97,
                'notes' => 'Verified with physical recount and checked against delivery note',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'COUNTING_TRANSITION_REFUSED');
        $response->assertJsonPath('error.current_status', 'finalized');
        $response->assertJsonPath('error.attempted_status', 'pending_review');

        $message = (string) $response->json('error.message');
        $this->assertStringNotContainsString(
            'This counting is',
            $message,
            'The operator message must come from the fr catalogue, not the hardcoded English exception text. Body: '.$response->getContent(),
        );
        $this->assertStringContainsString(
            (string) trans('inventory.counting.status.finalized', [], 'fr'),
            $message,
            'The refusal must name the current status in the request locale. Body: '.$response->getContent(),
        );
    }

    public function test_reconciliation_view_shows_all_data(): void
    {
        $counting = $this->createCountingSession(true, false);
        $item = $this->createCountingItem($counting, '100.0000');
        $item->update([
            'count_1_qty' => '95.0000',
            'count_1_at' => now(),
            'count_2_qty' => '95.0000',
            'count_2_at' => now(),
            'final_qty' => '95',
            'resolution_method' => ItemResolutionMethod::AutoCountersAgree,
            'is_flagged' => true,
            'flag_reason' => 'variance_from_theoretical',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/inventory/countings/{$counting->id}/reconciliation");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'summary' => [
                    'total',
                    'auto_resolved',
                    'needs_attention',
                    'manually_overridden',
                ],
                'items' => [
                    '*' => [
                        'id',
                        'product',
                        'location',
                        'theoretical_qty',
                        'count_1',
                        'final_qty',
                        'variance',
                        'resolution_method',
                        'is_flagged',
                        'flag_reason',
                    ],
                ],
            ],
        ]);
    }

    public function test_critical_variance_is_flagged_appropriately(): void
    {
        $counting = $this->createCountingSession(false, false);
        $item = $this->createCountingItem($counting, '100.0000');

        $this->assignCounter($counting, $this->counter1, 1);

        // Submit count with 15% variance (critical)
        $this->actingAs($this->counter1)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/items/{$item->id}/count", [
                'quantity' => 85,
            ]);

        $item->refresh();
        $this->assertEquals('critical_variance', $item->flag_reason);
    }

    public function test_trigger_third_count_for_disagreement(): void
    {
        // Create counting session with third count enabled and user assigned
        $counting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'status' => CountingStatus::PendingReview,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => true,
            'requires_count_3' => true,
            'allow_unexpected_items' => false,
            'created_by_user_id' => $this->adminUser->id,
            'count_3_user_id' => $this->counter3->id,
        ]);

        // Create assignment for counter 3
        InventoryCountingAssignment::create([
            'counting_id' => $counting->id,
            'user_id' => $this->counter3->id,
            'count_number' => 3,
            'status' => AssignmentStatus::Pending,
            'assigned_at' => now(),
        ]);

        $item = $this->createCountingItem($counting, '100.0000');
        $item->update([
            'count_1_qty' => '95.0000',
            'count_2_qty' => '105.0000',
            'is_flagged' => true,
            'flag_reason' => 'counter_disagreement',
        ]);

        // Admin triggers third count for specific items
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/trigger-third-count", [
                'item_ids' => [$item->id],
            ]);

        $response->assertStatus(200);

        // Verify counting is now in Count3InProgress
        $counting->refresh();
        $this->assertEquals(CountingStatus::Count3InProgress, $counting->status);
    }

    public function test_finalization_transitions_status(): void
    {
        // Create a counting already in PendingReview status with resolved items
        $counting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'status' => CountingStatus::PendingReview,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'created_by_user_id' => $this->adminUser->id,
        ]);

        $item = $this->createCountingItem($counting, '100.0000');
        $item->update([
            'count_1_qty' => '95.0000',
            'final_qty' => '95.0000',
            'resolution_method' => ItemResolutionMethod::AutoCountersAgree,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/finalize");

        $response->assertStatus(200);

        $counting->refresh();
        $this->assertEquals(CountingStatus::Finalized, $counting->status);
    }

    public function test_cannot_finalize_with_unresolved_items(): void
    {
        // Create a counting in PendingReview status but with unresolved items
        $counting = InventoryCounting::create([
            'company_id' => $this->company->id,
            'status' => CountingStatus::PendingReview,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
            'created_by_user_id' => $this->adminUser->id,
        ]);

        // Item with pending resolution (default)
        $this->createCountingItem($counting, '100.0000');

        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/inventory/countings/{$counting->id}/finalize");

        // LEDGER C-14(iii) — HONEST PIN REWRITE. This assertion used to pin a
        // 500: `finalize()` threw a bare `\InvalidArgumentException`, which has
        // no render handler, while its two sibling pre-finalize refusals
        // (`assertNoOverlappingActiveCounting`, `assertOpeningCostsResolved`)
        // both raise a `DomainException` and surface as a typed 422. "You still
        // have lines to resolve" is a business-rule refusal the reviewer must be
        // able to read and act on, not a server error.
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BUSINESS_ERROR');
        $this->assertStringContainsString(
            '1 item',
            (string) $response->json('error.message'),
            'The refusal must name how many lines are still pending. Body: '.$response->getContent(),
        );

        $counting->refresh();
        $this->assertSame(
            CountingStatus::PendingReview,
            $counting->status,
            'A refused finalize must leave the counting in pending_review.',
        );
    }

    private function createCountingSession(bool $requiresCount2, bool $requiresCount3): InventoryCounting
    {
        return InventoryCounting::create([
            'company_id' => $this->company->id,
            'status' => CountingStatus::Count1InProgress,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_ids' => [$this->warehouse->id]],
            'execution_mode' => CountingExecutionMode::Sequential,
            'requires_count_2' => $requiresCount2,
            'requires_count_3' => $requiresCount3,
            'allow_unexpected_items' => false,
            'created_by_user_id' => $this->adminUser->id,
            'count_1_user_id' => $this->counter1->id,
            'count_2_user_id' => $requiresCount2 ? $this->counter2->id : null,
            'count_3_user_id' => $requiresCount3 ? $this->counter3->id : null,
        ]);
    }

    private function createCountingItem(InventoryCounting $counting, string $theoreticalQty): InventoryCountingItem
    {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'theoretical_qty' => $theoreticalQty,
        ]);
    }

    private function assignCounter(InventoryCounting $counting, User $user, int $countNumber): void
    {
        InventoryCountingAssignment::create([
            'counting_id' => $counting->id,
            'user_id' => $user->id,
            'count_number' => $countNumber,
            'status' => AssignmentStatus::Pending,
            'assigned_at' => now(),
        ]);
    }
}
