# API Reference

> Complete REST API documentation for AutoERP.

---

## Overview

- **Base URL**: `/api/v1`
- **Authentication**: Laravel Sanctum (Bearer token or session cookie)
- **Content Type**: `application/json`
- **Response Format**: JSON with `data` wrapper

---

## Authentication

### Login
```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password",
  "device_name": "web-browser"  // Optional, for token naming
}
```

**Response:**
```json
{
  "data": {
    "user": {
      "id": "uuid",
      "name": "John Doe",
      "email": "user@example.com"
    },
    "token": "1|abc123...",
    "abilities": ["*"]
  }
}
```

### Get Current User
```http
GET /api/v1/auth/me
Authorization: Bearer {token}
```

### Logout
```http
POST /api/v1/auth/logout
Authorization: Bearer {token}
```

### Refresh Token
```http
POST /api/v1/auth/refresh
Authorization: Bearer {token}
```

---

## Response Format

### Success Response
```json
{
  "data": { ... },
  "meta": {
    "timestamp": "2025-12-17T12:00:00Z",
    "request_id": "uuid"
  }
}
```

### Paginated Response
```json
{
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 100,
    "last_page": 7
  },
  "links": {
    "first": "/api/v1/resource?page=1",
    "last": "/api/v1/resource?page=7",
    "prev": null,
    "next": "/api/v1/resource?page=2"
  }
}
```

### Error Response
```json
{
  "error": {
    "code": "INSUFFICIENT_STOCK",
    "message": "Not enough stock for product X",
    "details": {
      "product_id": "uuid",
      "requested": 10,
      "available": 5
    }
  },
  "meta": {
    "timestamp": "2025-12-17T12:00:00Z",
    "request_id": "uuid"
  }
}
```

### Validation Error (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "amount": ["The amount must be a positive number."]
  }
}
```

---

## Common Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | int | Page number for pagination |
| `per_page` | int | Items per page (default: 15, max: 100) |
| `sort` | string | Field to sort by (prefix with `-` for desc) |
| `search` | string | Global search term |
| `filter[field]` | mixed | Filter by specific field |
| `include` | string | Comma-separated relations to include |

**Example:**
```http
GET /api/v1/invoices?page=2&per_page=25&sort=-date&filter[status]=posted&include=partner,lines
```

---

## Documents API

### Document Types
- `quote` - Sales quotations
- `sales_order` - Sales orders
- `invoice` - Sales invoices
- `credit_note` - Credit notes
- `delivery_note` - Delivery notes (DDT)
- `purchase_order` - Purchase orders

### List Documents (All Types)
```http
GET /api/v1/documents
```

### Quotes

#### List Quotes
```http
GET /api/v1/quotes
```

#### Create Quote
```http
POST /api/v1/quotes
Content-Type: application/json

{
  "partner_id": "uuid",
  "date": "2025-12-17",
  "due_date": "2025-12-31",
  "currency": "EUR",
  "notes": "Customer notes",
  "internal_notes": "Internal notes",
  "lines": [
    {
      "product_id": "uuid",
      "description": "Product description",
      "quantity": 2,
      "unit_price": 100.00,
      "discount_percent": 10,
      "tax_rate": 20
    }
  ]
}
```

#### Update Quote
```http
PATCH /api/v1/quotes/{id}
```

#### Confirm Quote
```http
POST /api/v1/quotes/{id}/confirm
```

#### Convert Quote to Order
```http
POST /api/v1/quotes/{id}/convert-to-order
```

### Sales Orders

#### List Orders
```http
GET /api/v1/orders
```

#### Confirm Order
```http
POST /api/v1/orders/{id}/confirm
```

#### Convert to Invoice
```http
POST /api/v1/orders/{id}/convert-to-invoice
```

#### Convert to Delivery Note
```http
POST /api/v1/orders/{id}/convert-to-delivery
```

### Invoices

#### List Invoices
```http
GET /api/v1/invoices
```

#### Post Invoice (Fiscal)
```http
POST /api/v1/invoices/{id}/post
```

**Response includes fiscal data:**
```json
{
  "data": {
    "id": "uuid",
    "number": "INV-2025-0001",
    "status": "posted",
    "fiscal_status": "posted",
    "hash": "sha256...",
    "previous_hash": "sha256...",
    "chain_sequence": 1
  }
}
```

#### Cancel Invoice
```http
POST /api/v1/invoices/{id}/cancel
```

**Note:** Only draft/confirmed invoices can be cancelled. Posted invoices require credit note.

#### Create Full Credit Note
```http
POST /api/v1/invoices/{id}/credit-full
```

#### Create Partial Credit Note
```http
POST /api/v1/invoices/{id}/credit-partial
Content-Type: application/json

{
  "lines": [
    {
      "line_id": "uuid",
      "quantity": 1,
      "reason": "damaged"
    }
  ],
  "reason": "partial_return"
}
```

### Credit Notes

#### List Credit Notes
```http
GET /api/v1/credit-notes
```

#### Post Credit Note
```http
POST /api/v1/credit-notes/{id}/post
```

### Purchase Orders

#### List Purchase Orders
```http
GET /api/v1/purchase-orders
```

#### Create Purchase Order
```http
POST /api/v1/purchase-orders
```

#### Receive Goods
```http
POST /api/v1/purchase-orders/{id}/receive
Content-Type: application/json

{
  "lines": [
    {
      "line_id": "uuid",
      "quantity_received": 10,
      "location_id": "uuid"
    }
  ]
}
```

### Delivery Notes

#### List Delivery Notes
```http
GET /api/v1/delivery-notes
```

#### Consolidate to Invoice (Tunisia Model)
```http
POST /api/v1/delivery-notes/consolidate-to-invoice
Content-Type: application/json

{
  "delivery_note_ids": ["uuid1", "uuid2"],
  "partner_id": "uuid",
  "date": "2025-12-17"
}
```

### Document Additional Costs (Landed Cost)

#### List Additional Costs
```http
GET /api/v1/documents/{id}/additional-costs
```

#### Add Additional Cost
```http
POST /api/v1/documents/{id}/additional-costs
Content-Type: application/json

{
  "description": "Freight",
  "amount": 500.00,
  "allocation_method": "by_value"  // by_value, by_quantity, by_weight
}
```

#### Get Landed Cost Breakdown
```http
GET /api/v1/documents/{id}/landed-cost-breakdown
```

### PDF & Email

#### Download PDF
```http
GET /api/v1/documents/{id}/pdf
Accept: application/pdf
```

#### Send by Email
```http
POST /api/v1/documents/{id}/email
Content-Type: application/json

{
  "to": "customer@example.com",
  "cc": ["copy@example.com"],
  "subject": "Your Invoice",
  "message": "Please find attached..."
}
```

---

## Inventory API

### Locations

#### List Locations
```http
GET /api/v1/locations
```

#### Create Location
```http
POST /api/v1/locations
Content-Type: application/json

{
  "name": "Main Warehouse",
  "type": "warehouse",
  "address": "123 Street",
  "city": "Paris",
  "country": "FR"
}
```

#### Set Default Location
```http
POST /api/v1/locations/{id}/set-default
```

### Stock Levels

#### List Stock Levels
```http
GET /api/v1/stock-levels
```

**Query Parameters:**
- `filter[location_id]` - Filter by location
- `filter[low_stock]` - Show only low stock items

#### Get Stock for Product/Location
```http
GET /api/v1/stock-levels/{product_id}/{location_id}
```

### Stock Movements

#### List Movements
```http
GET /api/v1/stock-movements
```

> **The four raw write endpoints were removed (DPA V7).**
> `POST /api/v1/stock-movements/{receive,issue,transfer,adjust}` no longer exist.
> They wrote unjustified signed stock deltas — no `reason`, no document — and
> `adjust` took an ABSOLUTE `new_quantity` that silently overwrote anything
> committed between the browser read and the POST. `GET /api/v1/stock-movements`
> is unchanged. Replacements:
>
> | Removed | Use instead |
> |---|---|
> | `POST /stock-movements/receive` | `POST /goods-receipts/standalone` when supplier-sourced and priced; otherwise a positive `stock_adjustments` line |
> | `POST /stock-movements/issue` | the delivery note when partner-bound, `POST /batches/{uuid}/write-off` when lot-identified; otherwise a negative `stock_adjustments` line |
> | `POST /stock-movements/transfer` | `POST /stock-transfers` |
> | `POST /stock-movements/adjust` | `POST /stock-adjustments` |

### Stock Adjustments

Manual stock corrections as a lifecycle-tracked, multi-line DOCUMENT.
`location_id` is on the header; `reason_code` is on the LINE, so a
mixed-direction reconciliation is representable in one document.

Permissions are step-split: `inventory.adjustments.view` / `.create` / `.post` /
`.cancel`. `post_immediately: true` is therefore a TWO-leg check (`create` AND
`post`), and supplying `acknowledge_stale` or `ignore_reservations` without
`.post` is a 403 rather than a silent ignore.

```http
GET    /api/v1/stock-adjustments                       can:inventory.adjustments.view
GET    /api/v1/stock-adjustments/{adjustment}          can:inventory.adjustments.view
POST   /api/v1/stock-adjustments                       can:inventory.adjustments.create
PATCH  /api/v1/stock-adjustments/{adjustment}          can:inventory.adjustments.create   (draft only)
POST   /api/v1/stock-adjustments/{adjustment}/post     can:inventory.adjustments.post
POST   /api/v1/stock-adjustments/{adjustment}/cancel   can:inventory.adjustments.cancel   (draft only)
POST   /api/v1/stock-adjustments/{adjustment}/correct  can:inventory.adjustments.create   (posted only)
```

#### Create a stock adjustment
```http
POST /api/v1/stock-adjustments
Content-Type: application/json

{
  "location_id": "uuid",
  "note": "Quarterly reconciliation",
  "idempotency_key": "optional-string",
  "post_immediately": false,
  "acknowledge_stale": false,
  "ignore_reservations": false,
  "lines": [
    {
      "product_id": "uuid",
      "variant_id": null,
      "batch_uuid": null,
      "reason_code": "adjustment_positive|adjustment_negative|damage|write_off",
      "delta_quantity": "-2.5000",
      "observed_before": "12.0000",
      "line_note": null
    }
  ]
}
```

- `delta_quantity` is **signed**, non-zero, at most 4 dp, and its sign is bound
  to `reason_code` (`adjustment_positive` is the only inbound reason).
- `observed_before` is the operator's authoring snapshot, taken from a FRESH
  `GET /stock-levels/{product}/{location}` read. It is compared against the row
  under its lock; a mismatch is `STOCK_MOVED_SINCE_AUTHORING` (422).
- `occurred_at` is **prohibited**: v1 forbids backdating and stamps it
  server-side.
- `batch_uuid` is the lot's PUBLIC uuid. A negative line MUST name a lot when the
  product holds one with stock at that location; `damage` / `write_off` are
  refused on batch-tracked products and routed to the batch write-off, which
  posts the COGS entry this document does not.

#### Refusal codes

| Code | HTTP | Notes |
|---|---|---|
| `STOCK_MOVED_SINCE_AUTHORING` | 422 | overridable via `acknowledge_stale`; `details.lines[].line_id` is `null` when nothing was persisted |
| `ADJUSTMENT_EXCEEDS_AVAILABLE` | 422 | reserved-aware; `details.overridable = true`, override via `ignore_reservations` |
| `INSUFFICIENT_BATCH_STOCK` | 422 | carries the shortfall |
| `BATCH_REQUIRED_FOR_LINE` | 422 | |
| `BATCH_NOT_APPLICABLE` | 422 | |
| `USE_BATCH_WRITE_OFF` | 422 | |
| `LINE_TENANT_MISMATCH` | 422 | |
| `INVALID_ADJUSTMENT_STATE` | 422 | carries the legal transition set |
| `ADJUSTMENT_ALREADY_CORRECTED` | 422 | |
| `CANNOT_CORRECT_A_CORRECTION` | 422 | |
| `LOCATION_ACCESS_DENIED` | 403 | |

Every quantity-bearing refusal carries `quantity_decimals`, the product unit's
display precision.

### Inventory Counting

#### List Countings
```http
GET /api/v1/inventory/countings
```

#### Create Counting Session
```http
POST /api/v1/inventory/countings
Content-Type: application/json

{
  "location_id": "uuid",
  "name": "Monthly Count - December",
  "scope_type": "full_inventory",  // or product_location, category
  "execution_mode": "parallel",     // or sequential
  "counter_1_id": "uuid",
  "counter_2_id": "uuid",
  "scheduled_at": "2025-12-20T09:00:00Z"
}
```

#### Mobile-Initiated Draft
```http
POST /api/v1/inventory/countings/drafts
Content-Type: application/json

{
  "location_id": "uuid",
  "name": "Quick Count"
}
```

#### Add Product to Draft
```http
POST /api/v1/inventory/countings/{id}/add-product
Content-Type: application/json

{
  "product_id": "uuid"
}
```

#### Activate Draft
```http
POST /api/v1/inventory/countings/{id}/activate-draft
```

#### Activate Counting
```http
POST /api/v1/inventory/countings/{id}/activate
```

#### Submit Count (Counter View)
```http
POST /api/v1/inventory/countings/{counting_id}/items/{item_id}/count
Content-Type: application/json

{
  "quantity": 45
}
```

#### Get Reconciliation
```http
GET /api/v1/inventory/countings/{id}/reconciliation
```

#### Trigger Third Count (for discrepancies)
```http
POST /api/v1/inventory/countings/{id}/trigger-third-count
Content-Type: application/json

{
  "item_ids": ["uuid1", "uuid2"]
}
```

#### Manual Override
```http
POST /api/v1/inventory/countings/items/{item_id}/override
Content-Type: application/json

{
  "final_quantity": 50,
  "reason": "Verified with supervisor"
}
```

#### Finalize Counting
```http
POST /api/v1/inventory/countings/{id}/finalize
```

---

## Treasury API

### Payment Methods

#### List Payment Methods
```http
GET /api/v1/payment-methods
```

#### Create Payment Method
```http
POST /api/v1/payment-methods
Content-Type: application/json

{
  "code": "CHECK",
  "name": "Check",
  "is_physical": true,
  "has_maturity": false,
  "requires_third_party": false,
  "is_push": true,
  "has_deducted_fees": false,
  "is_restricted": false
}
```

### Payment Repositories

#### List Repositories
```http
GET /api/v1/payment-repositories
```

#### Get Balance
```http
GET /api/v1/payment-repositories/{id}/balance
```

#### Get Transactions
```http
GET /api/v1/payment-repositories/{id}/transactions
```

### Payment Instruments

#### List Instruments
```http
GET /api/v1/payment-instruments
```

**Query Parameters:**
- `filter[status]` - Filter by status (received, deposited, cleared, bounced)
- `filter[maturity_from]`, `filter[maturity_to]` - Filter by maturity date

#### Create Instrument (Record Check Received)
```http
POST /api/v1/payment-instruments
Content-Type: application/json

{
  "payment_method_id": "uuid",
  "amount": 5000.00,
  "currency": "EUR",
  "reference": "CHK-123456",
  "maturity_date": "2025-12-31",
  "repository_id": "uuid"
}
```

#### Deposit Instrument
```http
POST /api/v1/payment-instruments/{id}/deposit
Content-Type: application/json

{
  "bank_account_id": "uuid"
}
```

#### Clear Instrument
```http
POST /api/v1/payment-instruments/{id}/clear
```

#### Mark as Bounced
```http
POST /api/v1/payment-instruments/{id}/bounce
Content-Type: application/json

{
  "reason": "Insufficient funds"
}
```

#### Transfer Between Repositories
```http
POST /api/v1/payment-instruments/{id}/transfer
Content-Type: application/json

{
  "to_repository_id": "uuid"
}
```

### Payments

#### List Payments
```http
GET /api/v1/payments
```

#### Record Payment
```http
POST /api/v1/payments
Content-Type: application/json

{
  "partner_id": "uuid",
  "payment_method_id": "uuid",
  "repository_id": "uuid",
  "amount": 1500.00,
  "currency": "EUR",
  "reference": "Payment for INV-001",
  "allocations": [
    {
      "document_id": "uuid",
      "amount": 1500.00
    }
  ]
}
```

#### Refund Payment
```http
POST /api/v1/payments/{id}/refund
Content-Type: application/json

{
  "amount": 500.00,
  "reason": "Partial return"
}
```

#### Reverse Payment
```http
POST /api/v1/payments/{id}/reverse
Content-Type: application/json

{
  "reason": "Error correction"
}
```

### Smart Payment

#### Get Open Invoices for Partner
```http
GET /api/v1/partners/{partner_id}/open-invoices
```

#### Preview Allocation
```http
POST /api/v1/smart-payment/preview-allocation
Content-Type: application/json

{
  "partner_id": "uuid",
  "amount": 2500.00,
  "currency": "EUR"
}
```

**Response:**
```json
{
  "data": {
    "allocations": [
      {
        "document_id": "uuid",
        "document_number": "INV-001",
        "document_total": 1000.00,
        "balance_due": 1000.00,
        "allocated_amount": 1000.00
      },
      {
        "document_id": "uuid",
        "document_number": "INV-002",
        "document_total": 1500.00,
        "balance_due": 1500.00,
        "allocated_amount": 1500.00
      }
    ],
    "total_allocated": 2500.00,
    "remaining": 0.00
  }
}
```

#### Apply Allocation
```http
POST /api/v1/smart-payment/apply-allocation
Content-Type: application/json

{
  "partner_id": "uuid",
  "payment_method_id": "uuid",
  "repository_id": "uuid",
  "amount": 2500.00,
  "currency": "EUR",
  "allocations": [
    {"document_id": "uuid", "amount": 1000.00},
    {"document_id": "uuid", "amount": 1500.00}
  ]
}
```

### Multi-Payment

#### Split Payment (Multiple Methods)
```http
POST /api/v1/documents/{document_id}/split-payment
Content-Type: application/json

{
  "payments": [
    {
      "payment_method_id": "uuid",
      "repository_id": "uuid",
      "amount": 500.00
    },
    {
      "payment_method_id": "uuid",
      "repository_id": "uuid",
      "amount": 1000.00
    }
  ]
}
```

#### Record Payment on Account
```http
POST /api/v1/payments/on-account
Content-Type: application/json

{
  "partner_id": "uuid",
  "payment_method_id": "uuid",
  "repository_id": "uuid",
  "amount": 5000.00,
  "currency": "EUR"
}
```

#### Get Partner Account Balance
```http
GET /api/v1/partners/{partner_id}/account-balance/{currency}
```

### Bank Reconciliation

#### List Reconciliations
```http
GET /api/v1/bank-reconciliations
```

#### Create Reconciliation
```http
POST /api/v1/bank-reconciliations
Content-Type: application/json

{
  "repository_id": "uuid",
  "statement_date": "2025-12-31",
  "opening_balance": 10000.00,
  "closing_balance": 15000.00
}
```

#### Match Item
```http
POST /api/v1/bank-reconciliations/{reconciliation_id}/match/{payment_id}
```

#### Complete Reconciliation
```http
POST /api/v1/bank-reconciliations/{id}/complete
```

---

## Accounting API

### Chart of Accounts

#### List Accounts
```http
GET /api/v1/accounts
```

#### Create Account
```http
POST /api/v1/accounts
Content-Type: application/json

{
  "code": "411100",
  "name": "Customers - Domestic",
  "type": "asset",
  "parent_id": "uuid"
}
```

### Journal Entries

#### List Journal Entries
```http
GET /api/v1/journal-entries
```

#### Create Manual Journal Entry
```http
POST /api/v1/journal-entries
Content-Type: application/json

{
  "date": "2025-12-17",
  "description": "Accrual adjustment",
  "lines": [
    {
      "account_id": "uuid",
      "debit": 1000.00,
      "credit": 0,
      "description": "Accrued expense"
    },
    {
      "account_id": "uuid",
      "debit": 0,
      "credit": 1000.00,
      "description": "Expense accrual"
    }
  ]
}
```

#### Post Journal Entry
```http
POST /api/v1/journal-entries/{id}/post
```

### Account Purposes

#### List Purposes
```http
GET /api/v1/companies/{company_id}/accounts/purposes
```

#### Assign Purpose
```http
PUT /api/v1/companies/{company_id}/accounts/{account_id}/purpose
Content-Type: application/json

{
  "purpose": "accounts_receivable"
}
```

### Partner Balances

#### Get Partner Balance
```http
GET /api/v1/companies/{company_id}/partners/{partner_id}/balance
```

#### Get Partner Statement
```http
GET /api/v1/companies/{company_id}/partners/{partner_id}/statement
```

**Query Parameters:**
- `from_date`, `to_date` - Date range
- `currency` - Filter by currency

#### Get Receivables Report
```http
GET /api/v1/companies/{company_id}/subledger/receivables
```

#### Get Payables Report
```http
GET /api/v1/companies/{company_id}/subledger/payables
```

### Opening Balances

#### List Opening Batches
```http
GET /api/v1/companies/{company_id}/opening-batches
```

#### Create Opening Batch
```http
POST /api/v1/companies/{company_id}/opening-batches
Content-Type: application/json

{
  "type": "partner_ar",
  "as_of_date": "2025-01-01"
}
```

#### Import Opening Data
```http
POST /api/v1/companies/{company_id}/opening-batches/{batch_id}/import
Content-Type: multipart/form-data

file: (CSV file)
```

#### Validate Batch
```http
POST /api/v1/companies/{company_id}/opening-batches/{batch_id}/validate
```

#### Post Opening Batch
```http
POST /api/v1/companies/{company_id}/opening-batches/{batch_id}/post
```

---

## Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `VALIDATION_ERROR` | 422 | Request validation failed |
| `UNAUTHENTICATED` | 401 | Missing or invalid auth token |
| `UNAUTHORIZED` | 403 | Insufficient permissions |
| `NOT_FOUND` | 404 | Resource not found |
| `INSUFFICIENT_STOCK` | 422 | Not enough stock available |
| `DOCUMENT_ALREADY_POSTED` | 422 | Cannot modify posted document |
| `INVALID_DOCUMENT_STATUS` | 422 | Invalid status transition |
| `DOUBLE_ENTRY_IMBALANCE` | 422 | Journal entry debits != credits |
| `HASH_CHAIN_BROKEN` | 500 | Fiscal hash chain integrity error |
| `PAYMENT_OVER_ALLOCATION` | 422 | Payment exceeds invoice balance |
| `INSTRUMENT_INVALID_STATUS` | 422 | Invalid instrument status for operation |

---

## Rate Limiting

| Endpoint Category | Limit |
|-------------------|-------|
| Authentication | 10 requests/minute |
| Read operations | 60 requests/minute |
| Write operations | 30 requests/minute |
| Bulk operations | 10 requests/minute |

---

## Webhooks (Future)

Planned webhook events:
- `invoice.posted`
- `payment.received`
- `stock.low`
- `counting.finalized`

---

## Related Documentation

- [Backend Architecture](../architecture/backend.md)
- [Module Reference](../modules/README.md)
- [Frontend Architecture](../architecture/frontend.md)
