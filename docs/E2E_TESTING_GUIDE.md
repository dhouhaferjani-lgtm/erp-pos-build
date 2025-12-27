# End-to-End Testing Guide - AutoERP Treasury & Inventory Features

> **Purpose**: This document provides comprehensive testing scenarios for verifying credit notes, return notes, payment methods, and the complete document lifecycle including stock and financial impacts.
>
> **Target**: Claude Code with Playwright MCP installed
>
> **Last Updated**: December 2025

---

## Overview

This testing guide validates the complete business cycle from sales/purchase documents through financial postings and inventory movements. It ensures that all stock-related and finance-related operations work correctly and that data integrity is maintained across modules.

---

## Prerequisites

### 1. Environment Setup

```bash
# Ensure dev environment is running
cd /Users/houssamr/Projects/mecanospex
pnpm dev

# Backend API should be running at http://localhost:8000
# Frontend web should be running at http://localhost:5173
```

### 2. Test Data Requirements

You'll need:
- ✅ At least 1 company registered (with country code for payment methods testing)
- ✅ At least 2 products with stock (for sales/returns/credit notes)
- ✅ At least 1 customer (for sales documents)
- ✅ At least 1 supplier (for purchase documents)
- ✅ Payment methods seeded (country-specific)
- ✅ Payment repositories (cash register, bank account)
- ✅ Chart of accounts configured

### 3. Playwright MCP Configuration

Ensure Playwright MCP is installed and configured to access:
- Frontend: `http://localhost:5173`
- API (if needed): `http://localhost:8000/api/v1`

---

## Test Scenarios

### Scenario 1: Payment Methods - Country-Specific Seeding

**Objective**: Verify that payment methods are correctly seeded based on company country code.

**Test Steps**:

1. **Login** to the application
   - Navigate to `http://localhost:5173/login`
   - Enter credentials
   - Verify redirect to dashboard

2. **Navigate to Payment Methods**
   - Click on sidebar → Treasury → Payment Methods
   - URL should be: `/treasury/payment-methods`

3. **Verify France Company (FR) Payment Methods**
   - Expected count: **9 payment methods**
   - Expected methods:
     1. Espèces (CASH)
     2. Chèque (CHECK)
     3. Virement Bancaire (TRANSFER)
     4. Carte Bancaire (CARD)
     5. Prélèvement (DIRECT_DEBIT)
     6. LCR (Lettre de Change Relevé)
     7. PayPal
     8. Ticket Restaurant (MEAL_VOUCHER)
     9. Lettre de Change (BILL_EXCHANGE)

4. **Verify Payment Method Details**
   - Check CARD method has:
     - `is_physical`: false
     - `has_deducted_fees`: true
     - `fee_type`: percentage
     - `fee_percent`: 1.50%
   - Check PayPal method has:
     - `fee_type`: mixed
     - `fee_fixed`: €0.35
     - `fee_percent`: 2.90%

5. **Test Add Payment Method**
   - Click "Add Payment Method" button
   - Fill form:
     - Code: `TEST`
     - Name: `Test Payment Method`
     - Enable "Physical" checkbox
     - Fee type: None
   - Submit form
   - Verify new method appears in list
   - Verify count is now 10

6. **Test Toggle Active/Inactive**
   - Find the newly created method
   - Click "Deactivate" button
   - Verify status changes to "Inactive"
   - Click "Activate" button
   - Verify status changes to "Active"

**Expected Results**:
- ✅ Payment methods page loads without errors
- ✅ Correct number of methods for country
- ✅ Payment methods have correct French localization
- ✅ Fee configurations are accurate
- ✅ Add payment method works
- ✅ Toggle active/inactive works

---

### Scenario 2: Sales Invoice → Payment → Credit Note (Stock Decrease Verification)

**Objective**: Verify the complete sales cycle including credit note stock impact.

**Test Steps**:

#### Phase 1: Create and Post Invoice

1. **Create Sales Invoice**
   - Navigate to Sales → Invoices → New Invoice
   - Select customer
   - Add 2 products:
     - Product A: Qty 5, Price €100
     - Product B: Qty 3, Price €50
   - Note the product IDs and initial stock levels
   - Expected total: €650 (before tax)
   - Save as draft

2. **Post the Invoice**
   - Click "Post Invoice" button
   - Confirm posting
   - Note the invoice number (e.g., `INV-2025-001`)
   - Verify status changes to "Posted"

3. **Verify Stock Decrease**
   - Navigate to Inventory → Stock Levels
   - Find Product A → Verify stock decreased by 5 units
   - Find Product B → Verify stock decreased by 3 units
   - Expected: `initial_stock - sold_quantity`

4. **Verify General Ledger Entries (GL)**
   - Navigate to Finance → General Ledger
   - Search for invoice number
   - Expected GL entries:
     ```
     Debit:  Account Receivable (411/1310)    €650
     Credit: Revenue (707/4000)                €650

     Debit:  Cost of Goods Sold (607/5000)    €XXX
     Credit: Inventory (31/1200)               €XXX
     ```
   - Verify amounts match

#### Phase 2: Record Payment

5. **Record Payment for Invoice**
   - Navigate to Treasury → Payments → New Payment
   - Select the invoice (INV-2025-001)
   - Payment method: Cash (Espèces)
   - Payment repository: Cash Register
   - Amount: €650
   - Payment date: Today
   - Submit payment

6. **Verify Payment Allocation**
   - Go back to the invoice detail page
   - Verify "Paid" status
   - Verify payment appears in invoice payment history
   - Expected: `amount_paid = €650`, `balance = €0`

7. **Verify GL Entries for Payment**
   - Navigate to Finance → General Ledger
   - Search for payment reference
   - Expected GL entries:
     ```
     Debit:  Cash (512/1010)                   €650
     Credit: Account Receivable (411/1310)     €650
     ```

#### Phase 3: Create Credit Note

8. **Create Credit Note from Invoice**
   - Navigate back to invoice detail page (INV-2025-001)
   - Click "Create Credit Note" button
   - Select reason: "Product Return"
   - Return items:
     - Product A: Qty 2 (partial return)
     - Product B: Qty 3 (full return)
   - Expected credit amount: €350 (2×€100 + 3×€50)
   - Submit credit note

9. **Post the Credit Note**
   - Credit note should auto-generate number (e.g., `CN-2025-001`)
   - Click "Post Credit Note"
   - Verify status changes to "Posted"

10. **CRITICAL: Verify Stock Increase from Credit Note**
    - Navigate to Inventory → Stock Levels
    - Find Product A → Verify stock **INCREASED** by 2 units
    - Find Product B → Verify stock **INCREASED** by 3 units
    - Expected stock levels:
      ```
      Product A: initial_stock - 5 (invoice) + 2 (credit note)
      Product B: initial_stock - 3 (invoice) + 3 (credit note) = initial_stock
      ```

11. **Verify Credit Note GL Entries**
    - Navigate to Finance → General Ledger
    - Search for credit note number
    - Expected GL entries (reversal of invoice):
      ```
      Debit:  Revenue (707/4000)                €350
      Credit: Account Receivable (411/1310)     €350

      Debit:  Inventory (31/1200)               €XXX
      Credit: Cost of Goods Sold (607/5000)     €XXX
      ```

12. **Verify Invoice Balance Updated**
    - Return to original invoice page
    - Expected: `amount_paid = €650`, `amount_credited = €350`
    - Expected: `balance = -€350` (customer has credit)

**Expected Results**:
- ✅ Invoice posting decreases stock correctly
- ✅ Invoice creates correct GL entries (AR + Revenue + COGS)
- ✅ Payment records correctly and updates AR
- ✅ **Credit note INCREASES stock** (reverses the sale)
- ✅ Credit note creates reversal GL entries
- ✅ Invoice shows credit amount and negative balance

---

### Scenario 3: Return Note → Stock Movement Verification

**Objective**: Verify that return notes correctly increase stock for customer returns.

**Test Steps**:

1. **Prerequisites**
   - Have a posted delivery note or invoice with delivered items
   - Note the product ID and current stock level

2. **Create Return Note**
   - Navigate to Inventory → Return Notes → New Return Note
   - Select the original delivery note or invoice
   - Select items to return:
     - Product A: Qty 2
   - Reason: "Defective product"
   - Submit return note

3. **Post Return Note**
   - Note the return note number (e.g., `RN-2025-001`)
   - Click "Post Return Note"
   - Verify status changes to "Posted"

4. **Verify Stock Increase**
   - Navigate to Inventory → Stock Levels
   - Find Product A
   - Expected: Stock increased by 2 units
   - Formula: `new_stock = previous_stock + 2`

5. **Verify Stock Movement Log**
   - Navigate to Inventory → Stock Movements
   - Search for return note number
   - Expected movement:
     ```
     Type: Return Receipt
     Product: Product A
     Quantity: +2
     Reference: RN-2025-001
     ```

6. **Verify No GL Impact (if return note is inventory-only)**
   - Navigate to Finance → General Ledger
   - Search for return note number
   - Expected: No GL entries (unless return triggers refund)

**Expected Results**:
- ✅ Return note creation works
- ✅ Posting return note increases stock
- ✅ Stock movement log records the return
- ✅ Return note links to original document

---

### Scenario 4: Purchase Order → Goods Receipt → Stock Increase

**Objective**: Verify purchase cycle increases stock correctly.

**Test Steps**:

1. **Create Purchase Order**
   - Navigate to Purchases → Orders → New Purchase Order
   - Select supplier
   - Add product:
     - Product A: Qty 10, Unit Price €50
   - Save as draft
   - Note the PO number

2. **Confirm Purchase Order**
   - Click "Confirm Order"
   - Verify status changes to "Confirmed"

3. **Create Goods Receipt**
   - From PO detail page, click "Create Goods Receipt"
   - Receive full quantity: 10 units
   - Expected document type: "Goods Receipt"
   - Submit

4. **Post Goods Receipt**
   - Note the GR number (e.g., `GR-2025-001`)
   - Click "Post Goods Receipt"
   - Verify status changes to "Posted"

5. **Verify Stock Increase**
   - Navigate to Inventory → Stock Levels
   - Find Product A
   - Expected: Stock increased by 10 units
   - Formula: `new_stock = previous_stock + 10`

6. **Verify GL Entries for Goods Receipt**
   - Navigate to Finance → General Ledger
   - Search for GR number
   - Expected GL entries:
     ```
     Debit:  Inventory (31/1200)               €500
     Credit: GR/IV Clearing (408/2110)         €500
     ```

7. **Create and Post Supplier Invoice**
   - From GR detail page, click "Create Invoice"
   - Verify amount matches: €500
   - Post invoice

8. **Verify GL Entries for Supplier Invoice**
   - Expected GL entries:
     ```
     Debit:  GR/IV Clearing (408/2110)         €500
     Credit: Account Payable (401/2010)        €500
     ```

**Expected Results**:
- ✅ Purchase order workflow works
- ✅ Goods receipt increases stock
- ✅ GR creates correct GL entries
- ✅ Invoice clears GR/IV clearing account

---

### Scenario 5: Delivery Note → Invoice Conversion (No Double Stock Impact)

**Objective**: Verify that converting delivery note to invoice doesn't decrease stock twice.

**Test Steps**:

1. **Create Delivery Note**
   - Navigate to Inventory → Delivery Notes → New Delivery Note
   - Select customer
   - Add product:
     - Product A: Qty 5
   - Note current stock level for Product A
   - Save and post delivery note
   - Note the DN number (e.g., `DN-2025-001`)

2. **Verify Stock Decrease from Delivery Note**
   - Navigate to Inventory → Stock Levels
   - Find Product A
   - Expected: Stock decreased by 5 units
   - Record the stock level: `stock_after_dn`

3. **Convert Delivery Note to Invoice**
   - From DN detail page, click "Convert to Invoice"
   - Verify invoice pre-fills with DN data
   - Add pricing (DN might not have prices)
   - Post the invoice

4. **CRITICAL: Verify Stock NOT Decreased Again**
   - Navigate to Inventory → Stock Levels
   - Find Product A
   - Expected: Stock level unchanged from `stock_after_dn`
   - **Stock should NOT decrease again** (already decreased by DN)

5. **Verify GL Entries**
   - Invoice should create:
     ```
     Debit:  Account Receivable (411/1310)    €XXX
     Credit: Revenue (707/4000)                €XXX

     (COGS already recorded by DN, or recorded now if DN didn't)
     ```

**Expected Results**:
- ✅ Delivery note decreases stock
- ✅ Converting DN to invoice does NOT decrease stock again
- ✅ Invoice creates correct financial entries
- ✅ DN and Invoice are properly linked

---

### Scenario 6: Payment Method Fee Calculation

**Objective**: Verify payment method fees are calculated correctly.

**Test Steps**:

1. **Setup Payment Method with Fees**
   - Navigate to Treasury → Payment Methods
   - Find or create method with:
     - Fee type: Mixed
     - Fixed fee: €0.50
     - Percentage fee: 2.00%

2. **Create Invoice**
   - Amount: €100
   - Post invoice

3. **Record Payment with Fee-Based Method**
   - Navigate to Treasury → Payments
   - Select invoice
   - Payment method: [Fee-based method]
   - Payment amount: €100

4. **Verify Fee Calculation**
   - Expected fee calculation:
     ```
     Fixed fee: €0.50
     Percentage fee: €100 × 2.00% = €2.00
     Total fee: €2.50
     Net received: €97.50
     ```

5. **Verify GL Entries**
   - Expected GL entries:
     ```
     Debit:  Cash (512/1010)                   €97.50
     Debit:  Bank Fees (627/6XXX)              €2.50
     Credit: Account Receivable (411/1310)     €100.00
     ```

**Expected Results**:
- ✅ Fee calculation is accurate
- ✅ GL entries reflect net amount and fees
- ✅ Invoice shows full payment despite fee

---

### Scenario 7: Multi-Currency Payment (if supported)

**Objective**: Verify multi-currency handling in payments.

**Test Steps**:

1. **Create Invoice in Foreign Currency**
   - If multi-currency is enabled
   - Create invoice in USD: $150
   - Exchange rate: 1 USD = 0.92 EUR

2. **Record Payment**
   - Payment in EUR: €138
   - Verify exchange rate applied
   - Verify no exchange gain/loss (or correct amount)

**Expected Results**:
- ✅ Currency conversion works
- ✅ Exchange rate applied correctly
- ✅ GL entries in base currency (EUR)

---

### Scenario 8: Stock Adjustment → GL Impact

**Objective**: Verify manual stock adjustments create correct GL entries.

**Test Steps**:

1. **Record Stock Adjustment**
   - Navigate to Inventory → Stock Adjustments
   - Select Product A
   - Adjustment type: Increase
   - Quantity: +5
   - Reason: "Found during inventory count"
   - Submit and post

2. **Verify Stock Level**
   - Expected: Stock increased by 5 units

3. **Verify GL Entries**
   - Expected GL entries:
     ```
     Debit:  Inventory (31/1200)               €XXX (avg cost × 5)
     Credit: Stock Adjustment (713/6XXX)       €XXX
     ```

**Expected Results**:
- ✅ Stock adjustment updates stock
- ✅ GL entries created with correct accounts
- ✅ Adjustment appears in stock movement log

---

### Scenario 9: Inventory Counting → Reconciliation

**Objective**: Verify inventory counting and reconciliation process.

**Test Steps**:

1. **Create Inventory Counting**
   - Navigate to Inventory → Counting → New Counting
   - Select location: Main Warehouse
   - Select products to count
   - Generate counting sheet
   - Assign to user

2. **Perform Count**
   - Enter counted quantities:
     - Product A: 50 (vs system 48)
     - Product B: 30 (vs system 35)

3. **Review Discrepancies**
   - Expected discrepancies shown:
     - Product A: +2 (surplus)
     - Product B: -5 (shortage)

4. **Approve and Post Adjustments**
   - Approve the count
   - Post adjustments

5. **Verify Stock Updates**
   - Product A: Stock increased by 2
   - Product B: Stock decreased by 5

6. **Verify GL Entries**
   - Expected entries for adjustments:
     ```
     Product A (surplus):
     Debit:  Inventory                         +€XXX
     Credit: Stock Adjustment                  +€XXX

     Product B (shortage):
     Debit:  Stock Adjustment                  €XXX
     Credit: Inventory                         €XXX
     ```

**Expected Results**:
- ✅ Counting workflow works
- ✅ Discrepancies calculated correctly
- ✅ Stock adjustments applied
- ✅ GL entries created

---

### Scenario 10: Payment Allocation → Multiple Invoices

**Objective**: Verify payment can be allocated across multiple invoices.

**Test Steps**:

1. **Create Two Invoices**
   - Invoice 1: €300
   - Invoice 2: €200
   - Post both invoices

2. **Record Single Payment**
   - Amount: €500
   - Navigate to payment allocation
   - Allocate:
     - Invoice 1: €300 (full payment)
     - Invoice 2: €200 (full payment)

3. **Verify Allocation**
   - Invoice 1: Balance €0
   - Invoice 2: Balance €0
   - Both marked as "Paid"

4. **Verify GL Entries**
   - Single payment entry:
     ```
     Debit:  Cash (512/1010)                   €500
     Credit: Account Receivable (411/1310)     €500
     ```

**Expected Results**:
- ✅ Payment allocation to multiple invoices works
- ✅ Invoice balances updated correctly
- ✅ GL entries are consolidated

---

## Integration Test: Complete Sales-to-Cash Cycle

**Objective**: End-to-end test from quote to cash collection.

### Full Cycle Steps:

1. **Quote** → Create quote for customer (€1,000)
2. **Sales Order** → Convert quote to sales order
3. **Delivery Note** → Create and post delivery note (stock ↓)
4. **Invoice** → Convert delivery note to invoice
5. **Payment** → Record payment (€1,000)
6. **Partial Return** → Customer returns 20% of goods
7. **Credit Note** → Issue credit note (€200, stock ↑)
8. **Refund** → Issue refund payment to customer (€200)

### Verification Points:

- ✅ Quote → Order conversion preserves data
- ✅ Delivery note decreases stock correctly
- ✅ Invoice does NOT double-decrease stock
- ✅ Payment clears invoice AR
- ✅ Credit note increases stock
- ✅ Credit note reduces AR
- ✅ Refund payment processed correctly
- ✅ All GL entries balanced
- ✅ Stock levels accurate at each step
- ✅ Document chain traceable (quote → order → DN → invoice → CN)

---

## Critical Verifications Summary

### Stock Operations - Must Always Work:

| Operation | Expected Stock Impact |
|-----------|----------------------|
| **Sales Invoice (posted)** | Decrease ↓ |
| **Credit Note (posted)** | **Increase ↑** |
| **Return Note (posted)** | Increase ↑ |
| **Delivery Note (posted)** | Decrease ↓ |
| **DN → Invoice conversion** | No change (already decreased) |
| **Goods Receipt (posted)** | Increase ↑ |
| **Stock Adjustment** | ± as specified |
| **Inventory Count Reconciliation** | ± based on discrepancy |

### Financial Operations - Must Always Work:

| Operation | Expected GL Impact |
|-----------|-------------------|
| **Invoice (posted)** | DR: AR, CR: Revenue + DR: COGS, CR: Inventory |
| **Payment (received)** | DR: Cash, CR: AR |
| **Credit Note (posted)** | DR: Revenue, CR: AR + DR: Inventory, CR: COGS |
| **Supplier Invoice** | DR: GR/IV Clearing, CR: AP |
| **Payment (made)** | DR: AP, CR: Cash |
| **Goods Receipt** | DR: Inventory, CR: GR/IV Clearing |

---

## Common Issues to Watch For

### Stock Issues:
- ❌ **Double stock decrease**: DN + Invoice both decreasing stock
- ❌ **Credit note not increasing stock**: Critical bug
- ❌ **Return note not increasing stock**: Critical bug
- ❌ **Negative stock allowed when it shouldn't be**
- ❌ **Stock movement log missing entries**

### Financial Issues:
- ❌ **Unbalanced GL entries**: Debits ≠ Credits
- ❌ **Wrong accounts used**: Revenue in COGS account
- ❌ **Missing COGS entries**: Inventory impact without GL
- ❌ **Payment not clearing AR/AP**
- ❌ **Fee calculation errors**
- ❌ **Credit note not reversing revenue**

### Document Flow Issues:
- ❌ **Lost document chain**: Can't trace quote → invoice
- ❌ **Conversion errors**: Data lost during conversion
- ❌ **Status not updating**: Document stuck in "draft"
- ❌ **Duplicate numbering**: Sequential numbers broken

---

## Testing Commands Reference

### Playwright MCP Commands (Examples)

```typescript
// Navigate and login
await page.goto('http://localhost:5173/login')
await page.fill('[name="email"]', 'admin@example.com')
await page.fill('[name="password"]', 'password')
await page.click('button[type="submit"]')

// Navigate to payment methods
await page.click('text=Treasury')
await page.click('text=Payment Methods')
await page.waitForURL('**/treasury/payment-methods')

// Verify payment method count
const methodRows = await page.locator('table tbody tr').count()
expect(methodRows).toBe(9) // For France

// Check stock level
await page.goto('http://localhost:5173/inventory/stock')
const productRow = await page.locator(`tr:has-text("${productName}")`)
const stockValue = await productRow.locator('td:nth-child(3)').textContent()

// Create invoice
await page.click('text=New Invoice')
await page.selectOption('[name="customer_id"]', customerId)
// ... add lines
await page.click('button:has-text("Save")')
await page.click('button:has-text("Post Invoice")')

// Verify GL entries
await page.goto('http://localhost:5173/finance/ledger')
await page.fill('[name="search"]', invoiceNumber)
// Verify debit/credit entries
```

---

## Test Report Template

After running tests, provide a report in this format:

```markdown
# AutoERP E2E Test Report
Date: YYYY-MM-DD
Tester: Claude Code + Playwright MCP

## Summary
- Total Scenarios: 10
- Passed: X
- Failed: Y
- Skipped: Z

## Detailed Results

### ✅ Scenario 1: Payment Methods
- Status: PASSED
- Notes: All 9 France payment methods present, fees calculated correctly

### ❌ Scenario 2: Credit Note Stock Impact
- Status: FAILED
- Issue: Credit note did not increase stock
- Expected: Stock +3 units
- Actual: Stock unchanged
- Location: apps/api/app/Modules/Document/Domain/Services/CreditNoteService.php:XX

## Critical Issues
1. [CRITICAL] Credit notes not increasing stock - apps/api/path/to/file.php:line
2. [HIGH] GL entries unbalanced for credit notes

## Recommendations
1. Fix credit note stock reversal logic
2. Add stock movement validation
3. Enhance GL entry validation

## Test Evidence
- Screenshots: [attached]
- Stock levels before/after: [table]
- GL report: [attached]
```

---

## Notes for Claude Code

When running these tests:

1. **Take screenshots** at key verification points (stock levels, GL entries)
2. **Record actual values** vs expected values
3. **Note any error messages** or console errors
4. **Check database state** if UI doesn't show expected results
5. **Test both happy path and edge cases** (e.g., insufficient stock, overpayment)
6. **Verify data consistency** across modules (inventory vs finance)
7. **Test permissions** if applicable (can non-admin users do these operations?)

Good luck with testing! 🎯
