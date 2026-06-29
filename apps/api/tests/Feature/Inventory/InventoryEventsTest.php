<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for Inventory Module Events
 *
 * Verifies that all critical inventory operations emit proper domain events
 * for audit trail and fraud detection purposes.
 */
final class InventoryEventsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    private User $user;

    private WeightedAverageCostService $stockService;

    private InventoryCountingService $countingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Test Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->product = Product::factory()->for($this->tenant)->for($this->company)->create();
        $this->user = User::factory()->for($this->tenant)->create();

        $this->stockService = app(WeightedAverageCostService::class);
        $this->countingService = app(InventoryCountingService::class);

        // Pin CompanyContext so CurrencyScaleResolver can resolve scale without HTTP middleware.
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /**
     * Test that StockMovementRecorded event is dispatched on purchase
     */
    public function test_stock_movement_recorded_event_dispatched_on_purchase(): void
    {
        Event::fake([StockMovementRecorded::class]);

        $this->stockService->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: '10',
            landedUnitCost: '50',
            reference: 'PO-TEST-001',
            referenceType: 'Document',
            referenceId: '00000000-0000-4000-8000-000000000001'
        );

        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return $event->productId === $this->product->id
                && $event->locationId === $this->location->id
                && $event->movementType === 'purchase'
                && $event->quantity === '10.0000'
                && $event->reference === 'PO-TEST-001';
        });
    }

    /**
     * Test that StockMovementRecorded event is dispatched on sale
     */
    public function test_stock_movement_recorded_event_dispatched_on_sale(): void
    {
        // First add stock
        $this->stockService->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: '20',
            landedUnitCost: '50'
        );

        Event::fake([StockMovementRecorded::class]);

        $this->stockService->recordSale(
            product: $this->product,
            location: $this->location,
            quantity: 5.0,
            reference: 'DN-TEST-001',
            referenceType: 'Document',
            referenceId: '00000000-0000-4000-8000-000000000001'
        );

        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return $event->productId === $this->product->id
                && $event->locationId === $this->location->id
                && $event->movementType === 'sale'
                && $event->quantity === '-5.0000'
                && $event->reference === 'DN-TEST-001';
        });
    }

    /**
     * Test that StockMovementRecorded event is dispatched on return
     */
    public function test_stock_movement_recorded_event_dispatched_on_return(): void
    {
        Event::fake([StockMovementRecorded::class]);

        $this->stockService->recordReturn(
            product: $this->product,
            location: $this->location,
            quantity: 3.0,
            originalCost: 50.00,
            reference: 'RN-TEST-001',
            referenceType: 'Document',
            referenceId: '00000000-0000-4000-8000-000000000001'
        );

        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return $event->productId === $this->product->id
                && $event->locationId === $this->location->id
                && $event->movementType === 'return'
                && $event->quantity === '3.0000';
        });
    }

    /**
     * Test that StockMovementRecorded event contains correct structure
     */
    public function test_stock_movement_recorded_event_has_correct_structure(): void
    {
        Event::fake([StockMovementRecorded::class]);

        $this->stockService->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: '10',
            landedUnitCost: '50'
        );

        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return isset($event->movementId)
                && isset($event->tenantId)
                && isset($event->companyId)
                && isset($event->productId)
                && isset($event->locationId)
                && isset($event->movementType)
                && isset($event->quantity)
                && isset($event->unitCost)
                && isset($event->totalCost)
                && isset($event->newStockLevel)
                && isset($event->occurredAt);
        });
    }

    /**
     * Test that StockMovementRecorded event includes audit trail data
     */
    public function test_stock_movement_recorded_event_includes_audit_trail(): void
    {
        Event::fake([StockMovementRecorded::class]);

        $this->stockService->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: '10',
            landedUnitCost: '50',
            reference: 'PO-TEST-001',
            referenceType: 'Document',
            referenceId: '00000000-0000-4000-8000-000000000123'
        );

        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return $event->reference === 'PO-TEST-001'
                && $event->referenceType === 'Document'
                && $event->referenceId === '00000000-0000-4000-8000-000000000123';
        });
    }

    /**
     * Test that InventoryCountingCompleted event is dispatched when counting is finalized
     */
    public function test_inventory_counting_completed_event_dispatched_on_finalize(): void
    {
        // Create a counting session in PendingReview status (ready to finalize)
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'counting_number' => 'COUNT-TEST-001',
            'counting_date' => now(),
            'status' => CountingStatus::PendingReview,
            'is_blind' => false,
            'created_by_user_id' => $this->user->id,
        ]);

        // Add a counting item
        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '10.00',
            'final_qty' => '12.00',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        Event::fake([InventoryCountingCompleted::class]);

        $this->countingService->finalize($counting, $this->user);

        Event::assertDispatched(InventoryCountingCompleted::class, function (InventoryCountingCompleted $event) use ($counting): bool {
            return $event->countingId === $counting->id
                && $event->companyId === $counting->company_id
                && $event->tenantId === $this->tenant->id;
        });
    }

    /**
     * Test that InventoryCountingCompleted event contains variance data
     */
    public function test_inventory_counting_completed_event_includes_variance_data(): void
    {
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'counting_number' => 'COUNT-TEST-002',
            'counting_date' => now(),
            'status' => CountingStatus::PendingReview,
            'is_blind' => false,
            'created_by_user_id' => $this->user->id,
        ]);

        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '100.00',
            'final_qty' => '95.00',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        Event::fake([InventoryCountingCompleted::class]);

        $this->countingService->finalize($counting, $this->user);

        Event::assertDispatched(InventoryCountingCompleted::class, function (InventoryCountingCompleted $event): bool {
            return isset($event->totalVariance)
                && isset($event->itemsCount)
                && isset($event->completedBy);
        });
    }

    /**
     * Test that InventoryCountingCompleted event implements getEventName()
     */
    public function test_inventory_counting_completed_event_has_event_name(): void
    {
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'counting_number' => 'COUNT-TEST-003',
            'counting_date' => now(),
            'status' => CountingStatus::PendingReview,
            'is_blind' => false,
            'created_by_user_id' => $this->user->id,
        ]);

        // Add a counting item to prevent validation error
        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '10.00',
            'final_qty' => '10.00',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);

        Event::fake([InventoryCountingCompleted::class]);

        $this->countingService->finalize($counting, $this->user);

        Event::assertDispatched(InventoryCountingCompleted::class, function (InventoryCountingCompleted $event): bool {
            return method_exists($event, 'getEventName')
                && $event->getEventName() === 'inventory.counting.completed';
        });
    }

    /**
     * Test that StockMovementRecorded event implements getEventName()
     */
    public function test_stock_movement_recorded_event_has_event_name(): void
    {
        Event::fake([StockMovementRecorded::class]);

        $this->stockService->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: '10',
            landedUnitCost: '50'
        );

        Event::assertDispatched(StockMovementRecorded::class, function (StockMovementRecorded $event): bool {
            return method_exists($event, 'getEventName')
                && $event->getEventName() === 'inventory.stock_movement.recorded';
        });
    }
}
