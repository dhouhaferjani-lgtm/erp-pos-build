# Document Module

> Unified document handling for all trade documents.

---

## Purpose

The Document module manages all trade documents through a single unified table with type-specific behavior. This simplifies document conversion workflows (Quote → Order → Invoice).

---

## Document Types

| Type | Code | Fiscal | Description |
|------|------|--------|-------------|
| Quote | `quote` | No | Sales proposal |
| Sales Order | `sales_order` | No | Confirmed order |
| Invoice | `invoice` | Yes | Tax invoice |
| Credit Note | `credit_note` | Yes | Invoice correction/refund |
| Delivery Note | `delivery_note` | No | Shipment record |
| Purchase Order | `purchase_order` | No | Supplier order |
| Purchase Invoice | `purchase_invoice` | No | Supplier invoice |

---

## Document Lifecycle

```
┌─────────┐     ┌───────────┐     ┌────────┐     ┌──────────┐
│  Draft  │ ──► │ Confirmed │ ──► │ Posted │ ──► │   Paid   │
└─────────┘     └───────────┘     └────────┘     └──────────┘
     │                                  │
     ▼                                  ▼
┌───────────┐                    ┌───────────┐
│ Cancelled │                    │ Cancelled │
└───────────┘                    └───────────┘
```

### Status Definitions

| Status | Meaning | Editable |
|--------|---------|----------|
| `draft` | Work in progress | Full |
| `confirmed` | Ready to post | Limited |
| `posted` | Fiscally sealed | balance_due only |
| `partially_paid` | Has payments | balance_due only |
| `paid` | Fully paid | None |
| `cancelled` | Voided | None |

---

## Key Models

### Document

```php
Document {
    id: UUID
    tenant_id: UUID
    company_id: UUID
    partner_id: UUID
    type: DocumentType (enum)
    status: DocumentStatus (enum)
    document_number: string
    document_date: date
    due_date: date?
    currency: string (ISO 4217)
    subtotal: decimal
    tax_amount: decimal
    total: decimal
    balance_due: decimal

    // Fiscal fields
    fiscal_category: FiscalCategory (enum)
    fiscal_status: FiscalStatus (enum)
    fiscal_hash: string?
    previous_hash: string?
    chain_sequence: int?
}
```

### DocumentLine

```php
DocumentLine {
    id: UUID
    document_id: UUID
    product_id: UUID?
    description: string
    quantity: decimal
    unit_price: decimal
    discount_percent: decimal
    tax_rate: decimal
    line_total: decimal
}
```

---

## Services

### DocumentConversionService

Converts documents between types while preserving data:

```php
// Convert quote to sales order
$order = $conversionService->convert($quote, DocumentType::SalesOrder);

// Convert sales order to invoice
$invoice = $conversionService->convert($order, DocumentType::Invoice);
```

### DocumentPostingService

Posts confirmed documents to the fiscal chain:

```php
// Post invoice (seals it)
$posted = $postingService->post($invoice);

// Cancel posted document
$cancelled = $postingService->cancel($invoice);
```

---

## API Endpoints

```
GET    /api/documents              # List with filters
GET    /api/documents/{id}         # Get single document
POST   /api/documents              # Create draft
PATCH  /api/documents/{id}         # Update draft
DELETE /api/documents/{id}         # Delete draft only

POST   /api/documents/{id}/confirm # Transition to confirmed
POST   /api/documents/{id}/post    # Post (creates GL entries)
POST   /api/documents/{id}/cancel  # Cancel with reversal
```

---

## Document Numbering

Each document type has its own sequence per company:

| Type | Prefix | Example |
|------|--------|---------|
| Quote | QUO | QUO-2025-0001 |
| Sales Order | SO | SO-2025-0001 |
| Invoice | INV | INV-2025-0001 |
| Credit Note | CN | CN-2025-0001 |

Configured in `companies` table: `invoice_prefix`, `invoice_next_number`, etc.
