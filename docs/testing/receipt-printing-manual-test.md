# Receipt Printing - Manual Test Plan

## Test Environment Setup

- [ ] Backend running with PDF generation service (`php artisan serve`)
- [ ] Frontend dev server running (`pnpm dev`)
- [ ] Sample products exist in database
- [ ] Company settings configured with `auto_print_receipts`
- [ ] Browser popup blocker configured (for testing fallback)

## Pre-Test Configuration

### Database Setup
```sql
-- Check company auto_print_receipts setting
SELECT id, name, auto_print_receipts, receipt_logo, receipt_footer
FROM companies
WHERE id = 'your-company-id';

-- Enable auto-print if needed
UPDATE companies
SET auto_print_receipts = true
WHERE id = 'your-company-id';

-- Disable auto-print for some tests
UPDATE companies
SET auto_print_receipts = false
WHERE id = 'your-company-id';
```

## Test Cases

### TC1: Advanced Payments Modal - Manual Print
**Preconditions:**
- Company `auto_print_receipts` = `false`
- At least one product in cart

**Steps:**
1. Navigate to POS page
2. Add products to cart
3. Click "Advanced Payments" button
4. Select payment method (e.g., Cash)
5. Enter payment amount equal to total
6. Click "Complete Transaction"
7. Wait for success screen to appear

**Expected Results:**
- ✅ Success screen displays with green checkmark icon
- ✅ Receipt number is shown (e.g., "Receipt #REC-0001")
- ✅ "Print Receipt" button is visible
- ✅ "Download PDF" button is visible
- ✅ "New Transaction" button is visible
- ✅ NO automatic print dialog opens

**Actions:**
8. Click "Print Receipt" button

**Expected Results:**
- ✅ New browser tab opens with PDF
- ✅ Browser print dialog opens automatically
- ✅ PDF contains receipt number, items, totals, fiscal hash
- ✅ Toast notification shows "Receipt ready for printing"

---

### TC2: Advanced Payments Modal - Auto-Print Enabled
**Preconditions:**
- Company `auto_print_receipts` = `true`
- At least one product in cart
- Browser popup blocker disabled

**Steps:**
1. Navigate to POS page
2. Add products to cart
3. Click "Advanced Payments" button
4. Select payment method
5. Enter payment amount
6. Click "Complete Transaction"
7. Wait for success screen

**Expected Results:**
- ✅ Success screen appears
- ✅ Print dialog opens AUTOMATICALLY (no manual click needed)
- ✅ PDF loads in new tab
- ✅ Receipt data is correct

---

### TC3: Download PDF Functionality
**Preconditions:**
- Any payment completed successfully

**Steps:**
1. Complete a transaction (follow TC1 steps 1-7)
2. Click "Download PDF" button

**Expected Results:**
- ✅ PDF file downloads to local machine
- ✅ File name format: `receipt-{uuid}.pdf`
- ✅ Toast notification shows "Receipt downloaded successfully"
- ✅ Opening downloaded PDF shows correct content

---

### TC4: Popup Blocker Fallback
**Preconditions:**
- Company `auto_print_receipts` = `true`
- Browser popup blocker ENABLED (Chrome: Settings → Privacy → Pop-ups blocked)

**Steps:**
1. Complete a transaction
2. Observe behavior when auto-print tries to open new tab

**Expected Results:**
- ✅ Popup is blocked by browser
- ✅ PDF automatically downloads instead
- ✅ Toast shows: "Popup blocked - downloading instead"
- ✅ Downloaded PDF is correct

---

### TC5: New Transaction Flow
**Preconditions:**
- Payment completed, on success screen

**Steps:**
1. Click "New Transaction" button

**Expected Results:**
- ✅ Modal closes
- ✅ Cart is cleared
- ✅ Ready for next transaction
- ✅ Previous receipt ID is NOT reused

---

### TC6: Multiple Payment Methods (Split Payment)
**Preconditions:**
- Cart with total = 100.000 TND

**Steps:**
1. Click "Advanced Payments"
2. Select "Cash" - enter 50.000
3. Select "Card" - enter 50.000
4. Click "Complete Transaction"

**Expected Results:**
- ✅ Payment processes successfully
- ✅ Receipt shows split payment details
- ✅ Print/auto-print works correctly
- ✅ Receipt data includes both payment methods

---

### TC7: Processing State
**Preconditions:**
- Backend has artificial delay (or slow network)

**Steps:**
1. Add items to cart
2. Click "Advanced Payments"
3. Enter payment details
4. Click "Complete Transaction"
5. Observe button during processing

**Expected Results:**
- ✅ Button text changes to "Processing..."
- ✅ Button is disabled during processing
- ✅ Cannot click "Complete Transaction" multiple times
- ✅ On success, success screen appears

---

### TC8: Payment Error Handling
**Preconditions:**
- Backend endpoint will return error (disconnect network or modify code)

**Steps:**
1. Complete payment flow
2. Backend returns error

**Expected Results:**
- ✅ Error toast appears with error message
- ✅ Modal remains open (does NOT show success screen)
- ✅ Processing state resets
- ✅ User can retry payment

---

### TC9: Receipt Content Validation
**Preconditions:**
- Complete a transaction

**Steps:**
1. Print/download receipt PDF
2. Verify all content fields

**Expected Content:**
- ✅ Company name and address
- ✅ Receipt number (sequential)
- ✅ Date and time
- ✅ Cashier name
- ✅ Line items with quantities and prices
- ✅ Subtotal
- ✅ Tax amount
- ✅ Grand total
- ✅ Payment method(s)
- ✅ Fiscal hash (SHA-256)
- ✅ Chain sequence number
- ✅ Custom footer text (if configured)
- ✅ Logo (if configured)

---

### TC10: Translation (i18n) Compliance
**Preconditions:**
- Test in both English and French

**Steps:**
1. Switch language to English
2. Complete transaction
3. Verify all text on success screen
4. Switch language to French
5. Complete another transaction
6. Verify French translations

**Expected Results:**
- ✅ "Payment Successful!" (EN) / "Paiement Réussi!" (FR)
- ✅ "Receipt #" (EN) / "Reçu #" (FR)
- ✅ "Print Receipt" (EN) / "Imprimer le Ticket" (FR)
- ✅ "Download PDF" (EN) / "Télécharger le Ticket" (FR)
- ✅ "New Transaction" (EN) / "Nouvelle Transaction" (FR)
- ✅ All toast messages translated correctly

---

### TC11: Company Settings API Endpoint
**Preconditions:**
- User authenticated with valid company context

**Steps:**
1. Open browser DevTools → Network tab
2. Load POS page
3. Observe API request to `/api/v1/companies/{companyId}/pos-settings`

**Expected Results:**
- ✅ API request succeeds (200 OK)
- ✅ Response includes `auto_print_receipts` boolean
- ✅ Response includes `receipt_logo` (nullable)
- ✅ Response includes `receipt_footer` (nullable)
- ✅ Data cached for 10 minutes (no duplicate requests)

---

### TC12: Backend Route and Controller
**Backend Verification:**

```bash
# Test the endpoint
curl -X GET http://localhost:8000/api/v1/companies/{company-id}/pos-settings \
  -H "Authorization: Bearer {token}" \
  -H "X-Company-Id: {company-id}" \
  -H "Accept: application/json"
```

**Expected Response:**
```json
{
  "data": {
    "auto_print_receipts": true,
    "receipt_logo": null,
    "receipt_footer": "Thank you for your business!"
  }
}
```

---

## Performance Tests

### PT1: Auto-Print Performance
**Steps:**
1. Enable auto-print
2. Complete 10 transactions rapidly
3. Observe browser behavior

**Expected Results:**
- ✅ Each receipt prints without blocking
- ✅ No browser crashes or memory leaks
- ✅ PDF blob URLs are properly revoked

---

### PT2: Settings Cache Validation
**Steps:**
1. Load POS page
2. Check Network tab - settings request made
3. Navigate away and back to POS
4. Check Network tab again within 10 minutes

**Expected Results:**
- ✅ Settings fetched from cache (no new request)
- ✅ After 10 minutes, new request is made

---

## Edge Cases

### EC1: No Company Selected
**Steps:**
1. Clear company selection from localStorage
2. Try to load POS page

**Expected Results:**
- ✅ Settings hook does not fetch (enabled: false)
- ✅ No error thrown
- ✅ Default `autoPrintReceipts` = `false`

---

### EC2: Receipt PDF Generation Failure
**Steps:**
1. Complete transaction
2. Backend PDF generation fails (500 error)

**Expected Results:**
- ✅ Error toast shows "Failed to print receipt"
- ✅ Print button remains clickable (can retry)
- ✅ Download button remains clickable

---

### EC3: Very Long Receipt
**Steps:**
1. Add 50+ items to cart
2. Complete transaction
3. Print receipt

**Expected Results:**
- ✅ PDF renders correctly (multiple pages if needed)
- ✅ Print dialog opens
- ✅ No layout issues or text overflow

---

## Regression Tests

### RT1: Verify Sprint 1 Components Still Work
**Steps:**
1. Test `useReceiptPrint` hook independently
2. Test `ReceiptPrintButton` component in isolation
3. Verify API endpoint `/api/v1/pos/receipts/{id}/pdf` still works

**Expected Results:**
- ✅ All Sprint 1 components functional
- ✅ No breaking changes introduced

---

## Sign-Off Checklist

Before marking this feature as complete:
- [ ] All test cases (TC1-TC12) passed
- [ ] Performance tests passed
- [ ] Edge cases handled gracefully
- [ ] i18n compliance verified (EN + FR)
- [ ] Backend endpoint tested manually
- [ ] No console errors in browser
- [ ] No TypeScript errors
- [ ] ESLint passing
- [ ] Code reviewed by team member
- [ ] Documentation updated

---

## Known Limitations

1. Shift reports reprint functionality not yet implemented (separate task)
2. Offline mode not supported (requires future enhancement)
3. Custom receipt templates not yet configurable
4. Multi-company switching requires settings re-fetch

---

## Test Log

| Date | Tester | TC# | Result | Notes |
|------|--------|-----|--------|-------|
|      |        |     |        |       |
|      |        |     |        |       |
|      |        |     |        |       |
