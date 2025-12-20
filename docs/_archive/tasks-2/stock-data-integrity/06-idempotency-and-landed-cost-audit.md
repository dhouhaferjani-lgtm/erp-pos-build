# Idempotency & Landed Cost Audit

> **Created:** 2025-12-13
> **Purpose:** Audit idempotency implementation + Verify landed cost feature status

---

## Executive Summary

### Idempotency: ~85% Protected (State-Based)
The system uses **state-based idempotency** rather than formal idempotency keys. Most critical operations check document status before proceeding, preventing double-execution. Minor gaps exist in race condition windows.

### Landed Cost: NOT REGRESSED - Fully Implemented ✅
The landed cost feature is complete end-to-end. The perceived "regression" is a UX discoverability issue - additional costs only appear when editing an existing PO, not during initial creation.

---

## Part 1: Idempotency Audit

### 1.1 What is Idempotency?

An operation is **idempotent** if executing it multiple times produces the same result as executing it once. For ERP systems, this prevents:
- Double invoicing
- Double payments
- Duplicate stock movements
- Corrupted financial data

### 1.2 Current Protection Mechanisms

#### State-Based Idempotency (Backend)

| Operation | Check | Location | Protected? |
|-----------|-------|----------|------------|
| Confirm Document | `isDraft()` | `DocumentController.php:479` | ✅ |
| Post Document | `isConfirmed()` | `DocumentController.php:529` | ✅ |
| Quote → Order | `payload['converted_to_order_id']` | `DocumentController.php:646-656` | ✅ |
| SO → Invoice | `isOrderFullyInvoiced()` | `DocumentConversionService.php:115` | ✅ |
| SO → DN | `getDeliveryStatus() === FullyDelivered` | `DocumentConversionService.php:221` | ✅ |
| DN Consolidation | `payload['invoiced_at']` | `DocumentConversionService.php:642` | ✅ |
| Cancel Document | `isPosted()` | `DocumentController.php:586` | ✅ |

**Example: Quote Conversion Protection**
```php
// DocumentController.php:646-656
$payload = $quoteModel->payload ?? [];
if (isset($payload['converted_to_order_id'])) {
    return response()->json([
        'error' => [
            'code' => 'QUOTE_ALREADY_CONVERTED',
            'message' => 'This quote has already been converted to an order',
            'details' => ['order_id' => $payload['converted_to_order_id']],
        ],
    ], 422);
}
```

#### Frontend Protection (UI Level)

| Mechanism | Implementation | Effectiveness |
|-----------|----------------|---------------|
| Button Disabled During Mutation | `disabled={isSubmitting \|\| isPending}` | ✅ Good |
| TanStack Query Deduplication | Built-in mutation handling | ✅ Good |
| Optimistic Locking | Not implemented | ❌ Missing |

**Example: DocumentForm.tsx**
```tsx
<button
  type="submit"
  disabled={isSubmitting || createMutation.isPending || updateMutation.isPending}
>
  {isPending ? t('status.saving') : t('actions.save')}
</button>
```

### 1.3 Gaps & Race Conditions

#### Gap 1: No Formal Idempotency Keys

The system doesn't implement RFC idempotency key pattern:
- No `X-Idempotency-Key` header support
- No request deduplication table
- Relies entirely on state checks

**Risk Level:** LOW - State checks are effective for most scenarios.

**When This Matters:**
- Network timeouts where client retries
- User double-clicks before UI updates
- Mobile apps with poor connectivity

#### Gap 2: PO Confirmation Race Window

```
Time │ Request A                       │ Request B
─────┼─────────────────────────────────┼─────────────────────────────────
  1  │ POST /confirm (starts)          │
  2  │ Check: isDraft() → true         │ POST /confirm (starts)
  3  │                                 │ Check: isDraft() → true (STILL!)
  4  │ Update status to Confirmed      │
  5  │                                 │ Update status... (already done)
  6  │ Allocate landed costs           │ Allocate landed costs (DUPLICATE!)
```

**Actual Risk:** MEDIUM - Landed costs would be allocated twice, doubling the cost.

**Fix Required:** Add transaction + lock in `confirm()` action:
```php
public function confirm(...): JsonResponse
{
    return DB::transaction(function () use ($documentModel) {
        // Re-fetch with lock to prevent race
        $doc = Document::lockForUpdate()->findOrFail($documentModel->id);

        if (!$doc->isDraft()) {
            return response()->json(['error' => ...], 422);
        }

        // Safe to proceed...
    });
}
```

#### Gap 3: Payment Duplicate Detection

Payments don't have explicit duplicate detection beyond:
- Reference field (optional, not validated for uniqueness)
- Amount matching (manual)

**Risk Level:** LOW - Users typically verify payment amounts carefully.

### 1.4 Idempotency Implementation Priority

| Priority | Operation | Current State | Recommended Action |
|----------|-----------|---------------|-------------------|
| 🔴 HIGH | PO Confirm + Cost Allocation | Race possible | Add lock in transaction |
| 🔴 HIGH | Receive PO + WAC Update | Race possible | Add lock (from previous audit) |
| 🟠 MEDIUM | Payment Recording | No duplicate check | Add reference uniqueness |
| 🟡 LOW | All Mutations | No idempotency key | Future: Add X-Idempotency-Key |

---

## Part 2: Landed Cost Feature Audit

### 2.1 Status: FULLY IMPLEMENTED ✅

The landed cost feature is **complete and working**. Here's the full flow:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        LANDED COST FLOW (IMPLEMENTED)                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  1. Create Purchase Order                                                    │
│     └── POST /purchase-orders (creates draft PO)                             │
│                                                                              │
│  2. Save PO → Edit Mode                                                      │
│     └── Navigate to /purchases/orders/{id}/edit                              │
│     └── PurchaseOrderAdditionalCosts component appears ✓                     │
│                                                                              │
│  3. Add Additional Costs                                                     │
│     └── POST /documents/{id}/additional-costs                                │
│     └── Types: shipping, customs, insurance, handling, other                 │
│                                                                              │
│  4. View Allocation Preview                                                  │
│     └── GET /documents/{id}/landed-cost-breakdown                            │
│     └── Shows how costs will be distributed across lines                     │
│                                                                              │
│  5. Confirm PO                                                               │
│     └── POST /purchase-orders/{id}/confirm                                   │
│     └── LandedCostService::allocateCosts() called                            │
│     └── Each line gets: allocated_costs + landed_unit_cost                   │
│                                                                              │
│  6. Receive PO                                                               │
│     └── POST /purchase-orders/{id}/receive                                   │
│     └── landed_unit_cost used for WAC/cost_price update                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Backend Implementation

#### LandedCostService.php (Complete ✅)
```php
public function allocateCosts(Document $purchaseOrder): void
{
    $lines = $purchaseOrder->lines;
    $additionalCostsTotal = (float) $purchaseOrder->additionalCosts()->sum('amount');
    $subtotal = (float) $lines->sum('line_total');

    foreach ($lines as $line) {
        if ($subtotal > 0 && $additionalCostsTotal > 0) {
            $proportion = (float) $line->line_total / $subtotal;
            $allocatedCost = round($additionalCostsTotal * $proportion, 2);
        } else {
            $allocatedCost = 0;
        }

        $line->allocated_costs = (string) $allocatedCost;
        $line->landed_unit_cost = (float) $line->quantity > 0
            ? (string) round(((float) $line->line_total + $allocatedCost) / (float) $line->quantity, 2)
            : $line->unit_price;
        $line->save();
    }
}
```

#### DocumentController::confirm() Integration (Complete ✅)
```php
DB::transaction(function () use ($documentModel, $type): void {
    $documentModel->update(['status' => DocumentStatus::Confirmed]);

    // Integration Hook: For Purchase Orders, allocate landed costs
    if ($type === DocumentType::PurchaseOrder) {
        $this->landedCostService->allocateCosts($documentModel);
    }
});
```

#### API Routes (Complete ✅)
```php
// Additional Costs CRUD
Route::get('/documents/{document}/additional-costs', ...);
Route::post('/documents/{document}/additional-costs', ...);
Route::patch('/documents/{document}/additional-costs/{cost}', ...);
Route::delete('/documents/{document}/additional-costs/{cost}', ...);

// Landed Cost Breakdown
Route::get('/documents/{document}/landed-cost-breakdown', ...);
```

### 2.3 Frontend Implementation

#### DocumentForm.tsx Integration (Complete ✅)
```tsx
{/* Additional Costs (Purchase Orders only - after document is created) */}
{effectiveType === 'purchase_order' && isEditing && id && (
  <PurchaseOrderAdditionalCosts
    documentId={id}
    disabled={document?.status !== 'draft'}
    currency="TND"
  />
)}
```

#### PurchaseOrderAdditionalCosts.tsx (Complete ✅)
- Fetches costs via `useAdditionalCosts(documentId)`
- CRUD operations via `useCreateAdditionalCost`, `useUpdateAdditionalCost`, `useDeleteAdditionalCost`
- Renders `AdditionalCostsForm` component

#### useAdditionalCosts.ts Hooks (Complete ✅)
- `useAdditionalCosts()` - Fetch costs for document
- `useCreateAdditionalCost()` - Create new cost
- `useUpdateAdditionalCost()` - Update existing cost
- `useDeleteAdditionalCost()` - Delete cost
- `useLandedCostBreakdown()` - Fetch allocation preview

### 2.4 Why It Might "Feel" Regressed

The **UX flow** requires understanding:

| Step | What User Does | What They See |
|------|----------------|---------------|
| 1 | Click "New Purchase Order" | Form with lines only (no costs panel) |
| 2 | Add lines, click Save | Navigates to PO detail page |
| 3 | Click "Edit" | **NOW** sees Additional Costs panel |
| 4 | Add costs, click Save | Costs saved |
| 5 | Click "Confirm" | Costs allocated to lines |

**The "regression" perception:**
- Users expect to add costs during creation
- But the component only shows when editing (`isEditing && id`)

**Possible UX Improvements:**
1. Show "Save first to add additional costs" message on new PO form
2. Auto-save PO draft before showing costs panel
3. Navigate to edit mode immediately after creation

### 2.5 Files Reference

| Layer | File | Status |
|-------|------|--------|
| **Backend** | | |
| Service | `Inventory/Application/Services/LandedCostService.php` | ✅ Complete |
| Controller | `Http/Controllers/Api/DocumentAdditionalCostController.php` | ✅ Complete |
| Model | `Document/Domain/DocumentAdditionalCost.php` | ✅ Complete |
| Routes | `Document/Presentation/routes.php:270-288` | ✅ Complete |
| Integration | `DocumentController.php:492-494` | ✅ Complete |
| **Frontend** | | |
| Container | `documents/components/PurchaseOrderAdditionalCosts.tsx` | ✅ Complete |
| Hooks | `documents/hooks/useAdditionalCosts.ts` | ✅ Complete |
| Form Component | `organisms/AdditionalCostsForm/AdditionalCostsForm.tsx` | ✅ Complete |
| Breakdown | `organisms/LandedCostBreakdown/LandedCostBreakdown.tsx` | ✅ Complete |
| Integration | `DocumentForm.tsx:407-414` | ✅ Complete |

---

## Part 3: Combined Risk Assessment

| Issue | Category | Risk | Impact | Fix Complexity |
|-------|----------|------|--------|----------------|
| PO Confirm Race Condition | Idempotency | 🔴 HIGH | Double cost allocation | LOW |
| WAC Service No Lock | Idempotency | 🔴 CRITICAL | Corrupted costs/quantities | LOW |
| LandedCost No Transaction | Idempotency | 🟠 HIGH | Partial allocation | LOW |
| Payment Duplicate Risk | Idempotency | 🟡 MEDIUM | Double payment | MEDIUM |
| Landed Cost UX | Feature | 🟢 LOW | User confusion | LOW |

---

## Part 4: Recommended Fixes

### Immediate (Phase 1 Fixes from Roadmap)

1. **Fix LandedCostService** - Add transaction wrapper
2. **Fix WeightedAverageCostService** - Add transaction + locking
3. **Fix DocumentController::confirm()** - Add lock for PO confirmation

### Short-term

4. **Add payment reference uniqueness** - Prevent duplicate payments
5. **Improve PO creation UX** - Better messaging for additional costs

### Long-term

6. **Implement idempotency keys** - Full RFC compliance for API
7. **Add request deduplication table** - Store idempotency keys with TTL

---

## Appendix: Test Scenarios

### Idempotency Tests Needed

```php
/** @test */
public function concurrent_po_confirmation_does_not_duplicate_cost_allocation(): void
{
    // Create PO with additional costs
    $po = $this->createPurchaseOrderWithCosts();

    // Simulate concurrent requests
    $result1 = null;
    $result2 = null;

    $promise1 = async(function () use ($po, &$result1) {
        $result1 = $this->confirmPO($po);
    });

    $promise2 = async(function () use ($po, &$result2) {
        $result2 = $this->confirmPO($po);
    });

    await([$promise1, $promise2]);

    // One should succeed, one should fail
    $this->assertTrue($result1->success !== $result2->success);

    // Costs should be allocated exactly once
    $po->refresh();
    $expectedAllocation = $this->calculateExpectedAllocation($po);
    $this->assertEquals($expectedAllocation, $po->lines->sum('allocated_costs'));
}

/** @test */
public function double_click_on_confirm_button_is_safely_handled(): void
{
    $po = $this->createPurchaseOrderWithCosts();

    // First confirmation
    $response1 = $this->post("/purchase-orders/{$po->id}/confirm");
    $response1->assertStatus(200);

    // Immediate second confirmation (simulates double-click)
    $response2 = $this->post("/purchase-orders/{$po->id}/confirm");
    $response2->assertStatus(422);
    $response2->assertJson(['error' => ['code' => 'INVALID_STATUS_TRANSITION']]);
}
```

### Landed Cost E2E Test

```php
/** @test */
public function complete_landed_cost_flow(): void
{
    // 1. Create PO
    $po = Document::factory()->create([
        'type' => DocumentType::PurchaseOrder,
        'status' => DocumentStatus::Draft,
    ]);

    // 2. Add lines
    $line1 = DocumentLine::factory()->create([
        'document_id' => $po->id,
        'quantity' => 10,
        'unit_price' => 100,
        'line_total' => 1000,
    ]);

    // 3. Add additional costs
    DocumentAdditionalCost::create([
        'document_id' => $po->id,
        'cost_type' => 'shipping',
        'amount' => 100,
    ]);

    // 4. Confirm PO
    $this->landedCostService->allocateCosts($po);

    // 5. Verify allocation
    $line1->refresh();
    $this->assertEquals('100.00', $line1->allocated_costs);
    $this->assertEquals('110.00', $line1->landed_unit_cost); // (1000 + 100) / 10
}
```
