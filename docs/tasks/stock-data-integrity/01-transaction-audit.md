# Transaction & ACID Compliance Audit

> **Created:** 2025-12-13
> **Purpose:** Document current state of transaction handling and identify gaps

---

## Executive Summary

**Overall ACID Compliance: ~70%**

The codebase has good transaction handling for fiscal/compliance operations but has critical gaps in inventory management that could lead to data corruption and overselling.

---

## 1. Current Transaction Audit

### Services WITH Proper Transactions + Locking

| Service | File | Transaction | Lock | Notes |
|---------|------|-------------|------|-------|
| DocumentController::store | `Document/Presentation/Controllers/DocumentController.php:225` | ✅ `DB::transaction` | ❌ None | Creates document + lines atomically |
| DocumentController::update | `DocumentController.php:351` | ✅ `DB::transaction` | ❌ None | Updates document atomically |
| DocumentPostingService::post | `Document/Domain/Services/DocumentPostingService.php:57` | ✅ `DB::transaction` | ✅ `lockForUpdate` | Fiscal hash chain locked |
| DeliveryNoteService::confirm | `Document/Domain/Services/DeliveryNoteService.php:57` | ✅ `DB::transaction` | ✅ `lockForUpdate` | Hash chain locked, BUT stock not moved! |
| DocumentNumberingService | `DocumentNumberingService.php:20` | ✅ `DB::transaction` | ✅ `lockForUpdate` | Sequential numbering protected |
| PaymentController::store | `Treasury/Presentation/Controllers/PaymentController.php:147` | ✅ `DB::transaction` | ✅ `lockForUpdate` | Document + repository locked |
| PaymentAllocationService | `PaymentAllocationService.php:86` | ✅ `DB::transaction` | ✅ `lockForUpdate` | Document locked during allocation |
| StockAdjustmentService | `Inventory/Domain/Services/StockAdjustmentService.php` | ✅ `DB::transaction` | ✅ `lockForUpdate` | Stock levels properly locked |
| DocumentConversionService | `DocumentConversionService.php` | ✅ `DB::transaction` | ⚠️ Partial | Multiple conversion methods, all wrapped |

### Services WITH CRITICAL GAPS

| Service | File | Issue | Risk Level |
|---------|------|-------|------------|
| **WeightedAverageCostService** | `Inventory/Application/Services/WeightedAverageCostService.php` | NO transaction, NO locking | 🔴 **CRITICAL** - Race condition on WAC calculation |
| **LandedCostService** | `Inventory/Application/Services/LandedCostService.php` | NO transaction | 🟠 HIGH - Partial allocation possible |

### Services WITHOUT Transactions (Acceptable)

| Service | File | Why Acceptable |
|---------|------|----------------|
| PartnerController::store | `Partner/Presentation/Controllers/PartnerController.php:127` | Single record, separate from document flow |
| ProductController::store | Similar | Single record creation |

---

## 2. Detailed Analysis

### 2.1 WeightedAverageCostService - CRITICAL

```php
// app/Modules/Inventory/Application/Services/WeightedAverageCostService.php

public function recordPurchase(Product $product, Location $location, float $quantity, float $landedUnitCost, ?string $reference = null): StockMovement
{
    // ❌ NO DB::transaction
    // ❌ NO lockForUpdate on stock_levels or product

    $stockLevel = StockLevel::firstOrCreate([...]);  // Race condition!

    $currentQty = (float) $stockLevel->quantity;
    $currentCostPrice = (float) ($product->cost_price ?? 0);
    // ... calculations ...

    $movement = StockMovement::create([...]);
    $stockLevel->quantity = (string) $newQty;
    $stockLevel->save();  // Race condition!

    $product->cost_price = (string) $newAvgCost;
    $product->save();  // Race condition!
}
```

**Problem:** If two purchase orders are received simultaneously for the same product:
1. Both read the same `quantity` and `cost_price`
2. Both calculate based on stale data
3. One overwrites the other's changes
4. Result: Incorrect WAC and stock quantity

**Fix Required:**
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

### 2.2 LandedCostService - HIGH RISK

```php
// app/Modules/Inventory/Application/Services/LandedCostService.php

public function allocateCosts(Document $purchaseOrder): void
{
    // ❌ NO transaction - if loop fails mid-way, partial data

    $lines = $purchaseOrder->lines;
    foreach ($lines as $line) {
        $line->allocated_costs = (string) $allocatedCost;
        $line->landed_unit_cost = ...;
        $line->save();  // Could fail on any iteration
    }
}
```

**Problem:** If allocation fails on line 3 of 5, lines 1-2 have allocated costs but 3-5 don't.

---

## 3. Transaction Boundary Recommendations

### 3.1 Independent Operations (CORRECT - No Transaction Needed)

**Partner creation from Document form:**
```
[User clicks "Add New Customer" in Quote form]
    ↓
POST /api/partners  → Creates Partner (independent)
    ↓
[Returns partner_id to frontend]
    ↓
[User continues filling Quote form]
    ↓
POST /api/quotes  → Creates Quote with partner_id (transaction)
```

This is CORRECT because:
- Partner creation is a standalone operation
- If partner creation fails, no document is affected
- If document creation fails, partner still exists (acceptable)

### 3.2 Dependent Operations (MUST BE TRANSACTIONAL)

**Sales Order → Invoice Conversion:**
```
[User clicks "Convert to Invoice"]
    ↓
DocumentConversionService::convertOrderToInvoice()
    ├── Create Invoice document
    ├── Copy lines
    ├── Transfer prepayments
    ├── Update SO status
    ├── Create GL entry
    └── All in one DB::transaction ✅
```

**Purchase Order Receiving:**
```
[User clicks "Receive Goods"]
    ↓
Should be:
    ├── Lock stock levels (lockForUpdate)
    ├── Validate quantities
    ├── Create stock movements
    ├── Update stock levels
    ├── Update WAC on products
    ├── Update PO status
    ├── Create GL entry for inventory
    └── All in one DB::transaction
```

---

## 4. Current Flow Analysis

### 4.1 Sales Flow Transaction Map

```
Quote (Draft)
    │ [No stock impact - OK to have simple transaction]
    ↓
Quote (Confirmed)
    │ [No stock impact]
    ↓
Sales Order (Draft)
    │ [Should reserve stock here - NOT IMPLEMENTED]
    ↓
Sales Order (Confirmed)
    │ [Should lock reservation - NOT IMPLEMENTED]
    ↓
Delivery Note (Draft)
    │ [Created from SO]
    ↓
Delivery Note (Confirmed)
    │ [Should issue stock - NOT IMPLEMENTED]
    │ [Only creates fiscal hash chain currently]
    ↓
Invoice (Posted)
    │ [Creates GL entries]
    │ [NO stock interaction]
    ↓
Payment
    │ [Properly transactional with locking]
```

### 4.2 Purchase Flow Transaction Map

```
Purchase Order (Draft)
    │ [Add lines + additional costs]
    ↓
Purchase Order (Confirmed)
    │ [Landed costs allocated]
    │ [Currently: LandedCostService has no transaction!]
    ↓
Goods Receipt
    │ [NOT IMPLEMENTED - This is critical gap]
    │ [Should: Receive goods, update stock, update WAC]
    ↓
Supplier Invoice
    │ [Match to PO]
    │ [Create GL entries]
```

---

## 5. Files Reference

### Services Needing Fixes

| File | Fix Required |
|------|--------------|
| `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` | Add transaction + locking |
| `app/Modules/Inventory/Application/Services/LandedCostService.php` | Add transaction |
| `app/Modules/Document/Domain/Services/DeliveryNoteService.php` | Add stock movement call |

### Services Already Correct

| File | Notes |
|------|-------|
| `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` | Has transaction + locking |
| `app/Modules/Document/Domain/Services/DocumentPostingService.php` | Has transaction + locking |
| `app/Modules/Treasury/Application/Services/PaymentAllocationService.php` | Has transaction + locking |
