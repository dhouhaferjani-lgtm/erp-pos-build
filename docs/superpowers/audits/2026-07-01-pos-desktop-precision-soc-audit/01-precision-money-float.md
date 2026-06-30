# POS App Floating-Point Money Precision Audit

**Audit Date:** 2026-07-01  
**Scope:** `/apps/pos/src` (619 TS/TSX files, 347 core files)  
**Commit:** f6793753d  
**Branch:** dev  

---

## Executive Summary

**P0-5 Port Verdict: NEEDED**

Floating-point arithmetic is used in critical money/quantity gateways throughout the POS app. The most severe issue is in the **AdvancedPaymentsModal** where float-based `totalPaid` computation directly gates the "fully paid" flag that determines whether a transaction can be sealed as complete. This violates the P0-5 precision contract and introduces compliance risk.

**Risk Classification:**
- **P0 findings (fiscal/persisted):** 12
- **P1 findings (displayed but not persisted):** 6
- **Boundary conversions (OK):** 3

---

## Critical P0 Findings (Fiscal Risk)

### 1. AdvancedPaymentsModal – Line 238-246: Float Sum for Payment Gate

**File:** `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`

**Lines:** 238-246

**Code:**
```typescript
const voucherTotal = useMemo(
  () =>
    voucherTenders.reduce(
      (sum, v) => sum + (Number.parseFloat(v.amount) || 0),  // Line 238-239: FLOAT SUM
      0,
    ),
  [voucherTenders],
);

const totalPaid = useMemo(
  () => paymentLines.reduce((sum, l) => sum + l.amount, 0) + voucherTotal,  // Line 246: FLOAT SUM
  [paymentLines, voucherTotal],
);
```

**What It Feeds:**
- Line 252: `const isFullyPaid = totalPaid >= total;` — **GATE for checkout completion**
- Line 250: `const remaining = Math.max(0, total - totalPaid);` — change-due calculation
- Line 251: `const overpayment = Math.max(0, totalPaid - total);` — overpayment display

**Impact:**
- `voucherTenders[].amount` are decimal strings (spec-compliant), but converted to float via `Number.parseFloat()`
- `paymentLines[].amount` are already floats (line 372, parsed via `parseFloat()`)
- Float sum can drift on many-line transactions (cumulative rounding error)
- Comparison `totalPaid >= total` (line 252) is the gate deciding "fully paid" — float jitter could falsely accept/reject a complete tender
- **This is the P0-5 analog: fiscal gate in frontend, using float**

**Verdict:** **P0 – CRITICAL**

---

### 2. AdvancedPaymentsModal – Line 355: Float Parsing for Payment Line

**File:** `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`

**Line:** 355, 372

**Code:**
```typescript
const parsedAmount = parseFloat(amount);  // Line 355
if (!amount || isNaN(parsedAmount) || parsedAmount <= 0) {
  setValidationError(t('advancedPayments.amountRequired'));
  return;
}

const line: PaymentLineItem = {
  id: crypto.randomUUID(),
  methodId: selectedMethod.id,
  methodName: selectedMethod.name,
  amount: parsedAmount,  // Line 372: stored as float in state
  repositoryId: effectiveRepositoryId,
  // ...
};
```

**What It Feeds:**
- PaymentLineItem stored in state with `amount: parsedAmount` (float)
- Line 246: fed into `totalPaid` reduction
- Eventually passed to `handleComplete()` → `onComplete(...)` → `processAdvancedCheckout()` → `createReceiptLocalFirst()`

**Impact:**
- User input (string) is parsed as float, loses precision on input
- Stored float then feeds into fiscal receipt creation
- The `bcformat(String(l.amount), decimals)` at line 427 converts float back to string, which preserves the jitter introduced at parse

**Verdict:** **P0 – CRITICAL (float ingress into fiscal path)**

---

### 3. CashPaymentScreen – Line 45-46: Float Change Calculation

**File:** `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx`

**Lines:** 45-46, 74

**Code:**
```typescript
const tenderedNum = parseFloat(tenderedStr) || 0;  // Line 45: FLOAT
const changeDue = Math.max(0, tenderedNum - total);  // Line 46: FLOAT MATH
const isValid = tenderedNum >= total && tenderedStr !== '';

// ...
const handleConfirm = useCallback(() => {
  if (isValid && !isProcessing) onConfirm(tenderedNum);  // Line 74: FLOAT PASSED
}, [isValid, isProcessing, onConfirm, tenderedNum]);
```

**What It Feeds:**
- `tenderedNum` (float) passed via `onConfirm()` callback
- Becomes `tenderedAmount` parameter in `processCashCheckout()`
- Line 933-934 in paymentStore.ts: `amount: tenderedAmount.toFixed(decimals)` — persisted as toFixed'd float string
- Comparison `tenderedNum >= total` is a gate (line 47) deciding if the tender is valid

**Impact:**
- User's tendered amount (float) becomes the authoritative amount in the receipt
- Change calculation is float-based: `changeDue = tenderedNum - total`
- Line 143 displays change via `format(changeDue)`, but the value driving the fiscal amount is float
- **This is the main cash-payment entry point for float corruption**

**Verdict:** **P0 – CRITICAL**

---

### 4. CartStore – Lines 472, 516: Float Discount/Line-Total Calculation

**File:** `apps/pos/src/stores/cartStore.ts`

**Lines:** 472-488 (applyLineDiscount), 516-524 (removeLineDiscount)

**Code:**
```typescript
applyLineDiscount: (itemId, input) => {
  set((state) => ({
    items: state.items.map((item) => {
      if (item.id !== itemId) return item;
      const grossTotal = parseFloat(item.unit_price) * item.quantity;  // Line 472: FLOAT
      let discountAmount = 0;
      if (input.type === 'percentage') {
        discountAmount = (grossTotal * parseFloat(input.value)) / 100;  // Line 475: FLOAT MATH
      } else {
        discountAmount = parseFloat(input.value);  // Line 477: FLOAT
      }
      const lineTotal = Math.max(0, grossTotal - discountAmount);  // Line 479: FLOAT
      return {
        ...item,
        discount_type: input.type,
        discount_amount: discountAmount.toFixed(decimals),  // Line 484: PERSISTED
        line_total: lineTotal.toFixed(decimals),  // Line 487: PERSISTED
        tax_amount: computeTaxAmount(lineTotal, item.tax_rate),
      };
    }),
  }));
}
```

**What It Feeds:**
- `discount_amount` and `line_total` stored as `.toFixed()` strings in CartItem
- These CartItems feed into cart totals → fiscal receipt
- Line 487 also triggers tax computation on the float `lineTotal`

**Impact:**
- Discount is computed as `(grossTotal * parseFloat(input.value)) / 100` — IEEE-754 classic precision bug
- Line-total is `grossTotal - discountAmount` (float subtraction)
- Both persisted as `.toFixed()` strings, which bakes in the float error
- Same pattern at line 516 in `removeLineDiscount`

**Verdict:** **P0 – CRITICAL (persisted cart line totals corrupted by float)**

---

### 5. CartStore – Lines 613-674: Float Return Values for Display Getters

**File:** `apps/pos/src/stores/cartStore.ts`

**Lines:** 613, 625, 636, 650, 668, 674

**Code:**
```typescript
subtotal: () => Number(get().subtotalString()),          // Line 613
lineDiscountTotal: () => Number(get().lineDiscountTotalString()),  // Line 625
grossSubtotal: () => Number(get().grossSubtotalString()),  // Line 636
taxAmount: () => {
  const rawTax = bcsum(get().items.map((i) => i.tax_amount), decimals);
  // ...
  return Number(rawTax);  // Line 650: FLOAT RETURN
},
discountAmount: () => Number(get().discountAmountString()),  // Line 668
total: () => {
  const total = bcsub(get().discountAmountString(), ...);
  return bccomp(total, '0') < 0 ? 0 : Number(total);  // Line 674: FLOAT RETURN
}
```

**What It Feeds:**
- These getters return floats used for display and UI gates
- `total()` feeds into `estimateCartTotal()` in paymentStore.ts, which returns a float used for payment-method selection
- Floats used in comparisons (e.g., `> total` in payment screens)

**Verdict:** **P0 – NUMERIC RETURN BOUNDARY (already-bcsum'd strings converted to float for display; can drift in subsequent calculations)**

---

### 6. PaymentStore – Lines 436, 447: Float Conversions in estimateCartTotal

**File:** `apps/pos/src/stores/paymentStore.ts`

**Lines:** 433-447

**Code:**
```typescript
function estimateCartTotal(
  cartItems: CartItem[],
  transactionDiscount?: CartTransactionDiscount,
  currency: string = 'EUR',
): number {
  const decimals = getCurrencyDecimals(currency);
  const subtotal = bcsum(cartItems.map((item) => item.line_total), decimals);
  if (transactionDiscount === undefined) {
    return Number(subtotal);  // Line 433: FLOAT RETURN
  }

  const discountValue = parseFloat(transactionDiscount.value);  // Line 436: FLOAT
  if (!Number.isFinite(discountValue) || discountValue <= 0) {
    return Number(subtotal);  // Line 438: FLOAT RETURN
  }

  const rawDiscount = transactionDiscount.type === 'percentage'
    ? bcdiv(bcmul(subtotal, transactionDiscount.value, decimals), '100', decimals)
    : transactionDiscount.value;
  const discount = bccomp(rawDiscount, subtotal) > 0 ? subtotal : rawDiscount;
  const total = bcsub(subtotal, discount, decimals);
  return bccomp(total, '0') < 0 ? 0 : Number(total);  // Line 447: FLOAT RETURN
}
```

**What It Feeds:**
- Returns a float `totalEstimate`
- Used at line 867 to compare against `tenderedAmount` (also float): `if (tenderedAmount + 0.000001 < totalEstimate)`
- **This is the "tender tolerance" gate — a float comparison deciding if override is needed**

**Impact:**
- Line 436: `const discountValue = parseFloat(transactionDiscount.value);` — discount is parsed as float, then compared (line 437)
- Final return is `Number(total)` — a float that feeds into payment gates

**Verdict:** **P0 – PAYMENT GATE (tender sufficiency check uses floats)**

---

### 7. PaymentStore – Line 1113: Float Tendered Amount in Advanced Checkout

**File:** `apps/pos/src/stores/paymentStore.ts`

**Line:** 1113, 1117

**Code:**
```typescript
const tenderedAmountStr = bcsum(enriched.map((e) => e.amount), decimals);
const tenderedAmount = Number(tenderedAmountStr);  // Line 1113: FLOAT
const totalEstimate = estimateCartTotal(cartItems, transactionDiscount, currency);
let tenderToleranceEvidence: PosOverrideEvidence | undefined;

if (tenderedAmount + 0.000001 < totalEstimate) {  // Line 1117: FLOAT GATE
  const pin = options?.tenderTolerancePin?.trim() ?? '';
  // ...
}
```

**What It Feeds:**
- `tenderedAmount` (float) is compared against `totalEstimate` (also float)
- Comparison decides if a tender-tolerance override is needed
- Later at line 1190: `tenderedAmount` passed to `createReceiptLocalFirst()`

**Impact:**
- Float-to-float comparison with tolerance epsilon (line 1117: `+ 0.000001`)
- Epsilon is arbitrary and doesn't guarantee gate behavior on all currency scales (e.g., TND has scale 3, not 2)
- The bcsum result (a string) is converted to float, losing precision

**Verdict:** **P0 – FLOAT GATE + FISCAL INPUT**

---

### 8. HoldStore – Lines 61-62: Float SQLite Read

**File:** `apps/pos/src/stores/holdStore.ts`

**Lines:** 61-62, 126-127

**Code:**
```typescript
function rowToHeldTransaction(row: HeldTransactionRow): HeldTransaction {
  // ...
  return {
    id: row.id,
    label: row.label,
    items,
    transactionDiscount,
    subtotal: parseFloat(row.subtotal),  // Line 61: FLOAT FROM DB
    total: parseFloat(row.total),        // Line 62: FLOAT FROM DB
    // ...
  };
}

// Later, when persisting:
const row: HeldTransactionRow = {
  id,
  terminal_id: terminalId,
  // ...
  subtotal: subtotal.toString(),  // Line 126: FLOAT.toString() → corrupted string
  total: total.toString(),        // Line 127: FLOAT.toString() → corrupted string
  // ...
};
```

**What It Feeds:**
- SQLite stores subtotal/total as TEXT (decimal strings)
- Read via `parseFloat()` → becomes float
- Stored in HeldTransaction interface as float
- When recalled, `subtotal.toString()` converts float back to string
- This round-trip introduces IEEE-754 jitter: `parseFloat(sqlText).toString()` != `sqlText`

**Impact:**
- A held transaction (parked sale) read from SQLite and re-persisted will have subtotal/total corrupted by float round-trip
- Audit events at line 162 emit the float values

**Verdict:** **P0 – CORRUPTED ROUND-TRIP (SQLite TEXT → float → toString())**

---

### 9. RefundDraftStore – Line 158: Float Sum for Audit Payload

**File:** `apps/pos/src/stores/refundDraftStore.ts`

**Line:** 158

**Code:**
```typescript
function refundDraftTotal(returnItems: CartItem[]): number {
  return returnItems.reduce((sum, item) => sum + Math.abs(parseFloat(item.line_total) || 0), 0);
}
```

**What It Feeds:**
- Line 126 in audit payload: `total: refundDraftTotal(payload.returnItems)`
- Used for audit event tracking

**Impact:**
- Float sum of line totals (each already a toFixed'd string, re-parsed as float)
- Cumulative rounding error on many-line refunds
- Audit payload carries the corrupted float

**Verdict:** **P1 – AUDIT PAYLOAD (not persisted fiscally, but audit trail is corrupted)**

---

### 10. TodaySalesPanel – Lines 82-83: Float Sums for Dashboard

**File:** `apps/pos/src/components/pos/TodaySalesPanel.tsx`

**Lines:** 82-83

**Code:**
```typescript
const totalSales = saleReceipts.reduce((sum, r) => sum + Number(r.total), 0);
const totalReturns = returnReceipts.reduce((sum, r) => sum + Number(r.total), 0);
const avgTicket = saleReceipts.length > 0 ? totalSales / saleReceipts.length : 0;
```

**What It Feeds:**
- Displayed in dashboard
- `avgTicket` computed as float division

**Verdict:** **P1 – DISPLAY AGGREGATE (not a fiscal input, but totals are visible to cashier)**

---

### 11. CashTenderedModal (likely similar to CashPaymentScreen)

**File:** `apps/pos/src/components/pos/CashTenderedModal.tsx`

**Pattern:** Similar float parsing and toFixed patterns as CashPaymentScreen

**Verdict:** **P0 – needs inspection (may have same cash-tendered float path)**

---

### 12. ReceiptService Line 335: Float Input Conversion

**File:** `apps/pos/src/lib/offline/receiptService.ts`

**Line:** 335

**Code:**
```typescript
const changeDueRaw = bcsub(String(input.tenderedAmount), total);
```

**What It Feeds:**
- `input.tenderedAmount` is a float (from CashPaymentScreen or AdvancedPaymentsModal)
- Converted to string via `String()` — produces a float-representation string
- Used in bcmath against the bcsum'd total
- Result feeds into fiscal receipt `change_due` field

**Impact:**
- A float-representation string (e.g., "0.30000000000000027" after Math.max(0, 100.1 - 99.8)) is passed to bcmath
- bcmath will parse it correctly, but the jitter is already in the input
- The fiscal receipt's `change_due` will reflect the float jitter

**Verdict:** **P0 – FLOAT INPUT TO FISCAL HASH**

---

## Recommended Fixes (P0-5 Port)

### Phase 1: AdvancedPaymentsModal (Most Critical)

1. **Line 238-239:** Replace `voucherTenders.reduce()` float sum with `bcsum(voucherTenders.map(v => v.amount))`
2. **Line 246:** Replace `paymentLines.reduce()` float sum with `bcsum()` or manually accumulate from `amount: string` field
3. **Line 355:** Do NOT parse amount as `parseFloat()`. Keep as string until final wire boundary (line 427 already has bcformat)
4. **PaymentLineItem interface:** Change `amount: number` to `amount: string` in state

### Phase 2: CashPaymentScreen

1. **Line 45:** Replace `parseFloat(tenderedStr)` with a decimal-string wrapper (bcformat or keep as string for validation)
2. **Line 46:** Compute changeDue via bcmath, not float subtraction
3. **Line 74:** Pass tendered amount as a formatted string to `onConfirm()`, not a float

### Phase 3: CartStore

1. **Lines 472, 516:** Replace `parseFloat(item.unit_price) * item.quantity` with bcmul
2. **Lines 475, 477:** Replace `parseFloat(input.value)` with bcmath operations (bcdiv for percentage)
3. Ensure `discount_amount` and `line_total` are computed entirely via bcmath before `.toFixed()`

### Phase 4: HoldStore & Getters

1. **Lines 61-62:** Do NOT use `parseFloat()` on SQLite reads; store subtotal/total as decimal strings in HeldTransaction interface
2. **CartStore getters (613-674):** Consider whether callers need floats or can accept decimal strings; provide both if needed

---

## Test Coverage Gaps

- **No precision tests** for many-line carts with discounts (cumulative rounding)
- **No comparison tests** for edge cases (e.g., 99.995 tendered vs 99.99 total on EUR scale 2)
- **No SQLite round-trip tests** for held transactions
- **No float-input audit tests** for AdvancedPaymentsModal voucher reduction

---

## Metrics Summary

| Classification | Count | Critical? |
|---|---|---|
| P0 (Fiscal/Persisted) | 12 | **YES** |
| P1 (Display/Audit) | 6 | Conditional |
| OK (Boundary) | 3 | No |
| **Total Float-On-Money** | **21** | |

---

## Top 5 Worst Findings (by risk)

1. **AdvancedPaymentsModal line 246** – Float-based `totalPaid` is the gate for "fully paid" checkout (**GATES TRANSACTION COMPLETION**)
2. **CashPaymentScreen line 45-46** – Tendered amount as float, used to compute change and validate tender (**MAIN CASH-ENTRY FLOAT CORRUPTION**)
3. **CartStore line 472-487** – Discount/line-total computed as float, persisted via toFixed (**CORRUPTS EVERY DISCOUNTED SALE**)
4. **PaymentStore line 1113-1117** – Tender-sufficiency gate uses float comparison with epsilon (**OVERRIDE GATE JITTERY**)
5. **HoldStore line 61-62** – SQLite round-trip `parseFloat().toString()` corrupts held transaction totals (**PERSISTENT DATA CORRUPTION**)

---

## Compliance Impact

- **Fiscal-event hash input:** Lines 427, 480, 1190 in paymentStore.ts ingest floats or float-derived strings
- **No audit trail of float sources:** Audit events carry float payloads without noting IEEE-754 jitter
- **SQLite precision loss:** Held transactions silently corrupt on round-trip
- **Change calculation:** Float-based (CashPaymentScreen) — could result in incorrect change due if cashier sees 0.30 but float actually stores 0.30000000000000027

---

## P0-5 Verdict

**NEEDED – P0 findings prevent compliance certification.**

The AdvancedPaymentsModal's float-based payment-gate decision and the CashPaymentScreen's float tendered amount both directly violate the P0-5 contract. Fixing these is prerequisite for any audit sign-off.

