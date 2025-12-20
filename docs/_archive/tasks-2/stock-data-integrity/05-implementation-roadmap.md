# Stock Data Integrity - Implementation Roadmap

> **Created:** 2025-12-13
> **Purpose:** Prioritized implementation plan for stock integrity fixes

---

## Executive Summary

The codebase has good transaction handling for fiscal/compliance operations (~70% ACID compliance), but critical gaps exist in inventory management that could lead to:
- **Double-selling** (same stock sold to multiple customers)
- **WAC corruption** (race conditions on cost calculations)
- **Partial data** (landed cost allocation failures)

This roadmap addresses these issues in priority order.

---

## Phase 1: Critical Fixes (Data Integrity)

### 1.1 Fix WeightedAverageCostService

**File:** `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`

**Problem:** No transaction, no locking - race condition on WAC calculation.

**Fix:**
```php
public function recordPurchase(...): StockMovement
{
    return DB::transaction(function () use (...) {
        // Lock stock level first
        $stockLevel = StockLevel::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->lockForUpdate()
            ->first();

        // Lock product for cost update
        $product = Product::lockForUpdate()->findOrFail($product->id);

        // ... rest of logic
    });
}
```

**Impact:** Prevents corrupted cost prices and stock quantities.

**Test:**
```php
/** @test */
public function concurrent_purchases_calculate_correct_wac(): void
{
    // Simulate two concurrent purchase receipts
    // Assert final WAC and quantity are correct
}
```

---

### 1.2 Fix LandedCostService

**File:** `app/Modules/Inventory/Application/Services/LandedCostService.php`

**Problem:** No transaction wrapper - partial allocation if loop fails.

**Fix:**
```php
public function allocateCosts(Document $purchaseOrder): void
{
    DB::transaction(function () use ($purchaseOrder) {
        $lines = $purchaseOrder->lines;
        // ... existing logic ...

        // Add completion marker
        $purchaseOrder->update([
            'payload' => array_merge($purchaseOrder->payload ?? [], [
                'costs_allocated_at' => now()->toDateTimeString(),
            ])
        ]);
    });
}
```

**Impact:** Ensures all-or-nothing cost allocation.

**Test:**
```php
/** @test */
public function allocation_rolls_back_on_line_failure(): void
{
    // Create PO with 5 lines
    // Mock line 3 save to fail
    // Assert no lines have allocated_costs
}
```

---

## Phase 2: Stock Movement Integration

### 2.1 Create SalesOrderService

**File:** `app/Modules/Document/Domain/Services/SalesOrderService.php` (new)

**Purpose:** Handle SO confirmation with stock reservation.

**Implementation:**
```php
<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use Illuminate\Support\Facades\DB;

final class SalesOrderService
{
    public function __construct(
        private readonly StockAdjustmentService $stockService,
    ) {}

    public function confirm(Document $salesOrder): Document
    {
        if ($salesOrder->type !== DocumentType::SalesOrder) {
            throw new \DomainException('Only sales orders can be confirmed with this service');
        }

        if (!$salesOrder->isDraft()) {
            throw new \DomainException('Only draft sales orders can be confirmed');
        }

        return DB::transaction(function () use ($salesOrder): Document {
            // Reserve stock for all physical product lines
            foreach ($salesOrder->lines as $line) {
                if ($line->product_id && $line->product?->isPhysical()) {
                    try {
                        $this->stockService->reserve(
                            productId: $line->product_id,
                            locationId: $salesOrder->location_id ?? $this->getDefaultLocation($salesOrder),
                            quantity: (string) $line->quantity,
                            reference: $salesOrder->document_number,
                            userId: (string) auth()->id()
                        );
                    } catch (InsufficientStockException $e) {
                        throw new \DomainException(
                            "Insufficient stock for {$line->product->name}: " .
                            "Available: {$e->available}, Requested: {$line->quantity}"
                        );
                    }
                }
            }

            $salesOrder->update(['status' => DocumentStatus::Confirmed]);

            return $salesOrder->fresh();
        });
    }

    private function getDefaultLocation(Document $document): string
    {
        // Get company's default location
        $location = $document->company->locations()->where('is_default', true)->first();
        if (!$location) {
            throw new \RuntimeException('No default location configured for company');
        }
        return $location->id;
    }
}
```

**Register in Service Provider:**
```php
$this->app->bind(SalesOrderService::class);
```

---

### 2.2 Modify DeliveryNoteService

**File:** `app/Modules/Document/Domain/Services/DeliveryNoteService.php`

**Add Stock Issuance:**
```php
public function confirm(Document $deliveryNote): Document
{
    if ($deliveryNote->type !== DocumentType::DeliveryNote) {
        throw new \DomainException('...');
    }

    if (!$deliveryNote->isDraft()) {
        throw new \DomainException('...');
    }

    return DB::transaction(function () use ($deliveryNote): Document {
        // 1. Issue stock for all physical product lines
        $this->issueStock($deliveryNote);

        // 2. Seal with fiscal hash chain
        $this->confirmWithFiscalChain($deliveryNote);

        return $deliveryNote->fresh(['lines']);
    });
}

private function issueStock(Document $deliveryNote): void
{
    $locationId = $deliveryNote->location_id ?? $this->getDefaultLocation($deliveryNote);

    foreach ($deliveryNote->lines as $line) {
        if ($line->product_id === null) {
            continue; // Skip service lines
        }

        $product = Product::find($line->product_id);
        if ($product === null || !$product->isPhysical()) {
            continue;
        }

        // Release any existing reservation first
        $sourceDoc = $deliveryNote->sourceDocument;
        if ($sourceDoc && $sourceDoc->type === DocumentType::SalesOrder) {
            $this->stockAdjustmentService->releaseReservation(
                productId: $line->product_id,
                locationId: $locationId,
                quantity: (string) $line->quantity,
                reference: $sourceDoc->document_number,
                userId: (string) auth()->id()
            );
        }

        // Then issue stock
        $this->stockAdjustmentService->issue(
            productId: $line->product_id,
            locationId: $locationId,
            quantity: (string) $line->quantity,
            reference: $deliveryNote->document_number,
            userId: (string) auth()->id()
        );
    }
}
```

---

### 2.3 Create GoodsReceiptService

**File:** `app/Modules/Inventory/Application/Services/GoodsReceiptService.php` (new)

**Purpose:** Receive goods from PO with WAC update.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\DB;

final class GoodsReceiptService
{
    public function __construct(
        private readonly WeightedAverageCostService $wacService,
    ) {}

    /**
     * Receive goods for a purchase order.
     *
     * @param Document $purchaseOrder
     * @param array<string, string> $receivedQuantities Map of line_id => quantity
     */
    public function receiveGoods(Document $purchaseOrder, array $receivedQuantities): Document
    {
        if ($purchaseOrder->type !== DocumentType::PurchaseOrder) {
            throw new \DomainException('Only purchase orders can receive goods');
        }

        if ($purchaseOrder->status !== DocumentStatus::Confirmed) {
            throw new \DomainException('Purchase order must be confirmed before receiving goods');
        }

        return DB::transaction(function () use ($purchaseOrder, $receivedQuantities): Document {
            $location = $purchaseOrder->location ?? $this->getDefaultLocation($purchaseOrder);

            foreach ($purchaseOrder->lines as $line) {
                $qtyToReceive = $receivedQuantities[$line->id] ?? '0.00';

                if (bccomp($qtyToReceive, '0.00', 4) <= 0) {
                    continue;
                }

                // Validate not over-receiving
                $alreadyReceived = $line->quantity_received ?? '0.00';
                $remaining = bcsub((string) $line->quantity, $alreadyReceived, 4);

                if (bccomp($qtyToReceive, $remaining, 4) > 0) {
                    throw new \DomainException("Cannot receive more than ordered");
                }

                $product = Product::lockForUpdate()->find($line->product_id);
                if (!$product || !$product->isPhysical()) {
                    continue;
                }

                // Use landed cost from PO line
                $landedUnitCost = (float) ($line->landed_unit_cost ?? $line->unit_price);

                // Record purchase with WAC update
                $this->wacService->recordPurchase(
                    $product,
                    $location,
                    (float) $qtyToReceive,
                    $landedUnitCost,
                    $purchaseOrder->document_number
                );

                // Update received quantity
                $line->quantity_received = bcadd($alreadyReceived, $qtyToReceive, 4);
                $line->save();
            }

            // Check if fully received
            $fullyReceived = $this->isFullyReceived($purchaseOrder);

            $purchaseOrder->update([
                'status' => $fullyReceived ? DocumentStatus::Received : $purchaseOrder->status,
                'payload' => array_merge($purchaseOrder->payload ?? [], [
                    'last_goods_receipt_at' => now()->toDateTimeString(),
                    'fully_received' => $fullyReceived,
                ])
            ]);

            return $purchaseOrder->fresh();
        });
    }

    private function isFullyReceived(Document $purchaseOrder): bool
    {
        foreach ($purchaseOrder->lines as $line) {
            if (bccomp((string) $line->quantity_received, (string) $line->quantity, 4) < 0) {
                return false;
            }
        }
        return true;
    }
}
```

---

### 2.4 Add quantity_received Column

**Migration:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->decimal('quantity_received', 15, 4)
                ->default(0)
                ->after('quantity_delivered')
                ->comment('Quantity received for purchase orders');
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->dropColumn('quantity_received');
        });
    }
};
```

---

## Phase 3: API Updates

### 3.1 Update DocumentController

**Modify SO confirmation to use SalesOrderService:**

```php
// In DocumentController or dedicated SalesOrderController

public function confirm(Request $request, string $id): JsonResponse
{
    $document = Document::findOrFail($id);

    $confirmed = match ($document->type) {
        DocumentType::SalesOrder => $this->salesOrderService->confirm($document),
        DocumentType::DeliveryNote => $this->deliveryNoteService->confirm($document),
        default => $this->defaultConfirm($document),
    };

    return response()->json(['data' => $confirmed]);
}
```

### 3.2 Create Goods Receipt Endpoint

```php
// routes/api.php
Route::post('/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive']);

// PurchaseOrderController.php
public function receive(Request $request, string $id): JsonResponse
{
    $request->validate([
        'quantities' => 'required|array',
        'quantities.*' => 'numeric|min:0',
    ]);

    $purchaseOrder = Document::findOrFail($id);
    $received = $this->goodsReceiptService->receiveGoods(
        $purchaseOrder,
        $request->input('quantities')
    );

    return response()->json(['data' => $received]);
}
```

---

## Phase 4: Testing

### 4.1 Unit Tests

| Test | Purpose |
|------|---------|
| `WeightedAverageCostServiceTest` | Verify WAC calculation with locking |
| `LandedCostServiceTest` | Verify atomic allocation |
| `SalesOrderServiceTest` | Verify reservation on confirm |
| `DeliveryNoteServiceTest` | Verify stock issuance |
| `GoodsReceiptServiceTest` | Verify receiving with WAC update |

### 4.2 Integration Tests

| Test | Scenario |
|------|----------|
| `StockIntegrityTest` | Concurrent SO confirmations for same stock |
| `PurchaseFlowTest` | PO → Confirm → Allocate → Receive → WAC check |
| `SalesFlowTest` | SO → Confirm (reserve) → DN (issue) → Invoice |

### 4.3 Example Test

```php
/** @test */
public function concurrent_sales_orders_cannot_oversell(): void
{
    // Setup: Product with 10 units
    $product = Product::factory()->create(['is_physical' => true]);
    $location = Location::factory()->create();
    StockLevel::factory()->create([
        'product_id' => $product->id,
        'location_id' => $location->id,
        'quantity' => 10,
        'reserved' => 0,
    ]);

    // Create two sales orders each requesting 8 units
    $order1 = $this->createSalesOrderDraft($product, quantity: 8);
    $order2 = $this->createSalesOrderDraft($product, quantity: 8);

    // First confirm should succeed
    $service = app(SalesOrderService::class);
    $confirmed1 = $service->confirm($order1);
    $this->assertEquals('confirmed', $confirmed1->status->value);

    // Second confirm should fail
    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('Insufficient stock');
    $service->confirm($order2);

    // Verify stock state
    $stockLevel = StockLevel::where('product_id', $product->id)->first();
    $this->assertEquals('10.00', $stockLevel->quantity);
    $this->assertEquals('8.00', $stockLevel->reserved);
}
```

---

## Phase 5: Future Enhancements

### 5.1 Time-Based Reservations

- Add `stock_reservations` table with `expires_at`
- Create `ReservationDurationCalculator` service
- Add scheduled job `ExpireStaleReservations`
- Add WebSocket events for real-time UI updates

### 5.2 Real-Time UI

- Broadcast `StockLevelChanged` events
- Subscribe in frontend with Laravel Echo
- Update product availability in real-time

### 5.3 Supplier Invoice Matching

- Match supplier invoice to PO lines
- Handle price and quantity variances
- Create appropriate GL entries

---

## Implementation Checklist

### Phase 1: Critical Fixes
- [ ] Fix `WeightedAverageCostService` - add transaction + locking
- [ ] Fix `LandedCostService` - add transaction
- [ ] Write tests for concurrent access

### Phase 2: Stock Movement Integration
- [ ] Create `SalesOrderService` with reservation
- [ ] Modify `DeliveryNoteService` to issue stock
- [ ] Create `GoodsReceiptService` for PO receiving
- [ ] Add `quantity_received` migration
- [ ] Wire up services in providers

### Phase 3: API Updates
- [ ] Update SO confirmation endpoint
- [ ] Create goods receipt endpoint
- [ ] Update API documentation

### Phase 4: Testing
- [ ] Write unit tests for all new services
- [ ] Write integration tests for concurrent access
- [ ] Write E2E tests for complete flows

### Phase 5: Future
- [ ] Time-based reservations
- [ ] Real-time UI updates
- [ ] Supplier invoice matching

---

## Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| WAC corruption (current) | High | Critical | Phase 1 fixes |
| Double-selling (current) | High | Critical | Phase 2 fixes |
| Partial cost allocation | Medium | High | Phase 1 fixes |
| UI shows stale stock | Medium | Medium | Phase 5 |

---

## Files Quick Reference

| File | Status | Action |
|------|--------|--------|
| `WeightedAverageCostService.php` | ❌ Critical | Add transaction + locking |
| `LandedCostService.php` | ❌ High Risk | Add transaction |
| `DeliveryNoteService.php` | ⚠️ Incomplete | Add stock issuance |
| `SalesOrderService.php` | ❌ Missing | Create with reservation |
| `GoodsReceiptService.php` | ❌ Missing | Create for PO receiving |
| `StockAdjustmentService.php` | ✅ Good | Reference implementation |
| `DocumentConversionService.php` | ✅ Good | No changes needed |
| `DocumentPostingService.php` | ✅ Good | No changes needed |
