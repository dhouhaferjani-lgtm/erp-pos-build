# Credit Notes Feature

> Invoice corrections and refunds through credit notes.

---

## Overview

Credit notes are fiscal documents that correct or reverse posted invoices:
- Reduce customer's debt
- Reverse GL entries
- Maintain fiscal chain integrity

---

## Credit Note vs Credit Balance

| Concept | Description |
|---------|-------------|
| **Credit Note** | Fiscal document tied to an invoice (return, price adjustment) |
| **Credit Balance** | Account balance from overpayments (not a document) |

---

## Credit Note Reasons

| Reason | Code | Description |
|--------|------|-------------|
| Return | `return` | Goods returned |
| Price Adjustment | `price_adjustment` | Price correction |
| Quantity Error | `quantity_error` | Invoiced wrong quantity |
| Damaged Goods | `damaged` | Goods arrived damaged |
| Other | `other` | Free text reason |

---

## Workflow

```
Posted Invoice
      │
      ▼
┌─────────────┐
│ Create CN   │ ← Reason + Amount/Lines
└─────────────┘
      │
      ▼
┌─────────────┐
│  Confirm    │
└─────────────┘
      │
      ▼
┌─────────────┐
│    Post     │ ← GL reversal + fiscal chain
└─────────────┘
```

---

## Constraints

1. **Only from posted invoices**: Cannot create CN from draft/cancelled
2. **Amount limit**: CN total cannot exceed original invoice
3. **Cumulative limit**: Sum of all CNs cannot exceed invoice total
4. **Sequential numbering**: CN-2025-0001, CN-2025-0002...
5. **Fiscal chain**: Separate chain from invoices

---

## GL Entries

When credit note is posted:

| Account | Debit | Credit |
|---------|-------|--------|
| Sales Revenue (701) | Amount | - |
| Accounts Receivable (411) | - | Amount |

Tax reversal:
| Account | Debit | Credit |
|---------|-------|--------|
| Output VAT (443) | Tax | - |
| Accounts Receivable (411) | - | Tax |

---

## Services

### CreditNoteService

```php
// Create from invoice
$creditNote = $service->createFromInvoice($invoice, [
    'reason' => CreditNoteReason::Return,
    'amount' => 500.00,
    'description' => 'Customer returned 5 units',
]);

// Validate amount
$valid = $service->validateAmount($invoice, $amount);
```

---

## API Endpoints

```
# List credit notes for invoice
GET  /api/invoices/{id}/credit-notes

# Create credit note
POST /api/invoices/{id}/credit-notes
Body: { reason, amount?, lines?, description }

# Get credit note
GET  /api/credit-notes/{id}

# Post credit note
POST /api/credit-notes/{id}/post
```
