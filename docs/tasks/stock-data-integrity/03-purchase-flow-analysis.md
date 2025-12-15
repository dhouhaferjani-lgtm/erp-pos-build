# Purchase Flow Transaction Analysis

> **Created:** 2025-12-13
> **Purpose:** Analyze purchase flow, goods receipt, WAC/Landed Cost handling

---

## 1. Current Purchase Flow State Machine

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         CURRENT IMPLEMENTATION                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│    [Purchase Order Draft]                                                    │
│          │                                                                   │
│          ├── Add lines (products with quantities + prices)                   │
│          │                                                                   │
│          ├── Add additional costs (freight, customs, etc.)                   │
│          │      DocumentAdditionalCost records created                       │
│          │                                                                   │
│          ↓                                                                   │
│    [PO Confirmed]                                                            │
│          │                                                                   │
│          ├── LandedCostService::allocateCosts() called                       │
│          │      ❌ NO TRANSACTION WRAPPER!                                   │
│          │      Each line.save() could fail independently                    │
│          │                                                                   │
│          ↓                                                                   │
│    [Awaiting Goods]                                                          │
│          │                                                                   │
│          ├── ❌ NO GOODS RECEIPT SERVICE EXISTS                              │
│          │      No GoodsReceiptNote document type                            │
│          │      Only manual stock adjustments available                      │
│          │                                                                   │
│          ├── If manually calling WeightedAverageCostService::recordPurchase()│
│          │      ❌ NO TRANSACTION, NO LOCKING                                │
│          │      Race condition possible                                      │
│          │                                                                   │
│          ↓                                                                   │
│    [PO Received] (status = 'received')                                       │
│          │                                                                   │
│          └── Supplier invoice matching (manual)                              │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Critical Issue: WeightedAverageCostService

**File:** `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`

### 2.1 Current Code Analysis

```php
public function recordPurchase(
    Product $product,
    Location $location,
    float $quantity,
    float $landedUnitCost,
    ?string $reference = null
): StockMovement {
    // ❌ NO DB::transaction wrapper
    // ❌ NO lockForUpdate on stock_levels
    // ❌ NO lockForUpdate on product

    $stockLevel = StockLevel::firstOrCreate([...]);  // Race condition here!

    $currentQty = (float) $stockLevel->quantity;
    $currentCostPrice = (float) ($product->cost_price ?? 0);
    // ... calculations using stale data possible ...

    $movement = StockMovement::create([...]);
    $stockLevel->quantity = (string) $newQty;
    $stockLevel->save();  // Could overwrite concurrent update!

    $product->cost_price = (string) $newAvgCost;
    $product->save();  // Could overwrite concurrent update!

    return $movement;
}
```

### 2.2 Race Condition Scenario

```
Time │ Request A                       │ Request B
─────┼─────────────────────────────────┼─────────────────────────────────
  1  │ Read stock: qty=10, cost=$5     │
  2  │                                 │ Read stock: qty=10, cost=$5
  3  │ Calculate: 10*$5 + 5*$6 = $80   │
  4  │                                 │ Calculate: 10*$5 + 3*$7 = $71
  5  │ Save qty=15, cost=$5.33         │
  6  │                                 │ Save qty=13, cost=$5.46 ← OVERWRITES!
─────┴─────────────────────────────────┴─────────────────────────────────

RESULT: qty=13 (should be 18), cost=$5.46 (should be $5.44)
Lost: 5 units, wrong cost forever
```

### 2.3 Required Fix

```php
public function recordPurchase(
    Product $product,
    Location $location,
    float $quantity,
    float $landedUnitCost,
    ?string $reference = null
): StockMovement {
    return DB::transaction(function () use ($product, $location, $quantity, $landedUnitCost, $reference) {
        // Lock stock level first
        $stockLevel = StockLevel::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->lockForUpdate()
            ->first();

        if (!$stockLevel) {
            $stockLevel = StockLevel::create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'tenant_id' => $product->tenant_id,
                'quantity' => 0,
            ]);
        }

        // Lock product for cost update
        $product = Product::lockForUpdate()->findOrFail($product->id);

        // Now safe to calculate and update...
        // (rest of logic)
    });
}
```

---

## 3. Critical Issue: LandedCostService

**File:** `app/Modules/Inventory/Application/Services/LandedCostService.php`

### 3.1 Current Code Analysis

```php
public function allocateCosts(Document $purchaseOrder): void
{
    // ❌ NO DB::transaction wrapper

    $lines = $purchaseOrder->lines;
    $additionalCostsTotal = (float) $purchaseOrder->additionalCosts()->sum('amount');
    $subtotal = (float) $lines->sum('line_total');

    foreach ($lines as $line) {
        // Calculate allocation
        $allocatedCost = ...;

        $line->allocated_costs = (string) $allocatedCost;
        $line->landed_unit_cost = ...;
        $line->save();  // ❌ Could fail on any iteration!
    }
}
```

### 3.2 Partial Allocation Scenario

If PO has 5 lines and line 3 fails to save:
- Lines 1-2: Have allocated costs
- Lines 3-5: No allocated costs
- Result: Incorrect landed costs, wrong WAC when goods received

### 3.3 Required Fix

```php
public function allocateCosts(Document $purchaseOrder): void
{
    DB::transaction(function () use ($purchaseOrder) {
        $lines = $purchaseOrder->lines;
        $additionalCostsTotal = (float) $purchaseOrder->additionalCosts()->sum('amount');
        $subtotal = (float) $lines->sum('line_total');

        foreach ($lines as $line) {
            // Calculate allocation
            $allocatedCost = ...;

            $line->allocated_costs = (string) $allocatedCost;
            $line->landed_unit_cost = ...;
            $line->save();
        }

        // Mark PO as costs allocated
        $purchaseOrder->update([
            'payload' => array_merge($purchaseOrder->payload ?? [], [
                'costs_allocated_at' => now()->toDateTimeString(),
            ])
        ]);
    });
}
```

---

## 4. Missing: Goods Receipt Service

### 4.1 What Should Happen on Goods Receipt

```
[PO Confirmed + Costs Allocated]
       │
       ↓
[Goods Arrive at Warehouse]
       │
       ├── Create Goods Receipt Note (GRN) document
       │      or use PO status transition
       │
       ├── For each line:
       │      ├── Validate received qty ≤ ordered qty
       │      ├── Lock stock level
       │      ├── Receive stock (increment quantity)
       │      ├── Update WAC using landed_unit_cost
       │      └── Create StockMovement record
       │
       ├── Mark PO as received (or partially received)
       │
       ├── Create GL entry:
       │      Dr. Inventory (31x)
       │      Cr. Accounts Payable (401)
       │
       └── Dispatch GoodsReceived event
```

### 4.2 Proposed GoodsReceiptService

```php
<?php

namespace App\Modules\Inventory\Application\Services;

class GoodsReceiptService
{
    public function __construct(
        private readonly StockAdjustmentService $stockService,
        private readonly WeightedAverageCostService $wacService,
        private readonly GeneralLedgerService $glService,
    ) {}

    /**
     * Receive goods for a purchase order
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
                    continue; // Skip lines with no quantity to receive
                }

                // Validate not over-receiving
                $alreadyReceived = $line->quantity_received ?? '0.00';
                $remaining = bcsub((string) $line->quantity, $alreadyReceived, 4);

                if (bccomp($qtyToReceive, $remaining, 4) > 0) {
                    throw new \DomainException("Cannot receive more than ordered for line {$line->id}");
                }

                // Get product
                $product = Product::lockForUpdate()->findOrFail($line->product_id);

                if (!$product->isPhysical()) {
                    continue; // Skip services
                }

                // Use landed cost from the PO line
                $landedUnitCost = (float) ($line->landed_unit_cost ?? $line->unit_price);

                // Record purchase with WAC update
                $this->wacService->recordPurchase(
                    $product,
                    $location,
                    (float) $qtyToReceive,
                    $landedUnitCost,
                    $purchaseOrder->document_number
                );

                // Update line's received quantity
                $line->quantity_received = bcadd($alreadyReceived, $qtyToReceive, 4);
                $line->save();
            }

            // Check if fully received
            $fullyReceived = true;
            foreach ($purchaseOrder->lines as $line) {
                if (bccomp((string) $line->quantity_received, (string) $line->quantity, 4) < 0) {
                    $fullyReceived = false;
                    break;
                }
            }

            // Update PO status
            $purchaseOrder->update([
                'status' => $fullyReceived ? DocumentStatus::Received : $purchaseOrder->status,
                'payload' => array_merge($purchaseOrder->payload ?? [], [
                    'last_goods_receipt_at' => now()->toDateTimeString(),
                    'fully_received' => $fullyReceived,
                ])
            ]);

            // Create GL entry for inventory valuation
            $this->createInventoryGLEntry($purchaseOrder, $receivedQuantities);

            return $purchaseOrder->fresh();
        });
    }
}
```

---

## 5. Document Lines Schema Gap

### 5.1 Current Schema

The `document_lines` table has:
- `quantity` - Ordered quantity
- `quantity_delivered` - Used for sales flow (outbound)

### 5.2 Missing Column

For purchase flow, we need:
- `quantity_received` - Tracks inbound goods receipt

**Migration Required:**
```php
Schema::table('document_lines', function (Blueprint $table) {
    $table->decimal('quantity_received', 15, 4)->default(0)->after('quantity_delivered');
});
```

---

## 6. Purchase Flow Transaction Boundaries

### 6.1 PO Confirmation + Cost Allocation

| Event | Should Be Transactional? | Current | Fix |
|-------|-------------------------|---------|-----|
| Update PO status | ✅ Yes | ❌ No | Wrap with LandedCost |
| Allocate landed costs | ✅ Yes | ❌ No | Add transaction |

**Proposed Flow:**
```php
public function confirmPurchaseOrder(Document $purchaseOrder): Document
{
    return DB::transaction(function () use ($purchaseOrder) {
        // 1. Update status
        $purchaseOrder->update(['status' => DocumentStatus::Confirmed]);

        // 2. Allocate landed costs atomically
        $this->landedCostService->allocateCosts($purchaseOrder);

        return $purchaseOrder->fresh();
    });
}
```

### 6.2 Goods Receipt

| Event | Should Be Transactional? | Current | Fix |
|-------|-------------------------|---------|-----|
| Validate quantities | N/A | N/A | Create service |
| Lock stock levels | ✅ Yes | ❌ N/A | Add to service |
| Receive stock | ✅ Yes | ❌ N/A | Add to service |
| Update WAC | ✅ Yes | ❌ No locking! | Fix WAC service |
| Create movements | ✅ Yes | ❌ N/A | Add to service |
| Update PO status | ✅ Yes | ❌ N/A | Add to service |
| Create GL entry | ✅ Yes | ❌ N/A | Add to service |

**ALL of these must be in ONE transaction with proper locking.**

---

## 7. Supplier Invoice Matching (Future)

### 7.1 Current State

No formal invoice matching process. Manual creation of:
- Supplier Invoice document
- Payment to supplier

### 7.2 Ideal Flow

```
[Goods Received]
       │
       ├── PO line has: expected_cost (from PO), landed_cost (with additional costs)
       │
       ↓
[Supplier Invoice Arrives]
       │
       ├── Match invoice lines to PO lines
       ├── Handle variances:
       │      ├── Price variance → Expense or Inventory adjustment
       │      ├── Quantity variance → Goods in transit or error
       │
       ├── Create AP entry:
       │      Dr. Goods in Transit or Inventory Variance
       │      Cr. Accounts Payable
       │
       └── Mark PO as invoiced
```

This is lower priority but important for accurate inventory costing.

---

## 8. Summary: What Needs to Change

| Priority | Change | File | Impact |
|----------|--------|------|--------|
| 🔴 CRITICAL | Add transaction + locking to WAC | `WeightedAverageCostService.php` | Prevents data corruption |
| 🔴 CRITICAL | Add transaction to LandedCost | `LandedCostService.php` | Prevents partial allocation |
| 🔴 CRITICAL | Create `GoodsReceiptService` | New file | Enables stock receiving |
| 🟠 HIGH | Add `quantity_received` column | Migration | Track inbound quantities |
| 🟠 HIGH | Create PO confirmation service | New file | Atomic confirm + cost allocation |
| 🟡 MEDIUM | Add GL entries on goods receipt | `GoodsReceiptService.php` | Proper inventory accounting |
| 🟢 LOW | Supplier invoice matching | Future | Price variance handling |

---

## 9. Weighted Average Cost Formula Verification

### 9.1 Formula Used

```
New WAC = (Current Quantity × Current WAC + New Quantity × New Unit Cost) / Total Quantity
```

**Code:**
```php
$currentValue = $currentQty * $currentCostPrice;
$newValue = $quantity * $landedUnitCost;  // Uses LANDED cost, not unit price ✅
$newAvgCost = ($currentValue + $newValue) / ($currentQty + $quantity);
```

**Correct?** ✅ Yes, the formula is correct. The issue is the lack of locking.

### 9.2 Landed Cost Integration

The WAC service expects `landedUnitCost` as input, which should come from:
```
landed_unit_cost = (line_total + allocated_additional_costs) / quantity
```

This is correctly calculated in `LandedCostService.php:32-34`:
```php
$line->landed_unit_cost = (float) $line->quantity > 0
    ? (string) round(((float) $line->line_total + $allocatedCost) / (float) $line->quantity, 2)
    : $line->unit_price;
```

**Issue:** The connection between `LandedCostService` output and `WeightedAverageCostService` input is not automated. The goods receipt process must use `line->landed_unit_cost` when calling WAC.

---

## 10. Files Reference

| File | Current State | Needs Change |
|------|---------------|--------------|
| `WeightedAverageCostService.php` | ❌ No transaction/locking | 🔴 CRITICAL FIX |
| `LandedCostService.php` | ❌ No transaction | 🔴 CRITICAL FIX |
| `GoodsReceiptService.php` | ❌ Doesn't exist | 🔴 CREATE |
| `StockAdjustmentService.php` | ✅ Has transaction + locking | ✅ Reference implementation |
| `document_lines` migration | ⚠️ Missing `quantity_received` | 🟠 ADD COLUMN |
