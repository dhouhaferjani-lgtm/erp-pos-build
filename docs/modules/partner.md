# Partner Module

> Customers and suppliers with balance tracking.

---

## Purpose

The Partner module manages all business relationships including customers, suppliers, and dual-role partners.

---

## Partner Types

| Type | Code | Description |
|------|------|-------------|
| Customer | `customer` | Buys from us |
| Supplier | `supplier` | We buy from them |
| Both | `both` | Dual relationship |

---

## Key Fields

```php
Partner {
    id: UUID
    tenant_id: UUID
    company_id: UUID
    type: PartnerType
    name: string
    code: string?
    tax_id: string?
    email: string?
    phone: string?

    // Balance tracking
    receivable_balance: decimal  // What they owe us
    payable_balance: decimal     // What we owe them
    credit_balance: decimal      // Their credit (overpayments)
    credit_limit: decimal?       // Maximum credit allowed

    // Status
    is_active: boolean
}
```

---

## Balance Tracking

### Receivable Balance
Total unpaid invoices. Updated when:
- Invoice posted (+)
- Payment allocated (-)
- Credit note posted (-)

### Credit Balance
Advance payments and overpayments. Updated when:
- Advance payment received (+)
- Payment exceeds invoice (+)
- Credit applied to invoice (-)
- Refund issued (-)

### Credit Limit
Optional maximum outstanding balance allowed before blocking new sales.

---

## Services

### PartnerBalanceService

```php
// Recalculate balances from journal entries
$service->recalculateBalance($partner);

// Check if within credit limit
$allowed = $service->canExtendCredit($partner, $amount);
```

---

## API Endpoints

```
GET    /api/partners                    # List with filters
GET    /api/partners/{id}               # Get single partner
POST   /api/partners                    # Create partner
PATCH  /api/partners/{id}               # Update partner
GET    /api/partners/{id}/balance       # Balance summary
GET    /api/partners/{id}/ledger        # Transaction history
GET    /api/partners/{id}/open-invoices # Unpaid invoices
```
