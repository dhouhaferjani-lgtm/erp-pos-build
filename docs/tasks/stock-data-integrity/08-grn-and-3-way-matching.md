# Goods Receipt Note (GRN) and 3-Way Matching

> **Created:** 2025-12-13
> **Purpose:** Document industry standards for purchase flow and implementation recommendation

---

## Industry Standard: 3-Way Matching

The Goods Receipt Note (GRN) is a foundational control point in the Procure-to-Pay (P2P) cycle, linking procurement intent (PO), physical fulfillment (delivery), and financial settlement (invoice matching and payment).

### What is 3-Way Matching?

Three documents must align before payment is issued:

| Document | Purpose | Created By |
|----------|---------|------------|
| **Purchase Order (PO)** | Intent to purchase | Buyer |
| **Goods Receipt Note (GRN)** | Confirmation of delivery | Warehouse |
| **Supplier Invoice** | Request for payment | Supplier |

```
┌─────────────────────────────────────────────────────────────────┐
│                    3-WAY MATCHING FLOW                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   [Purchase Order]                                               │
│        │                                                         │
│        ├── Items ordered                                         │
│        ├── Quantities                                            │
│        ├── Agreed prices                                         │
│        └── Additional costs (freight, customs)                   │
│        │                                                         │
│        ↓                                                         │
│   [Goods Receipt Note]                                           │
│        │                                                         │
│        ├── Items received                                        │
│        ├── Actual quantities                                     │
│        ├── Quality inspection results                            │
│        └── Additional costs discovered                           │
│        │                                                         │
│        ↓                                                         │
│   [Supplier Invoice]                                             │
│        │                                                         │
│        ├── Billed items                                          │
│        ├── Billed quantities                                     │
│        └── Prices charged                                        │
│        │                                                         │
│        ↓                                                         │
│   [3-Way Match Check]                                            │
│        │                                                         │
│        ├── PO qty ≥ GRN qty ≥ Invoice qty?                       │
│        ├── Prices match within tolerance?                        │
│        └── Items match?                                          │
│        │                                                         │
│        ↓                                                         │
│   [Payment Authorized]                                           │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Why It Matters (Statistics)

- **79% of businesses** experienced payment fraud in 2024
- Businesses lost an average of **$145,000** to payment fraud
- **45% of businesses** still rely on manual processes for goods receipt
- Organizations with automated GRNs are **30% more likely** to pass procurement audits

---

## Recommendation for AutoERP

### Option A: Separate GRN Document Type (RECOMMENDED)

Add a new `GoodsReceiptNote` document type that:

1. **Lists confirmed POs** ready for receiving
2. **Shows PO details** with items, quantities, prices, and additional costs
3. **Allows adding more costs** discovered during receiving (e.g., inspection fees, handling)
4. **Captures receipt details** (received qty, condition, notes)
5. **Updates WAC** using landed cost when confirmed
6. **Links to supplier invoice** for 3-way matching

```
┌─────────────────────────────────────────────────────────────────┐
│                    PROPOSED PURCHASE FLOW                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   [PO Draft]                                                     │
│        │                                                         │
│        ├── Add lines (items + quantities + prices)               │
│        │                                                         │
│        ↓                                                         │
│   [PO Saved] ← Auto-redirect to edit mode                        │
│        │                                                         │
│        ├── Add additional costs (freight, customs, insurance)    │
│        │                                                         │
│        ↓                                                         │
│   [PO Confirmed]                                                 │
│        │                                                         │
│        ├── Allocate landed costs to lines (initial estimate)     │
│        ├── Additional costs STILL EDITABLE until received        │
│        │                                                         │
│        ↓                                                         │
│   [Goods Receipt Note Created]                                   │
│        │                                                         │
│        ├── Pre-populated from PO                                 │
│        ├── Add/modify additional costs                           │
│        ├── Enter received quantities                             │
│        ├── Note any discrepancies                                │
│        │                                                         │
│        ↓                                                         │
│   [GRN Confirmed]                                                │
│        │                                                         │
│        ├── Re-allocate final landed costs                        │
│        ├── Update WAC for products                               │
│        ├── Increment stock levels                                │
│        ├── Create stock movements                                │
│        │                                                         │
│        ↓                                                         │
│   [Supplier Invoice Received]                                    │
│        │                                                         │
│        ├── Match to PO/GRN (3-way match)                         │
│        ├── Flag discrepancies                                    │
│        │                                                         │
│        ↓                                                         │
│   [Payment Authorized]                                           │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Why Separate GRN?

| Benefit | Explanation |
|---------|-------------|
| **Clear Separation of Duties** | Purchasing team creates PO; Warehouse confirms receipt |
| **Audit Trail** | Distinct document for when goods physically arrived |
| **Flexibility** | Partial receipts over multiple days/shipments |
| **Cost Accuracy** | Final costs known only at receipt time |
| **Compliance** | Standard in regulated industries |
| **3-Way Matching** | Required for proper invoice verification |

### Alternative: Enhanced PO Status (Simpler but Limited)

Keep PO as single document with status progression:

```
Draft → Confirmed → Partially Received → Received → Invoiced
```

**Pros:** Simpler, fewer documents
**Cons:**
- No separate audit trail for receiving
- Harder to handle partial receipts
- Less flexibility for cost adjustments
- No clear separation of duties

---

## Implementation Plan

### Phase 1: Critical Fixes (Current Session)

1. **Fix WeightedAverageCostService** - Add transaction + locking
2. **Fix LandedCostService** - Add transaction wrapper
3. **Allow cost edits on confirmed PO** - Until goods received

### Phase 2: GRN Document Type

1. Add `GoodsReceiptNote` to `DocumentType` enum
2. Create `GoodsReceiptService` with:
   - `createFromPO(purchaseOrder)` - Pre-populate from PO
   - `addAdditionalCost()` - Add costs discovered during receiving
   - `confirm()` - Finalize, update WAC, increment stock
3. Add `quantity_received` to `document_lines`
4. Create GRN UI components:
   - List of confirmed POs ready for receiving
   - GRN form with editable costs and quantities
   - Discrepancy notes field

### Phase 3: 3-Way Matching (Future)

1. Link Supplier Invoice to PO/GRN
2. Auto-match quantities and prices
3. Flag discrepancies with tolerance settings
4. Payment authorization workflow

---

## UX Improvements for PO

### Immediate (This Session)

1. **Auto-redirect to edit after save** - So users see additional costs section
2. **Allow cost edits on confirmed PO** - Until goods received (GRN confirmed)
3. **Show info message** - "Additional costs can be added until goods are received"

### Code Changes Required

**1. DocumentForm.tsx - Auto-redirect after creation:**
```tsx
// After successful creation, redirect to edit mode
if (isNewDocument && result.id) {
  navigate(`/purchases/orders/${result.id}/edit`);
}
```

**2. DocumentController.php - Allow cost edits on confirmed PO:**
```php
// In additional cost routes, check:
// - PO is not yet 'received' status
// - OR if GRN exists, GRN is not confirmed
```

**3. PurchaseOrderAdditionalCosts.tsx - Update disabled logic:**
```tsx
// Change from:
disabled={document?.status !== 'draft'}
// To:
disabled={document?.status === 'received' || document?.payload?.goods_received}
```

---

## French Terminology Reference

| English | French | Abbreviation |
|---------|--------|--------------|
| Purchase Order | Bon de Commande | BC |
| Goods Receipt Note | Bon de Réception | BR / BE |
| Delivery Note (outbound) | Bon de Livraison | BL |
| Supplier Invoice | Facture Fournisseur | - |
| 3-Way Matching | Rapprochement Tripartite | - |

---

## Sources

- [Goods Receipt: Definition, Process & 3-Way Matching](https://ramp.com/blog/goods-receipt)
- [What Is Goods Received Note (GRN): Importance & Best Practices](https://www.highradius.com/resources/Blog/goods-received-note/)
- [Guide to Goods Received Note (GRN) in Procurement](https://www.zycus.com/blog/source-to-pay/goods-received-note-procurement)
- [Goods Received Note: Meaning, Importance, and Uses](https://tipalti.com/resources/learn/goods-received-note-explained/)
- [What is a Goods Received Note? Meaning, Importance & Implementation](https://www.gep.com/blog/strategy/goods-received-note-meaning-importance-implementation)
