# Smart Payment Feature

> Intelligent payment handling with allocation, tolerance, and credit management.

---

## Overview

Smart Payment enables intelligent handling of customer payments:
- **Allocation Methods**: FIFO, Due Date Priority, Manual
- **Payment Tolerance**: Auto-write-off small differences
- **Advance Payments**: Credit balance for overpayments
- **Credit Application**: Apply credit to future invoices

---

## Allocation Methods

### FIFO (First In, First Out)
Allocate to oldest invoice first.

```
Invoices: INV-001 (1000), INV-002 (500), INV-003 (300)
Payment: 1200

Result:
- INV-001: 1000 (fully paid)
- INV-002: 200 (partially paid)
```

### Due Date Priority
Allocate to most overdue invoice first.

```
Invoices:
- INV-001 (1000) due: 2025-01-15
- INV-002 (500)  due: 2025-01-01 ← oldest
- INV-003 (300)  due: 2025-01-10

Payment: 800

Result:
- INV-002: 500 (fully paid, was most overdue)
- INV-003: 300 (fully paid)
```

### Manual Override
User specifies exact allocation per invoice.

---

## Payment Tolerance

Small payment differences are auto-written off:

| Scenario | Example | Action |
|----------|---------|--------|
| Underpayment | Invoice: 100, Paid: 99.50 | Write off 0.50 to expense |
| Overpayment | Invoice: 100, Paid: 100.30 | Write off 0.30 to income |

### Tolerance Settings

**Country Defaults:**

| Country | % Threshold | Max Amount |
|---------|-------------|------------|
| Tunisia | 0.5% | 0.100 TND |
| France | 0.5% | 0.50 EUR |
| UK | 0.5% | 0.50 GBP |

Companies can override defaults.

### Tolerance Logic

```
Must satisfy BOTH conditions:
1. difference <= (invoice_total × tolerance_percentage)
2. difference <= max_tolerance_amount
```

---

## Credit Balance

### Creating Credit
- **Advance Payment**: Payment with no open invoices
- **Overpayment**: Payment exceeds total invoices

### Using Credit
```php
// Apply credit to invoice
$payment->applyCredit($invoice, $amount);
```

### Refunding Credit
```php
// Refund excess credit to customer
$refund = $paymentService->refundCredit($partner, $amount, $reason);
```

---

## GL Integration

| Scenario | Debit | Credit |
|----------|-------|--------|
| Normal Payment | Bank (512) | Accounts Receivable (411) |
| Advance Payment | Bank (512) | Customer Advance (419) |
| Underpay Tolerance | Tolerance Expense (658) | Accounts Receivable (411) |
| Overpay Tolerance | Accounts Receivable (411) | Tolerance Income (758) |

---

## API Endpoints

```
# Allocation Preview
POST /api/payments/preview-allocation
Body: { partner_id, amount, method: 'fifo'|'due_date'|'manual' }

# Get Tolerance Settings
GET  /api/companies/{id}/payment-tolerance

# Open Invoices for Partner
GET  /api/partners/{id}/open-invoices

# Apply Allocation
POST /api/payments/{id}/allocate
Body: { allocations: [{ invoice_id, amount }] }
```

---

## Services

### PaymentAllocationService

```php
// Preview allocation
$preview = $service->previewAllocation($partnerId, $amount, AllocationMethod::FIFO);

// Apply allocation
$service->applyAllocation($payment, $allocations);
```

### PaymentToleranceService

```php
// Get effective tolerance for company
$tolerance = $service->getEffectiveTolerance($company);

// Check if difference qualifies for write-off
$qualifies = $service->qualifiesForWriteOff($difference, $invoiceTotal, $company);
```
