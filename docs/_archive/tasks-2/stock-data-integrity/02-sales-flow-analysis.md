# Sales Flow Transaction Analysis

> **Created:** 2025-12-13
> **Purpose:** Analyze sales flow from Quote to Payment, identify where stock should be handled

---

## 1. Current Sales Flow State Machine

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         CURRENT IMPLEMENTATION                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│    [Quote Draft] ──confirm──> [Quote Confirmed]                              │
│                                      │                                       │
│                        convertQuoteToOrder()                                 │
│                                      ↓                                       │
│                            [Sales Order Draft]                               │
│                                      │                                       │
│                                  confirm                                     │
│                                      ↓                                       │
│                          [Sales Order Confirmed] ◄──── Prepayments allocated │
│                            /                \                                │
│         convertOrderToDelivery()     convertOrderToInvoice()                 │
│                    ↓                           ↓                             │
│           [Delivery Note Draft]        [Invoice Draft]                       │
│                    │                           │                             │
│          DeliveryNoteService::confirm()    post()                            │
│                    ↓                           ↓                             │
│           [DN Confirmed/Sealed]          [Invoice Posted]                    │
│           ❌ NO STOCK MOVEMENT!            + GL Entry                        │
│                                                │                             │
│                                           Payment                            │
│                                                ↓                             │
│                                          [Invoice Paid]                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Transaction Boundary Analysis

### 2.1 Quote → Sales Order Conversion

**File:** `DocumentConversionService.php:28-88`

| Aspect | Status | Notes |
|--------|--------|-------|
| Transaction | ✅ `DB::transaction` | Wraps entire operation |
| Locking | ❌ None | No concurrent access concern (new document) |
| Stock Impact | None | Quotes don't affect stock |
| Correct? | ✅ Yes | Single write operation, rollback on failure |

**Transaction Contents:**
1. Create Sales Order document
2. Copy lines from Quote
3. Update Quote payload with conversion reference

### 2.2 Sales Order Confirmation

**Current State:** NO explicit confirmation service

**Problem:** Status is updated directly without validation:
```php
// In DocumentController or via API
$order->update(['status' => DocumentStatus::Confirmed]);
```

**Required Changes:**
- Add `SalesOrderService::confirm()` method
- Should check stock availability BEFORE confirmation
- Should create reservations on confirmation

### 2.3 Sales Order → Delivery Note Conversion

**File:** `DocumentConversionService.php:206-269` (full) and `:286-372` (partial)

| Aspect | Status | Notes |
|--------|--------|-------|
| Transaction | ✅ `DB::transaction` | Wraps entire operation |
| Locking | ❌ None | Should lock stock levels |
| Stock Reservation | ❌ Not checked | Should validate reservation exists |
| Stock Movement | ❌ Not triggered | Should be triggered on DN creation or confirmation |
| Correct? | ⚠️ Partial | Missing stock handling |

**Transaction Contents:**
1. Create Delivery Note document
2. Copy lines with quantities
3. Update `quantity_delivered` on source order lines
4. Update order payload with DN reference

**Missing:**
- Stock level validation
- Reservation consumption
- Stock decrement trigger

### 2.4 Delivery Note Confirmation

**File:** `DeliveryNoteService.php:43-63`

| Aspect | Status | Notes |
|--------|--------|-------|
| Transaction | ✅ `DB::transaction` | Wraps entire operation |
| Locking | ✅ `lockForUpdate` | Previous DN in hash chain |
| Hash Chain | ✅ Complete | Fiscal compliance |
| Stock Movement | ❌ NOT CALLED | Should call StockAdjustmentService::issue() |
| Correct? | ❌ INCOMPLETE | Stock movement missing! |

**Transaction Contents (current):**
1. Lock previous DN for hash chain
2. Calculate fiscal hash
3. Update DN with sealed status + hash

**Missing:**
```php
// Should be added:
foreach ($deliveryNote->lines as $line) {
    if ($line->product && $line->product->isPhysical()) {
        $this->stockAdjustmentService->issue(
            productId: $line->product_id,
            locationId: $deliveryNote->location_id,
            quantity: $line->quantity,
            reference: $deliveryNote->document_number,
            userId: auth()->id()
        );
    }
}
```

### 2.5 Sales Order → Invoice Conversion

**File:** `DocumentConversionService.php:100-198`

| Aspect | Status | Notes |
|--------|--------|-------|
| Transaction | ✅ `DB::transaction` | Wraps entire operation |
| Locking | ❌ None | Document numbering has own lock |
| Delivery Check | ✅ Implemented | Tunisia compliance: products must be delivered |
| Prepayment Transfer | ✅ Implemented | Allocations moved from SO to Invoice |
| Correct? | ✅ Yes | No stock impact (happens at DN confirmation) |

**Transaction Contents:**
1. Validate Tunisia compliance (products must have DN first)
2. Create Invoice document
3. Copy lines (full or partial)
4. Recalculate totals
5. Transfer prepayments from SO to Invoice
6. Update order payload
7. Mark associated delivery notes as invoiced

### 2.6 Invoice Posting

**File:** `DocumentPostingService.php`

| Aspect | Status | Notes |
|--------|--------|-------|
| Transaction | ✅ `DB::transaction` | Wraps entire operation |
| Locking | ✅ `lockForUpdate` | Previous invoice in hash chain |
| Hash Chain | ✅ Complete | Fiscal compliance |
| GL Entry | ✅ Created | Accounts receivable entry |
| Correct? | ✅ Yes | Properly atomic |

---

## 3. The Missing Link: Stock Movement

### 3.1 Where Should Stock Be Decremented?

**Answer: On Delivery Note CONFIRMATION**

```
Sales Order (Confirmed)
       │
       ├── Stock should be RESERVED (not decremented)
       │
       ↓
Delivery Note (Draft)
       │
       ├── Stock NOT affected yet (picking in progress)
       │
       ↓
Delivery Note (Confirmed) ◄── THIS IS WHERE STOCK MOVES
       │
       ├── Stock DECREMENTED (goods left the building)
       ├── Reservation RELEASED
       │
       ↓
Invoice (Posted)
       │
       └── NO stock impact (just accounting entry)
```

### 3.2 Why Not On Delivery Note Creation?

In warehouse workflows:
1. Create DN (Draft) = Generate pick list
2. Warehouse picks items = Physical activity
3. Confirm DN = Goods have left, stock moves

If we decremented on creation, picking errors couldn't be corrected without complex reversals.

### 3.3 Current Gap in DeliveryNoteService

```php
// DeliveryNoteService.php - CURRENT CODE (line 57-62)
return DB::transaction(function () use ($deliveryNote): Document {
    $this->confirmWithFiscalChain($deliveryNote);  // Only does hash chain!
    // ❌ NO STOCK MOVEMENT CALL
    return $deliveryNote->fresh(['lines']);
});

// SHOULD BE:
return DB::transaction(function () use ($deliveryNote): Document {
    // 1. Issue stock for all physical product lines
    $this->issueStock($deliveryNote);

    // 2. Then seal with fiscal hash
    $this->confirmWithFiscalChain($deliveryNote);

    return $deliveryNote->fresh(['lines']);
});
```

---

## 4. Stock Reservation Requirements

### 4.1 When to Reserve

| Event | Action | Rationale |
|-------|--------|-----------|
| SO Confirmed | Create reservation | Prevent overselling |
| DN Created (from SO) | Transfer reservation to DN | Track which DN has which stock |
| DN Confirmed | Release reservation + Issue stock | Stock physically left |
| DN Cancelled | Release reservation | Return stock to available pool |
| SO Cancelled | Release all reservations | Return stock to available pool |

### 4.2 Reservation Schema (Already Exists)

```sql
-- stock_levels table has:
quantity  DECIMAL(15,2)  -- Physical on-hand
reserved  DECIMAL(15,2)  -- Committed to orders

-- Available = quantity - reserved
```

### 4.3 StockAdjustmentService Has Reserve Method

```php
// Already exists in StockAdjustmentService.php
public function reserve(
    string $productId,
    string $locationId,
    string $quantity,
    string $reference,
    string $userId
): StockMovement {
    return DB::transaction(function () use (...): StockMovement {
        $stockLevel = $this->lockStockLevel($productId, $locationId);
        $available = $stockLevel->getAvailableQuantity();

        if (bccomp($quantity, $available, self::SCALE) > 0) {
            throw new InsufficientStockException(...);
        }

        // Create reservation movement
        $movement = StockMovement::create([
            'type' => StockMovementType::Reserve,
            ...
        ]);

        // Increment reserved count
        $stockLevel->reserved = bcadd(
            (string) $stockLevel->reserved,
            $quantity,
            self::SCALE
        );
        $stockLevel->save();

        return $movement;
    });
}
```

**Problem:** This method is never called from the document flow!

---

## 5. Proposed Fix: Sales Order Confirmation Service

Create new service: `SalesOrderService.php`

```php
<?php

namespace App\Modules\Document\Domain\Services;

class SalesOrderService
{
    public function __construct(
        private readonly StockAdjustmentService $stockService,
    ) {}

    public function confirm(Document $salesOrder): Document
    {
        if ($salesOrder->type !== DocumentType::SalesOrder) {
            throw new \DomainException('Only sales orders can be confirmed');
        }

        if (!$salesOrder->isDraft()) {
            throw new \DomainException('Only draft sales orders can be confirmed');
        }

        return DB::transaction(function () use ($salesOrder): Document {
            // 1. Reserve stock for all physical product lines
            foreach ($salesOrder->lines as $line) {
                if ($line->product_id && $line->product?->isPhysical()) {
                    $this->stockService->reserve(
                        productId: $line->product_id,
                        locationId: $salesOrder->location_id,
                        quantity: (string) $line->quantity,
                        reference: $salesOrder->document_number,
                        userId: auth()->id()
                    );
                }
            }

            // 2. Update status
            $salesOrder->update([
                'status' => DocumentStatus::Confirmed,
            ]);

            return $salesOrder->fresh();
        });
    }
}
```

---

## 6. Proposed Fix: Delivery Note Stock Issuance

Modify `DeliveryNoteService::confirm()`:

```php
public function confirm(Document $deliveryNote): Document
{
    // ... validation ...

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
    foreach ($deliveryNote->lines as $line) {
        if ($line->product_id === null) {
            continue; // Skip service lines
        }

        $product = Product::find($line->product_id);
        if ($product === null || !$product->isPhysical()) {
            continue; // Skip non-physical products
        }

        $this->stockAdjustmentService->issue(
            productId: $line->product_id,
            locationId: $deliveryNote->location_id ?? $this->getDefaultLocation($deliveryNote),
            quantity: (string) $line->quantity,
            reference: $deliveryNote->document_number,
            userId: auth()->id() ?? 'system'
        );
    }
}
```

---

## 7. Customer Creation Independence (CORRECT)

The user asked about customer creation being separate from document transactions.

**Current Implementation (Correct):**

```
[User opens Quote form]
       │
       ├── Clicks "Add New Customer"
       │
       ↓
POST /api/partners ──────────────────────────────> [Partner Created] ✅
       │                                                  │
       ├── Returns partner_id to frontend                 │
       │                                                  │
       ↓                                                  │
[User continues filling Quote form]                       │
       │                                                  │
       ↓                                                  │
POST /api/quotes ────────────────────────────────────────>│
       │                                                  ↓
       └─────────────> [Quote Created with partner_id] ──→ Uses existing partner
```

**Why This Is Correct:**
1. Partner creation is a standalone CRUD operation
2. If partner creation fails, no document is affected
3. If document creation fails, partner still exists (acceptable business outcome)
4. The user can reuse the partner for future documents

**No Change Needed:** This is the correct separation of concerns.

---

## 8. Summary: What Needs to Change

| Priority | Change | File | Impact |
|----------|--------|------|--------|
| 🔴 CRITICAL | Add stock issuance to DN confirmation | `DeliveryNoteService.php` | Prevents overselling |
| 🔴 CRITICAL | Create `SalesOrderService::confirm()` | New file | Stock reservation |
| 🟠 HIGH | Update SO confirmation API to use service | `DocumentController.php` | Enforce reservation |
| 🟠 HIGH | Add reservation release on DN cancel | New method | Return stock to pool |
| 🟡 MEDIUM | Add reservation transfer from SO to DN | `DocumentConversionService.php` | Precise tracking |
| 🟢 LOW | Real-time UI stock updates | Frontend | User experience |

---

## 9. Files Reference

| File | Current State | Needs Change |
|------|---------------|--------------|
| `DocumentConversionService.php` | ✅ Good transactions | 🟡 Add reservation tracking |
| `DeliveryNoteService.php` | ⚠️ Missing stock call | 🔴 Add `issueStock()` |
| `SalesOrderService.php` | ❌ Doesn't exist | 🔴 Create with `confirm()` |
| `StockAdjustmentService.php` | ✅ Has all methods | ✅ No change needed |
| `DocumentController.php` | ⚠️ Direct status update | 🟠 Use `SalesOrderService` |
