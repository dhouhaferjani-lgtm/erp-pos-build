# Smart Payment E2E Tests - Status Report

**Date:** 2025-12-10 (Updated after rewrite)
**Test File:** `/Users/houssamr/Projects/mecanospex/apps/web/e2e/smart-payment.spec.ts`
**Status:** 0/7 Passing (Tests rewritten, new issues discovered)

---

## Update: Tests Rewritten (2025-12-10)

✅ **Architecture Fixed:** Tests now correctly follow the two-step payment flow
❌ **New Issues Discovered:** Tests still failing due to selector/mocking issues

### What Was Fixed
- All 7 tests rewritten to use two-step flow (create payment → allocation appears)
- Correct selectors based on component code review (PaymentAllocationForm, OpenInvoicesList)
- Proper API mocking with response waiting
- Removed incorrect assumptions about UI structure

### Current Failures
**Payment Allocation Tests (4 failures):**
- Issue: Can't find "Payment Allocation" heading after payment creation
- Likely Cause: Translation keys not matching, or allocation section not rendering

**Credit Note Tests (3 failures):**
- Issue: Can't find modal with `.modal-content, [role="dialog"]` selector
- Likely Cause: Modal has different structure, or takes longer to appear

---

## Executive Summary (Original Analysis)

E2E tests were created but do not match the actual UI implementation. Tests assumed a single-page flow, but the actual implementation uses a **two-step process**:

1. **Step 1:** Create payment (form with amount, method, repository, partner)
2. **Step 2:** Apply allocation (appears after payment is created)

**Status:** Tests have been rewritten but need further debugging of selectors and route mocking.

---

## Test Results

| Test # | Test Name | Status | Issue |
|--------|-----------|--------|-------|
| 1 | FIFO allocation | ❌ FAIL | Strict mode violation - multiple elements matching `/allocation/i` |
| 2 | Manual allocation | ❌ FAIL | Timeout - checkbox elements don't exist in actual UI |
| 3 | Validation error (exceeds payment) | ❌ FAIL | Timeout - checkbox elements don't exist |
| 4 | Create credit note | ❌ FAIL | Strict mode violation - duplicate "Create Credit Note" headings |
| 5 | Validate credit note amount | ❌ FAIL | Save button disabled (form validation) |
| 6 | Full refund button | ✅ PASS | Works correctly |
| 7 | Tolerance write-off preview | ❌ FAIL | Timeout - can't find invoice text |

**Pass Rate:** 14% (1/7)

---

## Root Cause Analysis

### Issue 1: Architecture Mismatch

**What Tests Assumed:**
```typescript
// Tests assumed: Navigate to /payments/new → See allocation UI immediately
await page.goto('/treasury/payments/new')
await page.getByRole('radio', { name: /fifo/i }).click() // Expected to exist immediately
```

**Actual Implementation:**
```typescript
// PaymentForm.tsx lines 431-451
{createdPaymentId && openInvoices.length > 0 && !invoiceId && (
  <PaymentAllocationForm ... />
)}
```

**Reality:** Allocation section only renders AFTER:
1. Payment is created (createdPaymentId is set)
2. Partner has open invoices (openInvoices.length > 0)
3. Not coming from a specific invoice (!invoiceId)

---

### Issue 2: Selector Problems

**Tests Used:**
- `getByRole('checkbox', { name: /INV-2025-0001/i })` - Doesn't exist
- `getByLabel(/amount.*INV-2025-0001/i)` - Structure doesn't match
- `getByText(/allocation/i)` - Matches 6 different elements (strict mode violation)

**Actual UI Structure:**
The PaymentAllocationForm uses OpenInvoicesList component which has a different structure. Need to check actual component implementation.

---

### Issue 3: Credit Note Modal

**Problem:** Two headings with identical text "Create Credit Note":
1. Modal title (from Modal component)
2. Form heading (from CreateCreditNoteForm component)

**Solution:** Use more specific selectors or remove duplicate heading.

---

## Actual UI Flow (from Code Review)

### Payment Allocation Flow

**File:** `apps/web/src/features/treasury/PaymentForm.tsx`

```
┌─────────────────────────────────────────────────┐
│ Step 1: Payment Form                            │
├─────────────────────────────────────────────────┤
│ - Amount (input)                                │
│ - Payment Method (select)                       │
│ - Repository (select)                           │
│ - Partner (select) ← Triggers openInvoices query│
│ - Payment Date (date)                           │
│ - Reference (input)                             │
│ - Notes (textarea)                              │
│                                                 │
│ [Cancel] [Save] ← Creates payment               │
└─────────────────────────────────────────────────┘
                      │
                      │ Payment created successfully
                      │ createdPaymentId is set
                      ▼
┌─────────────────────────────────────────────────┐
│ Step 2: Payment Allocation (appears dynamically)│
├─────────────────────────────────────────────────┤
│ PaymentAllocationForm component renders:        │
│                                                 │
│ - Allocation Method selection (FIFO/Due Date/Manual)
│ - OpenInvoicesList component                    │
│ - Preview Allocation button                     │
│ - AllocationPreview display                     │
│ - Apply Allocation button                       │
│                                                 │
│ [Cancel] [Apply Allocation]                     │
└─────────────────────────────────────────────────┘
```

### Credit Note Flow

**File:** `apps/web/src/features/documents/DocumentDetailPage.tsx`

```
┌─────────────────────────────────────────────────┐
│ Invoice Detail Page (Posted Invoice)            │
├─────────────────────────────────────────────────┤
│ - Invoice information                           │
│ - Line items                                    │
│ - Totals                                        │
│                                                 │
│ [Create Credit Note] ← Opens modal             │
└─────────────────────────────────────────────────┘
                      │
                      │ Button clicked
                      ▼
┌─────────────────────────────────────────────────┐
│ Modal: Create Credit Note                       │
├─────────────────────────────────────────────────┤
│ CreateCreditNoteForm component:                 │
│                                                 │
│ - Invoice information (read-only)               │
│ - Amount (input with Full Refund button)        │
│ - Reason (select dropdown)                      │
│ - Notes (textarea)                              │
│                                                 │
│ [Cancel] [Save]                                 │
└─────────────────────────────────────────────────┘
```

---

## Required Fixes

### Priority 1: Rewrite Payment Allocation Tests

Tests must follow two-step flow:

```typescript
test('should create payment with FIFO allocation', async ({ authenticatedPage: page }) => {
  // Step 1: Create payment
  await page.goto('/treasury/payments/new')

  // Fill payment form
  await page.getByLabel(/amount/i).fill('5000.00')
  await page.getByLabel(/payment method/i).selectOption('method-1')
  await page.getByLabel(/repository/i).selectOption('repo-1')
  await page.getByLabel(/partner/i).selectOption('partner-1')

  // Submit payment
  await page.getByRole('button', { name: /save/i }).click()

  // Wait for payment creation success
  await page.waitForResponse(resp =>
    resp.url().includes('/api/v1/payments') && resp.status() === 201
  )

  // Step 2: Allocation section should now appear
  await expect(page.getByText('Payment Allocation')).toBeVisible()

  // Select FIFO method
  await page.getByRole('radio', { name: /first in first out/i }).click()

  // Preview allocation
  await page.getByRole('button', { name: /preview allocation/i }).click()

  // Verify preview shows invoices
  await expect(page.getByText('INV-2025-0001')).toBeVisible()

  // Apply allocation
  await page.getByRole('button', { name: /apply allocation/i }).click()

  // Wait for navigation
  await expect(page).toHaveURL(/\/treasury\/payments/)
})
```

### Priority 2: Fix Credit Note Duplicate Heading

**Option A:** Remove duplicate heading from CreateCreditNoteForm
**Option B:** Use more specific selector in tests:
```typescript
await expect(page.locator('.modal-title').getByRole('heading', { name: /create.*credit.*note/i })).toBeVisible()
```

### Priority 3: Verify Actual Component Selectors

Before rewriting tests, need to:
1. Read OpenInvoicesList component to understand actual structure
2. Read PaymentAllocationForm to understand actual UI elements
3. Update fixture data to match expected API responses

---

## Component Files to Review

| Component | File Path | Purpose |
|-----------|-----------|---------|
| PaymentForm | `src/features/treasury/PaymentForm.tsx` | Main payment creation form |
| PaymentAllocationForm | `src/features/treasury/components/PaymentAllocationForm.tsx` | Allocation UI (Step 2) |
| OpenInvoicesList | `src/features/treasury/components/OpenInvoicesList.tsx` | Invoice selection for manual allocation |
| AllocationPreview | `src/features/treasury/components/AllocationPreview.tsx` | Preview before applying |
| CreateCreditNoteForm | `src/features/documents/components/CreateCreditNoteForm.tsx` | Credit note form |

---

## API Mocking Requirements

Tests need to mock these endpoints in sequence:

### Payment Creation Flow
1. `GET /api/v1/payment-methods` → Payment methods list
2. `GET /api/v1/partners` → Partners list
3. `GET /api/v1/payment-repositories` → Repositories list
4. `POST /api/v1/payments` → Create payment (returns payment with ID)
5. `GET /api/v1/partners/:id/open-invoices` → Open invoices for selected partner
6. `POST /api/v1/payments/:id/allocate` → Apply allocation
7. `GET /api/v1/payments/:id/preview-allocation` → Get allocation preview

### Credit Note Creation Flow
1. `GET /api/v1/invoices/:id` → Invoice details
2. `POST /api/v1/credit-notes` → Create credit note
3. `GET /api/v1/credit-notes?source_invoice_id=:id` → Credit notes list

---

## Recommendations

### For Opus (Next Session)

1. **Don't rewrite tests yet** - First verify the actual component implementations
2. **Create component exploration task** - Read all 5 component files to understand actual UI structure
3. **Document actual selectors** - Create a selector reference guide
4. **Update fixtures** - Ensure mock data matches actual API responses
5. **Rewrite tests incrementally** - Start with 1 test, verify it works, then do the rest

### Test Writing Guidelines

- **Always wait for API responses** using `page.waitForResponse()`
- **Never assume immediate rendering** - wait for elements with `toBeVisible()`
- **Use specific selectors** - Avoid `/allocation/i` that matches multiple elements
- **Test realistic flows** - Match actual user journey (create → allocate)
- **Verify error states** - Test validation before testing success paths

---

## Next Steps

1. ✅ Document test failures (this file)
2. ⏳ Read component implementation files
3. ⏳ Update test fixtures to match API responses
4. ⏳ Rewrite payment allocation tests (3 tests)
5. ⏳ Fix credit note tests (3 tests)
6. ⏳ Add tolerance write-off test (1 test)
7. ⏳ Run tests and verify all pass

---

**Status:** Tests created but not functional - architecture mismatch
**Blocker:** Tests don't match actual two-step payment flow
**Recommendation:** Pause E2E testing, complete component implementation audit first

---

**Document Version:** 1.0
**Last Updated:** 2025-12-10
**Author:** Sonnet 4.5 (Session 3)
