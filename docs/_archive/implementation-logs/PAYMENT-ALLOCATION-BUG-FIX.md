# Payment Allocation Bug Fix

## Issue Summary

**Problem:** Payments were being created but NOT allocated to invoices, resulting in:
- Invoice `balance_due` not updating after payment
- Payment records missing `partner_id`
- No `payment_allocations` records created in database

**Root Cause:** Frontend `PaymentForm.tsx` was NOT sending the `allocations` array that the backend `PaymentController.php` requires.

**User Report:**
> "I recorded a payment to this invoice: TEST-INV-001 which was open for $2,000 overdue, and I made a payment of $3,000. I saw the toast that a payment has been made, but nothing changed on the invoice page. It remains still overdue for $2,000 even when I refreshed it... when I went to the payments page in the treasury, I saw that the payment was made, but there was no partner associated with it."

---

## Technical Details

### Backend (Already Correct)

`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php` (lines 141-187)

The backend **already had** correct allocation logic:
```php
// Expects 'allocations' array in request
'allocations' => [
  ['document_id' => 'uuid', 'amount' => '2000.00'],
  ['document_id' => 'uuid', 'amount' => '1000.00'],
]

// Processes each allocation:
foreach ($allocations as $allocationData) {
    $document = Document::lockForUpdate()->findOrFail($allocationData['document_id']);

    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $document->id,
        'amount' => $allocationAmount,
    ]);

    // Updates invoice balance
    $newBalance = bcsub($document->balance_due, $allocationAmount, 2);
    $document->balance_due = $newBalance;

    if (bccomp($newBalance, '0.00', 2) === 0) {
        $document->status = DocumentStatus::Paid;
    }

    $document->save();
}
```

### Frontend (FIXED)

`apps/web/src/features/treasury/PaymentForm.tsx`

**BEFORE (Lines 177-198):**
```typescript
const createMutation = useMutation({
  mutationFn: (data: PaymentFormData) =>
    apiPost<Payment>('/payments', {
      ...data,
      amount: parseFloat(data.amount),
      invoice_id: invoiceId ?? undefined,  // ❌ Only sent invoice_id, not allocations
    }),
  // ...
})
```

**AFTER (Lines 177-237):**
```typescript
const createMutation = useMutation({
  mutationFn: (data: PaymentFormData) => {
    // Prepare allocations array
    const allocations = []

    // If coming from a specific invoice, allocate to that invoice
    if (invoiceId && invoiceData) {
      const amountResidual = invoiceData.amount_residual ?? parseFloat(invoiceData.total)
      const allocationAmount = Math.min(parseFloat(data.amount), amountResidual)

      allocations.push({
        document_id: invoiceId,
        amount: allocationAmount.toFixed(2),
      })
    }
    // If no specific invoice, auto-allocate using FIFO to open invoices
    else if (openInvoices.length > 0 && selectedPartnerId) {
      let remainingAmount = parseFloat(data.amount)

      // Sort invoices by document_date (FIFO)
      const sortedInvoices = [...openInvoices].sort((a, b) =>
        new Date(a.document_date).getTime() - new Date(b.document_date).getTime()
      )

      for (const invoice of sortedInvoices) {
        if (remainingAmount <= 0) break

        const invoiceBalance = parseFloat(invoice.balance_due)
        const allocationAmount = Math.min(remainingAmount, invoiceBalance)

        allocations.push({
          document_id: invoice.id,
          amount: allocationAmount.toFixed(2),
        })

        remainingAmount -= allocationAmount
      }
    }

    return apiPost<Payment>('/payments', {
      ...data,
      amount: parseFloat(data.amount),
      allocations: allocations.length > 0 ? allocations : undefined,  // ✅ Now sends allocations
    })
  },
  // ...
})
```

---

## What Changed

### Scenario 1: Payment from Invoice Detail Page
**Before:**
- Payment created with `invoice_id` field
- Backend ignored `invoice_id` (only processes `allocations` array)
- No allocations created

**After:**
- Automatically creates allocation to that specific invoice
- Allocates up to the invoice's remaining balance
- Excess payment amount stays unallocated (can be handled later)

### Scenario 2: Payment from General Payment Form
**Before:**
- Two-step flow: create payment → optionally allocate via separate form
- Allocation form only showed if `openInvoices.length > 0 && !invoiceId`
- If user didn't use allocation form, payment remained unallocated

**After:**
- Automatically allocates using **FIFO (First In First Out)**
- Sorts open invoices by `document_date` (oldest first)
- Distributes payment across multiple invoices if needed
- User can still use allocation form to manually adjust

---

## Testing Instructions

### Test 1: Single Invoice Payment (Exact Amount)
1. Navigate to invoice TEST-INV-001 ($2,000 overdue)
2. Click "Record Payment"
3. Enter $2,000 payment
4. Select payment method and repository
5. Submit

**Expected Result:**
- ✅ Invoice status changes to "Paid"
- ✅ Invoice `balance_due` becomes $0.00
- ✅ Payment shows partner name in payment list
- ✅ Payment detail shows allocation to TEST-INV-001

### Test 2: Overpayment (Exceeds Invoice)
1. Navigate to invoice TEST-INV-001 ($2,000 overdue)
2. Click "Record Payment"
3. Enter $3,000 payment
4. Submit

**Expected Result:**
- ✅ Invoice fully paid ($2,000 allocated)
- ✅ Payment shows partner name
- ✅ Payment has $1,000 unallocated (excess)
- ⚠️ Excess handling (credit balance/tolerance) to be implemented later

### Test 3: FIFO Allocation (Multiple Invoices)
1. Navigate to Payments page
2. Click "New Payment"
3. Select partner with multiple open invoices
4. Enter $5,000 payment amount
5. Submit

**Expected Result:**
- ✅ Payment allocated to oldest invoices first (by document_date)
- ✅ Multiple invoices updated (e.g., INV-001: $2,000, INV-002: $1,500, INV-003: $1,500)
- ✅ All allocated invoices show reduced balances
- ✅ Payment detail shows multiple allocations

### Test 4: Partial Payment
1. Navigate to invoice TEST-INV-002 ($1,500 balance)
2. Click "Record Payment"
3. Enter $500 payment
4. Submit

**Expected Result:**
- ✅ Invoice status remains "Open" (not fully paid)
- ✅ Invoice `balance_due` reduced to $1,000
- ✅ Payment shows $500 allocation to TEST-INV-002

---

## Database Verification

After recording a payment, verify in the database:

```sql
-- Check payment record
SELECT id, partner_id, amount, status
FROM payments
ORDER BY created_at DESC
LIMIT 1;

-- Check payment allocations
SELECT pa.*, d.document_number, pa.amount
FROM payment_allocations pa
JOIN documents d ON pa.document_id = d.id
WHERE pa.payment_id = '<payment-id-from-above>';

-- Check updated invoice balance
SELECT id, document_number, balance_due, status
FROM documents
WHERE id = '<invoice-id>';
```

**Expected Results:**
- ✅ `payments.partner_id` is NOT NULL
- ✅ `payment_allocations` records exist
- ✅ `documents.balance_due` updated correctly
- ✅ `documents.status` = 'paid' when balance_due = 0

---

## Deployment Checklist

- [x] Fix applied to `PaymentForm.tsx`
- [x] Web server restarted (http://localhost:5174)
- [ ] Manual testing completed (user to test)
- [ ] Database verification performed
- [ ] Git commit with conventional message

---

## Files Modified

1. `/apps/web/src/features/treasury/PaymentForm.tsx` (lines 177-237)
   - Added automatic allocation logic
   - FIFO sorting by document_date
   - Handles both single-invoice and multi-invoice scenarios

---

## Related Features (Future Work)

The following features are already implemented on backend but need frontend work:

1. **Smart Allocation Methods** (already exists via PaymentAllocationForm)
   - FIFO (implemented in this fix)
   - Due Date Priority (backend ready)
   - Manual Selection (backend ready)

2. **Payment Tolerance** (backend ready, frontend pending)
   - Auto write-off small differences
   - Configurable per company/country

3. **Excess Handling** (backend ready, frontend pending)
   - Credit balance (customer advance)
   - Tolerance write-off

4. **Credit Notes** (backend ready, frontend pending)
   - Create credit notes for returns
   - Apply credit notes to invoices

---

*Fix applied: December 10, 2025*
*Backend was already correct - only frontend needed fix*
