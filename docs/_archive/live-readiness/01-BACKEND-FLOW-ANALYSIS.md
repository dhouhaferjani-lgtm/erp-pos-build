# Backend Business Flow Analysis

> Complete analysis of Purchase → Sales → Returns → Payments flow

---

## Executive Summary

| Flow | Completeness | Key Status |
|------|--------------|-----------|
| **Sales/Document** | 95% | Quote→Order→DN→Invoice→Payment complete |
| **Purchase** | 70% | PO receipt complete; PO→Invoice missing |
| **Inventory** | 85% | Movements, WAC, landed cost complete |
| **Accounting GL** | 90% | Entry templates excellent; COGS incomplete |
| **Compliance** | 100% | Fiscal hash chain fully operational |

---

## 1. Sales Document Flow

### Quote → Sales Order → Delivery Note → Invoice → Credit Note

#### Quote to Sales Order ✅ COMPLETE
**Service**: `DocumentConversionService::convertQuoteToOrder()`

- Validates quote is confirmed and not expired
- Creates new sales order with fresh document number
- Copies all lines from quote
- Marks quote as converted with timestamp

#### Sales Order to Delivery Note ✅ COMPLETE
**Services**:
- `DocumentConversionService::convertOrderToDelivery()` - Full delivery
- `DocumentConversionService::convertOrderToPartialDelivery()` - Partial delivery

**Features**:
- Full and partial deliveries supported
- Links delivery lines to source order lines via `source_line_id`
- Updates `quantity_delivered` on source lines
- Tunisia-specific: DN gets fiscal hash on CONFIRM (not POST)

#### Sales Order/Delivery Note to Invoice ✅ COMPLETE
**Services**:
- `DocumentConversionService::convertOrderToInvoice()` - Direct conversion
- `DocumentConversionService::createInvoiceFromDeliveryNotes()` - DN consolidation

**Scenarios Handled**:
- **Services only**: Direct invoicing without DN requirement
- **Products only/mixed**: Requires delivery notes (Tunisia compliance)
- **Prepayment transfer**: Automatically moves acomptes from order to invoice

#### Invoice Posting with Fiscal Hash ✅ COMPLETE
**Service**: `DocumentPostingService::post()`

```
Hash = SHA256(previous_hash | document_number | date | total | currency)
```

- Genesis seed per company (better than ZATCA's fixed "0")
- Separate chains per [company_id, document_type]
- Sets `fiscal_status = "Sealed"`

#### Credit Notes ✅ COMPLETE
**Service**: `RefundService::createFullCreditNote()`

- Creates reverse GL entries
- Links to source invoice
- Tracks `fully_credited` flag
- Records cancellation reason

---

## 2. Purchase Flow

### Purchase Order → Goods Receipt → (Purchase Invoice)

#### Purchase Order Creation ✅ COMPLETE
- Basic CRUD via DocumentController
- Document numbering (PO prefix)
- Confirm transition implemented

#### Goods Receipt ✅ COMPLETE
**Service**: `GoodsReceiptService::receiveGoods()`

- Partial goods receipt with line-level tracking
- Updates `quantity_received` on PO lines
- Integrates WeightedAverageCostService
- Reallocates landed costs before receipt
- Skips non-physical products (services)

**Methods**:
- `receiveGoods()` - Partial receipt
- `receiveAll()` - Full receipt
- `getReceiptStatus()` - Progress tracking

#### Landed Cost Allocation ✅ COMPLETE
**Service**: `LandedCostService`

```php
proportion = line_total / subtotal
allocated_cost = additional_costs_total * proportion
landed_unit_cost = (line_total + allocated_cost) / quantity
```

- Proportional allocation by line value
- Updates `allocated_costs` and `landed_unit_cost` per line
- Prevents modification post-receipt

#### ❌ CRITICAL GAP: Purchase Invoice Conversion
**Status**: NOT IMPLEMENTED

**Missing**:
1. `DocumentConversionService::convertPurchaseOrderToInvoice()`
2. Purchase Invoice document type handling
3. Supplier GL entry (Dr. Expense/Inventory, Cr. AP)
4. Goods receipt verification before invoicing

**Impact**: Cannot create purchase invoices systematically

#### ❌ MISSING: Supplier Returns
- No purchase return handling
- No reverse GL for returned goods
- No supplier credit note support

---

## 3. Inventory Flow

### Stock Movement Types ✅ COMPLETE
**Enum**: `MovementType`

| Type | Direction | Usage |
|------|-----------|-------|
| Receipt | Inbound | Purchase receipt |
| Issue | Outbound | Sales/usage |
| TransferIn | Inbound | Stock transfer in |
| TransferOut | Outbound | Stock transfer out |
| Adjustment | Either | Inventory adjustment |
| Opening | Inbound | Opening balance import |

### Weighted Average Cost (WAC) ✅ COMPLETE
**Service**: `WeightedAverageCostService`

- `recordPurchase()` - Updates stock and product cost_price
- `recordSale()` - Uses current WAC for valuation
- `recordReturn()` - Re-enters stock at original cost
- Pessimistic locking prevents race conditions

### ⚠️ COGS Posting INCOMPLETE
**Status**: Account exists, automation missing

- `SystemAccountPurpose::CostOfGoodsSold` defined
- No automatic posting on invoice confirmation
- No event listener triggers COGS entry

**Needed**:
```php
public function createCogsEntry(
    string $companyId,
    string $invoiceId,
    string $cogsAmount,
    DateTimeInterface $date
): JournalEntry {
    // Dr. COGS, Cr. Inventory
}
```

---

## 4. Accounting Integration

### GL Entry Generation ✅ EXCELLENT
**Service**: `GeneralLedgerService`

| Operation | Debit | Credit |
|-----------|-------|--------|
| Invoice Posting | AR (411) | Revenue (701), VAT (4457) |
| Credit Note | Revenue, VAT | AR |
| Customer Advance | Bank/Cash | Customer Advances (419) |
| Payment Received | Bank/Cash | AR |
| Supplier Invoice | Expense, VAT | AP (401) |
| Supplier Payment | AP | Bank/Cash |
| Payment Tolerance | Tolerance Expense (658) | AR |
| Prepayment Clear | Customer Advances (419) | AR |

### Account Lookup Strategy ✅ COMPLETE
**Method**: `getAccountByPurpose()`

Uses `SystemAccountPurpose` enum instead of hardcoded account codes:
- CustomerReceivable, CustomerAdvance
- SupplierPayable, SupplierAdvance
- VatCollected, VatDeductible
- ProductRevenue, ServiceRevenue
- Bank, Cash
- OpeningBalanceEquity, CostOfGoodsSold

**Benefit**: Country-agnostic - works for any chart of accounts

---

## 5. Event System

### Implemented Domain Events

| Event | Trigger | Fiscal? |
|-------|---------|---------|
| InvoicePosted | Invoice posting | Yes |
| InvoiceCancelled | Invoice cancellation | Yes |
| InvoicePaid | Full payment received | No |
| DeliveryNoteConfirmed | DN confirmation | Yes |
| PaymentRecorded | Payment recording | Yes* |

### Event Subscriber
**Class**: `DomainEventSubscriber`

- Persists all events to `audit_events` table
- Non-blocking (audit failure doesn't break operations)
- Records company_id, occurred_at, full payload

---

## 6. Gap Summary

### Critical (Must Fix)

| Feature | Impact | Effort |
|---------|--------|--------|
| PO → Purchase Invoice | Cannot process supplier invoices | 8h |
| COGS posting automation | Inventory not reduced on sales | 4h |
| Supplier credit notes | Cannot handle returns | 6h |

### High Priority

| Feature | Impact | Effort |
|---------|--------|--------|
| Document conversion events | Quote→Order not audited | 4h |
| Stock movement source linking | Can't trace invoice→stock | 2h |

### Medium Priority

| Feature | Impact | Effort |
|---------|--------|--------|
| Multi-currency support | Single currency only | 16h |
| FIFO/LIFO costing | WAC only | 12h |
| Payment tolerance threshold | Manual override needed | 2h |

---

## 7. Testing Checklist

### Sales Flow
- [x] Quote creation/confirmation
- [x] Quote to Sales Order conversion
- [x] Sales Order to Delivery Note (full + partial)
- [x] Sales Order to Invoice (direct or via DN)
- [x] Invoice posting with fiscal hash
- [x] Credit note creation
- [x] Payment allocation with tolerance
- [x] Prepayment transfer

### Purchase Flow
- [x] Purchase Order creation/confirmation
- [x] Goods Receipt (full + partial)
- [x] Landed Cost Allocation
- [x] WAC updates on receipt
- [ ] PO to Purchase Invoice ❌
- [ ] Supplier credit notes ❌
- [ ] Purchase returns ❌

### Inventory
- [x] Stock movements (6 types)
- [x] Stock level per location
- [x] Opening balance import
- [x] WAC calculation
- [ ] COGS posting on sale ❌

### Accounting
- [x] AR GL entries
- [x] AP GL entry templates
- [x] Journal entry creation
- [x] Hash chain verification
- [x] Opening balance GL entries
- [ ] Automatic COGS workflow ❌

---

**File**: `docs/live-readiness/01-BACKEND-FLOW-ANALYSIS.md`
**Generated**: 2025-12-13
