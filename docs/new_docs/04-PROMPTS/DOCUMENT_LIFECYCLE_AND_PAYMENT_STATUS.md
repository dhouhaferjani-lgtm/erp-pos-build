# Document Lifecycle & Payment Status Specification

**Module:** Sales, Treasury, Accounting Integration  
**Version:** 1.0  
**Date:** January 2026  
**Status:** Specification for Implementation

---

## Executive Summary

This document defines the complete lifecycle of commercial documents (quotes, orders, delivery notes, invoices) and their relationship with payment status tracking. It establishes the fundamental principle that **document status** and **payment status** are independent dimensions that must be tracked separately.

### Key Principles

1. **Immutability**: Posted invoices are immutable; corrections require credit notes
2. **Separation of Concerns**: Document lifecycle ≠ Payment lifecycle ≠ Fulfillment lifecycle
3. **Derived Status**: Payment status is calculated, not stored on the invoice
4. **Event Sourcing**: All state changes are events with cryptographic hash chains for fiscal documents

---

## Table of Contents

1. [The Three Status Dimensions](#1-the-three-status-dimensions)
2. [Document Types and Flow](#2-document-types-and-flow)
3. [Document Status Lifecycle](#3-document-status-lifecycle)
4. [Payment Status Lifecycle](#4-payment-status-lifecycle)
5. [Fulfillment Status Lifecycle](#5-fulfillment-status-lifecycle)
6. [Payment Allocation Model](#6-payment-allocation-model)
7. [Credit Notes and Corrections](#7-credit-notes-and-corrections)
8. [Business Scenarios](#8-business-scenarios)
9. [UI/UX Requirements](#9-uiux-requirements)
10. [Audit Checklist](#10-audit-checklist)
11. [Implementation Tasks](#11-implementation-tasks)
12. [Related Conversations](#12-related-conversations)

---

## 1. The Three Status Dimensions

Every sales document has **three independent status dimensions**:

### 1.1 Document Status (Lifecycle State)

Tracks where the document is in its approval/posting workflow.

| Status | Description | Editable | Accounting Impact | Hash Chain |
|--------|-------------|----------|-------------------|------------|
| `draft` | Being prepared | ✅ Yes | ❌ None | ❌ No |
| `confirmed` | Approved, pending posting | ⚠️ Limited | ❌ None | ❌ No |
| `posted` | Finalized, fiscal document | ❌ No | ✅ Journal entries created | ✅ Yes |
| `cancelled` | Voided (with reversal) | ❌ No | ✅ Reversal entries | ✅ Yes |

### 1.2 Payment Status (Financial Settlement)

Tracks whether money has been received/paid. **This is CALCULATED, not stored.**

| Status | Condition | Visual Indicator |
|--------|-----------|------------------|
| `unpaid` | Outstanding = Total (no payments) | 🔴 Red |
| `partially_paid` | 0 < Outstanding < Total | 🟡 Yellow/Orange |
| `in_payment` | Payment registered, pending bank reconciliation | 🔵 Blue |
| `paid` | Outstanding = 0 | 🟢 Green |
| `overpaid` | Outstanding < 0 (customer has credit) | 🟣 Purple |

**Calculation:**
```
Outstanding Amount = Invoice Total - SUM(Allocated Payments) - SUM(Credit Note Amounts)
Payment Status = CASE
    WHEN Outstanding = Total AND no payments registered THEN 'unpaid'
    WHEN Outstanding = Total AND payments pending reconciliation THEN 'in_payment'
    WHEN Outstanding > 0 THEN 'partially_paid'
    WHEN Outstanding = 0 THEN 'paid'
    WHEN Outstanding < 0 THEN 'overpaid'
END
```

### 1.3 Fulfillment Status (Delivery)

Tracks whether goods have been shipped or services rendered.

| Status | Condition |
|--------|-----------|
| `not_delivered` | No delivery notes confirmed |
| `partially_delivered` | Some items delivered |
| `fully_delivered` | All items delivered |
| `returned` | Items returned (return note exists) |

**Note:** For service-only invoices, fulfillment status may not apply or auto-set to `fulfilled`.

---

## 2. Document Types and Flow

### 2.1 Sales Document Types

| Document | Code | Purpose | Creates Journal Entry | Affects Inventory |
|----------|------|---------|----------------------|-------------------|
| Quote | `QUO` | Non-binding proposal | ❌ No | ❌ No |
| Sales Order | `SO` | Customer commitment | ❌ No | ⚠️ Reserves only |
| Delivery Note | `DN` | Goods shipped | ✅ COGS entry | ✅ Decreases stock |
| Invoice | `INV` | Financial obligation | ✅ AR + Revenue | ⚠️ If Update Stock enabled |
| Credit Note | `CN` | Reversal/correction | ✅ Reverse AR | ⚠️ If includes return |
| Payment | `PAY` | Cash receipt | ✅ Cash + AR | ❌ No |

### 2.2 Standard Document Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           SALES DOCUMENT FLOW                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   ┌─────────┐      ┌─────────────┐      ┌──────────────┐      ┌─────────┐  │
│   │  Quote  │─────▶│ Sales Order │─────▶│ Delivery Note│─────▶│ Invoice │  │
│   │(Optional)│      │ (Optional)  │      │  (Optional)  │      │(Required)│  │
│   └─────────┘      └─────────────┘      └──────────────┘      └────┬────┘  │
│                                                                     │       │
│                                          ┌──────────────────────────┘       │
│                                          ▼                                  │
│                                    ┌───────────┐      ┌─────────────┐       │
│                                    │ Payment(s)│─────▶│ Reconciled  │       │
│                                    └───────────┘      └─────────────┘       │
│                                                                             │
│   If correction needed:                                                     │
│                                                                             │
│   ┌─────────┐      ┌─────────────┐                                          │
│   │ Invoice │─────▶│ Credit Note │─────▶ (New Invoice if needed)            │
│   └─────────┘      └─────────────┘                                          │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.3 Flexible Paths

The system should support multiple paths based on business needs:

| Scenario | Path | Use Case |
|----------|------|----------|
| Full Flow | Quote → Order → Delivery → Invoice → Payment | B2B with complex fulfillment |
| Direct Sale | Invoice → Payment | POS/Retail |
| Service Sale | Quote → Invoice → Payment | Service businesses |
| Prepaid | Order → Payment → Delivery → Invoice | E-commerce with advance payment |
| COD | Order → Delivery + Invoice + Payment | Cash on delivery |

---

## 3. Document Status Lifecycle

### 3.1 State Machine

```
                    ┌─────────────────────────────────────────┐
                    │                                         │
                    ▼                                         │
┌────────┐    ┌───────────┐    ┌────────┐    ┌───────────┐   │
│ Draft  │───▶│ Confirmed │───▶│ Posted │───▶│ Cancelled │   │
└────────┘    └───────────┘    └────────┘    └───────────┘   │
     │              │                              ▲          │
     │              │                              │          │
     └──────────────┴──────────────────────────────┘          │
                    (Can cancel before posting)               │
                                                              │
                    ┌─────────────────────────────────────────┘
                    │ Cancellation creates reversal entry
                    │ and maintains audit trail
```

### 3.2 Status Transitions

| From | To | Action | Validation | Side Effects |
|------|-----|--------|------------|--------------|
| `draft` | `confirmed` | `confirm()` | All required fields present | Locks most fields |
| `draft` | `cancelled` | `cancel()` | None | Document marked void |
| `confirmed` | `posted` | `post()` | Customer valid, lines exist | Creates journal entry, assigns number, adds to hash chain |
| `confirmed` | `draft` | `revert()` | No downstream documents | Unlocks for editing |
| `confirmed` | `cancelled` | `cancel()` | No downstream documents | Document marked void |
| `posted` | `cancelled` | `cancel()` | **Only if unpaid** | Creates reversal journal entry |

### 3.3 Immutability Rules

Once a document is **posted**:

| Field Category | Can Change? | How to Correct |
|----------------|-------------|----------------|
| Line items (products, qty, prices) | ❌ Never | Credit Note |
| Totals and taxes | ❌ Never | Credit Note |
| Customer information | ❌ Never | Credit Note + new invoice |
| Document number | ❌ Never | N/A (sequential, never reused) |
| Document date | ❌ Never | Credit Note + new invoice |
| Payment terms | ❌ Never | Credit Note + new invoice |
| Internal notes | ✅ Yes | Direct edit |
| Tags/categories | ✅ Yes | Direct edit |
| Attachments | ✅ Yes | Add/remove |

---

## 4. Payment Status Lifecycle

### 4.1 Key Principle: Payment Status is Derived

**The payment status is NOT stored on the invoice.** It is calculated from:

1. Invoice total amount
2. Sum of allocated payments
3. Sum of applied credit notes
4. Bank reconciliation status (if using outstanding accounts)

### 4.2 Payment Workflow (With Bank Reconciliation)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         PAYMENT STATUS FLOW                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   Invoice Posted                                                            │
│        │                                                                    │
│        ▼                                                                    │
│   ┌─────────┐    Register Payment    ┌────────────┐                        │
│   │ UNPAID  │───────────────────────▶│ IN PAYMENT │                        │
│   │         │                        │            │                        │
│   └─────────┘                        └─────┬──────┘                        │
│                                            │                                │
│                                   Bank Reconciliation                       │
│                                            │                                │
│                                            ▼                                │
│                                    ┌─────────────┐                          │
│                                    │    PAID     │                          │
│                                    │             │                          │
│                                    └─────────────┘                          │
│                                                                             │
│   Alternative: Direct Payment (POS mode, no bank reconciliation)           │
│                                                                             │
│   Invoice Posted ──▶ Payment Registered ──▶ PAID (immediately)             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 4.3 Payment Status Calculation Logic

```php
// Pseudo-code for payment status calculation
public function getPaymentStatus(): PaymentStatus
{
    $total = $this->total_amount;
    $paid = $this->allocatedPayments()->sum('amount');
    $creditNotes = $this->creditNotes()->posted()->sum('total_amount');
    $outstanding = $total - $paid - $creditNotes;
    
    // Check if any payments are pending bank reconciliation
    $pendingReconciliation = $this->allocatedPayments()
        ->whereHas('payment', fn($q) => $q->where('reconciled', false))
        ->exists();
    
    return match(true) {
        $outstanding < 0 => PaymentStatus::OVERPAID,
        $outstanding == 0 => PaymentStatus::PAID,
        $outstanding == $total && $pendingReconciliation => PaymentStatus::IN_PAYMENT,
        $outstanding == $total => PaymentStatus::UNPAID,
        default => PaymentStatus::PARTIALLY_PAID,
    };
}
```

### 4.4 Configuration Options

| Setting | Description | Default |
|---------|-------------|---------|
| `require_bank_reconciliation` | Use "In Payment" status until bank confirmed | `true` for B2B, `false` for POS |
| `allow_partial_payments` | Accept payments less than invoice total | `true` |
| `allow_overpayments` | Accept payments exceeding invoice total | `true` |
| `auto_apply_customer_credit` | Automatically apply customer credit balance | `false` |

---

## 5. Fulfillment Status Lifecycle

### 5.1 Delivery Note States

```
┌─────────┐    Confirm    ┌───────────┐
│  Draft  │──────────────▶│ Confirmed │
└─────────┘               └─────┬─────┘
                                │
                                ▼
                          Stock Deducted
                          COGS Entry Created
```

### 5.2 Invoice Fulfillment Status Calculation

```php
public function getFulfillmentStatus(): FulfillmentStatus
{
    // Service-only invoice
    if ($this->hasOnlyServices()) {
        return FulfillmentStatus::NOT_APPLICABLE;
    }
    
    $totalQty = $this->lines()->sum('quantity');
    $deliveredQty = $this->deliveryNotes()
        ->confirmed()
        ->join('delivery_note_lines', ...)
        ->sum('quantity');
    $returnedQty = $this->returnNotes()
        ->confirmed()
        ->sum('quantity');
    
    $netDelivered = $deliveredQty - $returnedQty;
    
    return match(true) {
        $netDelivered <= 0 => FulfillmentStatus::NOT_DELIVERED,
        $netDelivered < $totalQty => FulfillmentStatus::PARTIALLY_DELIVERED,
        $netDelivered >= $totalQty => FulfillmentStatus::FULLY_DELIVERED,
    };
}
```

### 5.3 Auto-Create Delivery Note Option

For businesses that don't track delivery separately:

| Setting | Behavior |
|---------|----------|
| `auto_create_delivery_on_invoice_post` | Creates and confirms delivery note when invoice is posted |
| `update_stock_on_invoice` | Bypass delivery note entirely (POS mode) |

---

## 6. Payment Allocation Model

### 6.1 Data Model

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         PAYMENT ALLOCATION MODEL                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   ┌────────────┐         ┌─────────────────────┐         ┌──────────────┐  │
│   │  Payment   │────────▶│ Payment Allocation  │◀────────│   Invoice    │  │
│   │            │    1:N  │                     │   N:1   │              │  │
│   └────────────┘         └─────────────────────┘         └──────────────┘  │
│                                                                             │
│   Payment                  PaymentAllocation              Invoice           │
│   ────────                 ─────────────────              ───────           │
│   id                       id                             id                │
│   partner_id               payment_id (FK)                partner_id        │
│   amount                   invoice_id (FK)                total_amount      │
│   payment_date             amount_allocated               ...               │
│   payment_method_id        allocated_at                                     │
│   reconciled               allocated_by                                     │
│   ...                                                                       │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 6.2 Scenarios

#### Scenario A: One Payment → One Invoice

```
Payment #PAY-001: €1,500
    └── Allocation: €1,500 → Invoice #INV-001 (€1,500)

Invoice #INV-001: Total €1,500, Outstanding €0 → PAID
```

#### Scenario B: One Payment → Multiple Invoices

```
Payment #PAY-002: €2,500
    ├── Allocation: €1,000 → Invoice #INV-002 (€1,000)
    └── Allocation: €1,500 → Invoice #INV-003 (€1,500)

Invoice #INV-002: Total €1,000, Outstanding €0 → PAID
Invoice #INV-003: Total €1,500, Outstanding €0 → PAID
```

#### Scenario C: Multiple Payments → One Invoice

```
Payment #PAY-003: €500
    └── Allocation: €500 → Invoice #INV-004

Payment #PAY-004: €1,000
    └── Allocation: €1,000 → Invoice #INV-004

Invoice #INV-004: Total €1,500, Outstanding €0 → PAID
```

#### Scenario D: Partial Payment

```
Payment #PAY-005: €800
    └── Allocation: €800 → Invoice #INV-005 (€1,200)

Invoice #INV-005: Total €1,200, Outstanding €400 → PARTIALLY_PAID
```

#### Scenario E: Overpayment

```
Payment #PAY-006: €2,000
    └── Allocation: €1,500 → Invoice #INV-006 (€1,500)
    └── Unallocated: €500 (Customer Credit)

Invoice #INV-006: Total €1,500, Outstanding €0 → PAID
Customer Balance: €500 credit
```

#### Scenario F: Unallocated Payment (Payment on Account)

```
Payment #PAY-007: €3,000
    └── No allocation (or partial allocation)

Customer Balance: €3,000 credit (available for future invoices)
```

### 6.3 Payment Allocation Rules

1. **One payment can allocate to multiple invoices** (batch payment)
2. **Multiple payments can allocate to one invoice** (installments)
3. **Unallocated amounts become customer credit** (on account)
4. **Allocations are always positive amounts**
5. **Total allocations cannot exceed payment amount**
6. **Total allocations to an invoice cannot exceed invoice total** (unless overpayment allowed)

---

## 7. Credit Notes and Corrections

### 7.1 When to Use Credit Notes

| Situation | Solution | Stock Impact |
|-----------|----------|--------------|
| Wrong amount charged | Credit Note (partial) | Optional |
| Wrong items on invoice | Credit Note (full) + New Invoice | Yes, if items returned |
| Customer returns goods | Credit Note + Return Note | Yes |
| Pricing error | Credit Note (difference) | No |
| Cancel paid invoice | Credit Note (full) | Depends |
| Discount after fact | Credit Note (discount amount) | No |

### 7.2 Credit Note Types

```php
enum CreditNoteReason: string
{
    case RETURN = 'return';           // Goods returned
    case PRICING_ERROR = 'pricing';   // Wrong price charged
    case QUANTITY_ERROR = 'quantity'; // Wrong quantity
    case DAMAGED_GOODS = 'damaged';   // Goods arrived damaged
    case DISCOUNT = 'discount';       // Post-sale discount
    case CANCELLATION = 'cancel';     // Full invoice cancellation
    case OTHER = 'other';             // Other (requires note)
}
```

### 7.3 Credit Note Creation Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       CREDIT NOTE CREATION                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   1. Select source invoice (must be posted)                                 │
│                                                                             │
│   2. System creates DRAFT credit note with:                                 │
│      - All line items copied from invoice                                   │
│      - Same partner, currency, tax settings                                 │
│      - Reference to source invoice                                          │
│                                                                             │
│   3. User modifies:                                                         │
│      - Remove lines not being credited                                      │
│      - Adjust quantities/amounts                                            │
│      - Select reason                                                        │
│      - Optionally check "Include Stock Return"                              │
│                                                                             │
│   4. On Post:                                                               │
│      - Create reversal journal entry (DR: Revenue, CR: AR)                  │
│      - If stock return: Create return note + inventory adjustment           │
│      - Apply credit to source invoice OR create customer credit             │
│      - Add to hash chain                                                    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 7.4 Credit Note Allocation

A credit note can be:

1. **Applied to the source invoice** - Reduces outstanding amount
2. **Applied to other invoices** - Settles other open invoices
3. **Held as customer credit** - Available for future invoices
4. **Refunded** - Actual money returned to customer

```php
// Credit note allocation options
enum CreditNoteAllocation: string
{
    case APPLY_TO_SOURCE = 'source';    // Apply to original invoice
    case APPLY_TO_OTHER = 'other';      // Apply to specified invoices
    case CUSTOMER_CREDIT = 'credit';    // Hold as customer credit
    case REFUND = 'refund';             // Process refund payment
}
```

### 7.5 Accounting Entries

**Credit Note (with stock return):**
```
DR  Sales Returns (709)              100.00
DR  VAT Collected (4457)              19.00
    CR  Accounts Receivable (411)            119.00

DR  Inventory (37)                    60.00
    CR  Cost of Goods Sold (607)              60.00
```

**Credit Note (price adjustment only):**
```
DR  Sales Discounts/Allowances        50.00
DR  VAT Collected                      9.50
    CR  Accounts Receivable                   59.50
```

**Refund Payment (when credit note is refunded):**
```
DR  Accounts Receivable              119.00
    CR  Bank/Cash                            119.00
```

---

## 8. Business Scenarios

### 8.1 Retail/POS Flow

```
Customer at counter → Add items → Invoice (draft)
                                      │
                                      ▼
                               Post Invoice
                                      │
                    ┌─────────────────┼─────────────────┐
                    ▼                 ▼                 ▼
              Stock Deducted    AR Created      Payment Prompt
                    │                 │                 │
                    └─────────────────┼─────────────────┘
                                      ▼
                              Register Payment
                                      │
                                      ▼
                               Invoice PAID
                               Print Receipt
```

**Characteristics:**
- No separate delivery note (stock updated on invoice)
- Payment typically immediate
- No bank reconciliation needed
- Single transaction flow

### 8.2 B2B Credit Sale Flow

```
Customer request → Quote (optional) → Sales Order
                                           │
                                           ▼
                                    Delivery Note
                                    (Goods Shipped)
                                           │
                                           ▼
                                       Invoice
                                    (Net 30 terms)
                                           │
                                           ▼
                              ┌────────────┴────────────┐
                              │                         │
                              ▼                         ▼
                        On Due Date              Customer Pays
                        Send Reminder            (via transfer)
                              │                         │
                              └────────────┬────────────┘
                                           ▼
                                  Register Payment
                                  (status: In Payment)
                                           │
                                           ▼
                                  Bank Reconciliation
                                           │
                                           ▼
                                    Invoice PAID
```

**Characteristics:**
- Delivery before invoice
- Payment terms (Net 30, 60, etc.)
- Bank reconciliation required
- Aging reports important

### 8.3 Prepaid/E-commerce Flow

```
Customer Order (online) → Sales Order + Payment
                                  │
                                  ▼
                          Payment Verified
                                  │
                                  ▼
                          Process Order
                                  │
                                  ▼
                          Delivery Note
                          (Ship goods)
                                  │
                                  ▼
                             Invoice
                         (already PAID)
```

**Characteristics:**
- Payment before delivery
- Stock reserved on order
- Invoice created as record (already paid)

### 8.4 Partial Delivery Flow

```
Sales Order (100 units)
        │
        ├──▶ Delivery Note #1 (60 units) ──▶ Stock -60
        │
        ├──▶ Invoice #1 (60 units) ──▶ AR Created
        │
        ├──▶ Delivery Note #2 (40 units) ──▶ Stock -40
        │
        └──▶ Invoice #2 (40 units) ──▶ AR Created
```

**Characteristics:**
- Multiple deliveries against one order
- Can invoice per delivery or batch

### 8.5 Return/Credit Flow

```
Original: Invoice #INV-001 (€1,000) ─── PAID
                    │
                    ▼
        Customer returns €300 worth of goods
                    │
                    ▼
        Credit Note #CN-001 (€300)
        + Return Note (stock +items)
                    │
                    ▼
        ┌───────────┴───────────┐
        ▼                       ▼
   Apply to             Create Refund
   Future Invoice       Payment (€300)
```

### 8.6 Advance Payment Flow

```
Customer wants to pay in advance
        │
        ▼
   Payment #PAY-001 (€5,000)
   (Unallocated - Customer Credit)
        │
        │  DR: Cash
        │  CR: Customer Advances (Liability)
        │
        ▼
   Later: Invoice #INV-001 (€3,000)
        │
        ▼
   Auto-prompt: "Apply customer credit?"
        │
        ▼
   Allocate €3,000 from advance
        │
        │  DR: Customer Advances
        │  CR: Accounts Receivable
        │
        ▼
   Invoice #INV-001: PAID
   Remaining Credit: €2,000
```

---

## 9. UI/UX Requirements

### 9.1 Invoice Header Display

Every invoice view must prominently display all three status dimensions:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Invoice #INV-2024-00156                                     [Actions ▼]   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────┐  ┌──────────────────┐  ┌────────────────┐                 │
│  │ ✓ Posted    │  │ ⚠ Partially Paid │  │ ✓ Delivered    │                 │
│  │ 05-Jan-2026 │  │ €800 / €1,500    │  │ DN-2024-00089  │                 │
│  └─────────────┘  └──────────────────┘  └────────────────┘                 │
│                                                                             │
│  Customer: ABC Corporation                                                  │
│  Due Date: 04-Feb-2026 (29 days remaining)                                 │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 9.2 Outstanding Amount Display

Always visible on invoice detail:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  PAYMENT SUMMARY                                                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Subtotal (HT)         │                              €1,260.50            │
│  Tax (TVA 19%)         │                                €239.50            │
│  ─────────────────────────────────────────────────────────────             │
│  Total (TTC)           │                              €1,500.00            │
│                                                                             │
│  Payments Received     │                               -€800.00            │
│  Credit Notes Applied  │                                 €0.00             │
│  ─────────────────────────────────────────────────────────────             │
│  OUTSTANDING           │                               €700.00   ⚠         │
│                                                                             │
│  [Register Payment]                                                         │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 9.3 Payment History Section

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  PAYMENT HISTORY                                                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Date        │ Reference   │ Method      │ Amount    │ Status              │
│  ────────────┼─────────────┼─────────────┼───────────┼───────────────────  │
│  05-Jan-2026 │ PAY-001     │ Bank Xfer   │ €500.00   │ ✓ Reconciled        │
│  08-Jan-2026 │ PAY-003     │ Check #4521 │ €300.00   │ ○ Pending           │
│                                                                             │
│  Total Paid: €800.00                                                        │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 9.4 Invoice List Columns

Required columns in invoice list view:

| Column | Description |
|--------|-------------|
| Number | Invoice number (linked) |
| Date | Invoice date |
| Customer | Customer name (linked) |
| Due Date | Payment due date |
| Total | Invoice total |
| Outstanding | Remaining amount (calculated) |
| Payment Status | Badge (Unpaid/Partial/Paid) |
| Document Status | Badge (Draft/Posted/Cancelled) |
| Actions | View, Pay, Print, etc. |

### 9.5 Quick Filters

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  INVOICES                                                                   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  [All] [Draft] [Posted] [Cancelled]    Document Status                      │
│                                                                             │
│  [All] [Unpaid] [Partial] [Paid] [Overdue]    Payment Status               │
│                                                                             │
│  Date Range: [This Month ▼]    Customer: [All ▼]    Search: [________]     │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 9.6 Payment Registration Modal

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  REGISTER PAYMENT                                                     [X]   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Invoice: #INV-2024-00156                                                   │
│  Customer: ABC Corporation                                                  │
│  Outstanding: €700.00                                                       │
│                                                                             │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                             │
│  Amount:        [€700.00_______]   [Pay Full Amount]                       │
│                                                                             │
│  Payment Date:  [06-Jan-2026___]                                           │
│                                                                             │
│  Method:        [Bank Transfer ▼]                                          │
│                                                                             │
│  Reference:     [TRF-20260106__]                                           │
│                                                                             │
│  ─────────────────────────────────────────────────────────────────────────  │
│                                                                             │
│  ⚠ Amount is less than outstanding. Invoice will be marked "Partially Paid"│
│                                                                             │
│                                              [Cancel]  [Register Payment]   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 10. Audit Checklist

This checklist should be executed by Claude Code to assess current implementation state.

### 10.1 Database Schema Audit

```bash
# Check document-related tables
grep -rn "CreateTable\|Schema::create" database/migrations/ | grep -i "document\|invoice\|payment\|credit"

# Check for payment_status column on invoices (SHOULD NOT EXIST as stored field)
grep -rn "payment_status" database/migrations/

# Check payment allocations table
grep -rn "payment_allocation\|PaymentAllocation" database/migrations/

# Check credit_notes table
grep -rn "credit_note" database/migrations/
```

### 10.2 Model Audit

```bash
# Find Document/Invoice models
find app -name "*.php" | xargs grep -l "class.*Invoice\|class.*Document"

# Check for payment status as attribute vs computed
grep -rn "payment_status" app/Modules --include="*.php"

# Check for outstanding amount calculation
grep -rn "outstanding\|getOutstanding" app/Modules --include="*.php"

# Find payment allocation relationships
grep -rn "allocations\|payments()" app/Modules --include="*.php"

# Check credit note model
find app -name "*.php" | xargs grep -l "CreditNote\|credit_note"
```

### 10.3 Service Layer Audit

```bash
# Find document services
find app -name "*Service.php" | xargs grep -l "Invoice\|Document\|Payment"

# Check for posting logic
grep -rn "post\|Posted\|posting" app/Modules/Document --include="*.php"

# Check for journal entry creation on invoice post
grep -rn "JournalEntry\|createJournal" app/Modules/Document --include="*.php"

# Check for immutability enforcement
grep -rn "immutable\|cannot.*edit\|posted.*cannot" app/Modules --include="*.php"
```

### 10.4 API Endpoint Audit

```bash
# Find document/invoice routes
grep -rn "invoice\|document\|payment" routes/ --include="*.php"

# Check for payment registration endpoint
grep -rn "payment.*store\|registerPayment\|record.*payment" app/Modules --include="*.php"

# Check for credit note endpoints
grep -rn "credit.*note\|creditNote" routes/ --include="*.php"
```

### 10.5 Frontend Audit

```bash
# Find invoice-related components
find apps/web/src -name "*.tsx" | xargs grep -l "Invoice\|invoice"

# Check for payment status display
grep -rn "paymentStatus\|payment_status\|outstanding" apps/web/src --include="*.tsx"

# Check for payment registration UI
find apps/web/src -name "*.tsx" | xargs grep -l "Payment\|RegisterPayment"

# Check for credit note UI
find apps/web/src -name "*.tsx" | xargs grep -l "CreditNote\|credit.*note"
```

### 10.6 Test Coverage Audit

```bash
# Find invoice/payment tests
find tests -name "*.php" | xargs grep -l "Invoice\|Payment\|CreditNote"

# Check for payment status tests
grep -rn "payment.*status\|outstanding" tests/ --include="*.php"

# Check for credit note tests
grep -rn "credit.*note\|CreditNote" tests/ --include="*.php"
```

---

## 11. Implementation Tasks

### 11.1 Phase 1: Foundation (Payment Status)

| Task | Priority | Complexity |
|------|----------|------------|
| Verify payment_status is NOT a stored column on invoices | HIGH | Low |
| Create/verify `PaymentAllocation` model and table | HIGH | Medium |
| Implement `getPaymentStatus()` computed method on Invoice | HIGH | Medium |
| Implement `getOutstandingAmount()` computed method | HIGH | Low |
| Add payment status badge to invoice list view | HIGH | Low |
| Add payment status badge to invoice detail view | HIGH | Low |
| Add outstanding amount display to invoice detail | HIGH | Low |

### 11.2 Phase 2: Payment Registration

| Task | Priority | Complexity |
|------|----------|------------|
| Create payment registration endpoint | HIGH | Medium |
| Create payment allocation logic (one payment → multiple invoices) | HIGH | High |
| Create "Register Payment" button on invoice (when posted & unpaid) | HIGH | Low |
| Create payment registration modal/page | HIGH | Medium |
| Handle partial payments | HIGH | Medium |
| Handle overpayments (customer credit) | MEDIUM | Medium |
| Add payment history section to invoice detail | HIGH | Medium |

### 11.3 Phase 3: Credit Notes

| Task | Priority | Complexity |
|------|----------|------------|
| Verify credit note creation from invoice | HIGH | Medium |
| Ensure credit note copies all lines from source invoice | HIGH | Medium |
| Implement credit note reason enum | MEDIUM | Low |
| Credit note affects invoice outstanding calculation | HIGH | Medium |
| Credit note posting creates reversal journal entry | HIGH | High |
| Optional: Stock return with credit note | MEDIUM | High |
| Credit note allocation options (apply to source vs customer credit) | MEDIUM | Medium |

### 11.4 Phase 4: Invoice Immutability

| Task | Priority | Complexity |
|------|----------|------------|
| Enforce no-edit on posted invoices (backend) | HIGH | Medium |
| Hide/disable edit button on posted invoices (frontend) | HIGH | Low |
| Allow only metadata edits (notes, tags) on posted invoices | MEDIUM | Low |
| Verify cancellation blocked for paid invoices | HIGH | Medium |
| Cancellation creates reversal journal entry | HIGH | High |

### 11.5 Phase 5: Fulfillment Tracking

| Task | Priority | Complexity |
|------|----------|------------|
| Implement fulfillment status calculation | MEDIUM | Medium |
| Add fulfillment status badge to invoice view | MEDIUM | Low |
| Link delivery notes to invoices | MEDIUM | Medium |
| Handle "Update Stock on Invoice" option (POS mode) | MEDIUM | Medium |

### 11.6 Phase 6: Reports & Aging

| Task | Priority | Complexity |
|------|----------|------------|
| Accounts Receivable Aging Report | HIGH | High |
| Customer Statement | HIGH | High |
| Payment History Report | MEDIUM | Medium |
| Overdue invoices filter/view | HIGH | Low |

---

## 12. Related Conversations

The following past conversations contain relevant context and decisions:

### 12.1 Credit Note Discussion

**Chat:** `177d688c-1d3a-43fc-ad0f-a0317c457c6f`  
**Topics:**
- Credit note creation from invoice (should copy all lines)
- Credit note accounting entries
- Reversal journal entry patterns
- Credit memo reason tracking

**Key Decision:** Credit note should be a mirror copy of the source invoice, with user deleting/adjusting lines they don't want to credit.

### 12.2 Treasury Module Design

**Chat:** `9271edf9-872b-4842-87d0-0a5a97344bcb`  
**Topics:**
- Universal Payment Method system (6 switches)
- Payment instruments (checks, vouchers)
- Payment repositories (cash registers, safes, bank accounts)
- Deferred instrument accounting (check clearing lifecycle)
- Payment allocation tables

**Key Decision:** Configuration-driven payment methods rather than hardcoded types.

### 12.3 Document Status Design

**Chat:** `9271edf9-842b-4842-87d0-0a5a97344bcb`  
**Topics:**
- Document status enum (draft, confirmed, posted, cancelled)
- Hash chain scope (fiscal documents only)
- Immutability rules post-posting

**Key Decision:** Chain fiscal documents only (posted invoices, credit notes, payments).

### 12.4 GL Service Integration

**Chat:** `36f714ef-fb01-4159-9309-cf233696b77e`  
**Topics:**
- GeneralLedgerService methods
- Payment posting to journal
- Customer advance handling
- SystemAccountPurpose usage

**Key Decision:** Use SystemAccountService for account lookup, never hardcode account numbers.

### 12.5 Delivery Note Flow

**Chat:** `bfab36de-88ce-4b8f-b925-24661d7a7cc8`  
**Topics:**
- Invoice vs Delivery Note separation
- When to auto-create delivery note
- Stock movement timing
- DDT compliance (Italy)

**Key Decision:** Delivery Note controls stock, Invoice controls money. They can be linked but are independent.

---

## Appendix A: Enums Reference

```php
// Document Status
enum DocumentStatus: string
{
    case DRAFT = 'draft';
    case CONFIRMED = 'confirmed';
    case POSTED = 'posted';
    case CANCELLED = 'cancelled';
}

// Payment Status (computed, not stored)
enum PaymentStatus: string
{
    case UNPAID = 'unpaid';
    case PARTIALLY_PAID = 'partially_paid';
    case IN_PAYMENT = 'in_payment';
    case PAID = 'paid';
    case OVERPAID = 'overpaid';
}

// Fulfillment Status (computed, not stored)
enum FulfillmentStatus: string
{
    case NOT_DELIVERED = 'not_delivered';
    case PARTIALLY_DELIVERED = 'partially_delivered';
    case FULLY_DELIVERED = 'fully_delivered';
    case NOT_APPLICABLE = 'not_applicable';  // Services only
}

// Credit Note Reason
enum CreditNoteReason: string
{
    case RETURN = 'return';
    case PRICING_ERROR = 'pricing';
    case QUANTITY_ERROR = 'quantity';
    case DAMAGED_GOODS = 'damaged';
    case DISCOUNT = 'discount';
    case CANCELLATION = 'cancel';
    case OTHER = 'other';
}

// Document Types
enum DocumentType: string
{
    case QUOTE = 'quote';
    case SALES_ORDER = 'sales_order';
    case DELIVERY_NOTE = 'delivery_note';
    case INVOICE = 'invoice';
    case CREDIT_NOTE = 'credit_note';
    case PURCHASE_ORDER = 'purchase_order';
    case GOODS_RECEIPT = 'goods_receipt';
    case SUPPLIER_INVOICE = 'supplier_invoice';
    case DEBIT_NOTE = 'debit_note';
}
```

---

## Appendix B: Database Schema Reference

```sql
-- Payment Allocations (links payments to invoices)
CREATE TABLE payment_allocations (
    id UUID PRIMARY KEY,
    company_id UUID NOT NULL REFERENCES companies(id),
    payment_id UUID NOT NULL REFERENCES payments(id),
    invoice_id UUID NOT NULL REFERENCES documents(id),
    amount DECIMAL(15,2) NOT NULL,
    allocated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    allocated_by UUID REFERENCES users(id),
    created_at TIMESTAMP DEFAULT NOW(),
    
    CONSTRAINT positive_amount CHECK (amount > 0),
    CONSTRAINT unique_payment_invoice UNIQUE (payment_id, invoice_id)
);

-- Credit Note Allocations (links credit notes to invoices)
CREATE TABLE credit_note_allocations (
    id UUID PRIMARY KEY,
    company_id UUID NOT NULL REFERENCES companies(id),
    credit_note_id UUID NOT NULL REFERENCES documents(id),
    invoice_id UUID NOT NULL REFERENCES documents(id),
    amount DECIMAL(15,2) NOT NULL,
    allocated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW(),
    
    CONSTRAINT positive_amount CHECK (amount > 0)
);

-- View for invoice outstanding amounts (materialized for performance)
CREATE MATERIALIZED VIEW invoice_balances AS
SELECT 
    d.id AS invoice_id,
    d.company_id,
    d.partner_id,
    d.total_amount,
    COALESCE(pa.total_paid, 0) AS total_paid,
    COALESCE(ca.total_credited, 0) AS total_credited,
    d.total_amount - COALESCE(pa.total_paid, 0) - COALESCE(ca.total_credited, 0) AS outstanding
FROM documents d
LEFT JOIN (
    SELECT invoice_id, SUM(amount) AS total_paid
    FROM payment_allocations
    GROUP BY invoice_id
) pa ON d.id = pa.invoice_id
LEFT JOIN (
    SELECT invoice_id, SUM(amount) AS total_credited
    FROM credit_note_allocations
    GROUP BY invoice_id
) ca ON d.id = ca.invoice_id
WHERE d.type = 'invoice' AND d.status = 'posted';
```

---

*End of Document*
