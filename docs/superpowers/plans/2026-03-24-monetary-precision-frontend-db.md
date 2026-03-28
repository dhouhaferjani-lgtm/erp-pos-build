# Monetary Precision: Frontend & DB Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate IEEE 754 floating-point precision errors from all frontend monetary calculations and widen remaining DB columns to support 3-decimal currencies (TND).

**Architecture:** Replace `parseFloat()` + native JS arithmetic in `lib/decimal.ts` with `big.js` arbitrary-precision library (same API signatures, zero consumer changes). Replace ~100 hardcoded `.toFixed(2)` calls with currency-aware `decimals` from `useCurrency()`. Convert POS `parseFloat` + `reduce` accumulation loops to use `bcadd`. Widen 8 remaining `decimal(X,2)` DB columns to `decimal(X,3)`.

**Tech Stack:** big.js (6KB), Vitest, Laravel migrations, TypeScript strict

**Spec:** `docs/superpowers/specs/2026-03-24-monetary-precision-frontend-db-design.md`

**Conventions:**
- Read `docs/conventions/README.md` before starting
- TDD: write failing test first, verify it fails, implement, verify it passes
- All user-facing text uses `t()` translation keys
- Atomic design: atoms/molecules/organisms in frontend
- Hexagonal architecture in backend

---

## Task 1: DB Migration — Widen Remaining Monetary Columns

**Files:**
- Create: `apps/api/database/migrations/2026_03_24_100000_widen_remaining_monetary_columns_to_scale_3.php`

- [ ] **Step 1: Create migration file**

```bash
cd apps/api && php artisan make:migration widen_remaining_monetary_columns_to_scale_3
```

- [ ] **Step 2: Write migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('line_tax_amount', 15, 3)->nullable()->change();
        });

        Schema::table('loyalty_enrollments', function (Blueprint $table) {
            $table->decimal('current_balance', 15, 3)->default(0)->change();
            $table->decimal('lifetime_earned', 15, 3)->default(0)->change();
            $table->decimal('lifetime_redeemed', 15, 3)->default(0)->change();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->decimal('fee_fixed', 10, 3)->default(0)->change();
        });

        // price_yearly lives on the `plans` table (added by create_subscription_plans migration)
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_monthly', 10, 3)->nullable()->change();
            $table->decimal('price_yearly', 10, 3)->nullable()->change();
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->decimal('price', 10, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('line_tax_amount', 15, 2)->nullable()->change();
        });

        Schema::table('loyalty_enrollments', function (Blueprint $table) {
            $table->decimal('current_balance', 15, 2)->default(0)->change();
            $table->decimal('lifetime_earned', 15, 2)->default(0)->change();
            $table->decimal('lifetime_redeemed', 15, 2)->default(0)->change();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->decimal('fee_fixed', 10, 2)->default(0)->change();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price_monthly', 10, 2)->nullable()->change();
            $table->decimal('price_yearly', 10, 2)->nullable()->change();
        });

        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->change();
        });
    }
};
```

NOTE: nullable/default values verified against original create migrations. `plans.price_monthly`, `plans.price_yearly`, and `tenant_subscriptions.price` are all `nullable()`. `loyalty_enrollments` balances and `payment_methods.fee_fixed` have `default(0)`.

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 4: Verify columns**

```bash
php artisan tinker --execute="echo Schema::getColumnType('documents', 'line_tax_amount');"
```

- [ ] **Step 5: Commit**

```bash
git add apps/api/database/migrations/*widen_remaining*
git commit -m "fix(db): widen remaining monetary columns to decimal scale 3 for TND support"
```

---

## Task 2: Install big.js and Write Failing Precision Tests

**Files:**
- Create: `apps/web/src/lib/decimal.test.ts`
- Modify: `apps/web/package.json` (via pnpm add)

- [ ] **Step 1: Install big.js**

```bash
cd apps/web && pnpm add big.js && pnpm add -D @types/big.js
```

- [ ] **Step 2: Write failing tests that prove current implementation has precision bugs**

Create `apps/web/src/lib/decimal.test.ts`:

```typescript
import { describe, it, expect } from 'vitest'
import { bcadd, bcsub, bcmul, bcdiv, bccomp, formatCurrency, calculateDiscountAmount, applyDiscount } from './decimal'

describe('decimal precision', () => {
  describe('bcadd', () => {
    it('adds 0.1 + 0.2 without IEEE 754 error', () => {
      expect(bcadd('0.1', '0.2', 3)).toBe('0.300')
    })

    it('adds 0.1 + 0.7 without IEEE 754 error', () => {
      expect(bcadd('0.1', '0.7', 3)).toBe('0.800')
    })

    it('handles TND price addition', () => {
      expect(bcadd('5.000', '3.500', 3)).toBe('8.500')
    })

    it('handles large values', () => {
      expect(bcadd('999999.999', '0.001', 3)).toBe('1000000.000')
    })

    it('handles negative values', () => {
      expect(bcadd('-5.000', '3.000', 3)).toBe('-2.000')
    })

    it('handles empty string as zero', () => {
      expect(bcadd('', '5.000', 3)).toBe('5.000')
      expect(bcadd('5.000', '', 3)).toBe('5.000')
    })
  })

  describe('bcsub', () => {
    it('subtracts without IEEE 754 error', () => {
      expect(bcsub('1.000', '0.100', 3)).toBe('0.900')
    })

    it('handles TND price subtraction', () => {
      expect(bcsub('10.000', '5.500', 3)).toBe('4.500')
    })
  })

  describe('bcmul', () => {
    it('multiplies without IEEE 754 error', () => {
      expect(bcmul('0.1', '0.2', 4)).toBe('0.0200')
    })

    it('multiplies unit price by quantity (POS hot path)', () => {
      expect(bcmul('9.990', '2', 3)).toBe('19.980')
      expect(bcmul('4.990', '3', 3)).toBe('14.970')
    })

    it('handles 1.005 * 100 correctly', () => {
      // This is a known IEEE 754 failure: 1.005 * 100 = 100.49999999999999
      expect(bcmul('1.005', '100', 2)).toBe('100.50')
    })
  })

  describe('bcdiv', () => {
    it('divides without IEEE 754 error', () => {
      expect(bcdiv('10.000', '3', 3)).toBe('3.333')
    })

    it('throws on division by zero', () => {
      expect(() => bcdiv('10.000', '0', 3)).toThrow('Division by zero')
    })

    it('handles percentage calculation', () => {
      expect(bcdiv('19', '100', 4)).toBe('0.1900')
    })
  })

  describe('bccomp', () => {
    it('compares equal values', () => {
      expect(bccomp('10.500', '10.500')).toBe(0)
    })

    it('compares with different scale representations', () => {
      expect(bccomp('10.5', '10.500')).toBe(0)
    })

    it('returns 1 when a > b', () => {
      expect(bccomp('10.500', '5.250')).toBe(1)
    })

    it('returns -1 when a < b', () => {
      expect(bccomp('5.250', '10.500')).toBe(-1)
    })
  })

  describe('calculateDiscountAmount', () => {
    it('calculates percentage discount on TND price', () => {
      expect(calculateDiscountAmount('100.000', 'percentage', '10')).toBe('10.000')
    })

    it('returns fixed discount as-is', () => {
      expect(calculateDiscountAmount('100.000', 'fixed', '15.000')).toBe('15.000')
    })
  })

  describe('applyDiscount', () => {
    it('applies percentage discount', () => {
      expect(applyDiscount('100.000', 'percentage', '10')).toBe('90.000')
    })

    it('applies fixed discount', () => {
      expect(applyDiscount('100.000', 'fixed', '15.000')).toBe('85.000')
    })
  })

  describe('formatCurrency', () => {
    it('formats TND with 3 decimals', () => {
      expect(formatCurrency('5.000', false, 'TND')).toBe('5.000')
    })

    it('formats EUR with 2 decimals', () => {
      expect(formatCurrency('19.99', false, 'EUR', 2)).toBe('19.99')
    })

    it('handles empty string input', () => {
      expect(formatCurrency('', false, 'TND')).toBe('0.000')
    })
  })
})
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
cd apps/web && pnpm test -- src/lib/decimal.test.ts
```

Expected: Several tests FAIL (the 0.1+0.2, 1.005*100, and empty string tests will fail with current parseFloat implementation).

- [ ] **Step 4: Commit failing tests**

```bash
git add apps/web/src/lib/decimal.test.ts apps/web/package.json apps/web/pnpm-lock.yaml
git commit -m "test: add failing precision tests for lib/decimal.ts (TDD red phase)"
```

---

## Task 3: Replace `lib/decimal.ts` Internals with big.js

**Files:**
- Modify: `apps/web/src/lib/decimal.ts`

- [ ] **Step 1: Rewrite decimal.ts with big.js**

Replace the entire file content. Keep exact same exports and function signatures:

```typescript
/**
 * Decimal calculation utilities for precise financial calculations.
 *
 * Uses big.js for arbitrary-precision arithmetic, eliminating
 * IEEE 754 floating-point errors (e.g., 0.1 + 0.2 !== 0.3).
 *
 * All amounts are represented as strings to maintain precision.
 */

import Big from 'big.js'
import { getDecimals } from '../hooks/useCurrency'

// Round half-up (matches PHP round() and PostgreSQL behavior)
Big.RM = 1

/** Safely construct a Big from potentially empty/falsy input */
function safeBig(value: string): Big {
  if (!value || value.trim() === '') return new Big(0)
  return new Big(value)
}

export function bcadd(a: string, b: string, scale: number = 3): string {
  return safeBig(a).plus(safeBig(b)).toFixed(scale)
}

export function bcsub(a: string, b: string, scale: number = 3): string {
  return safeBig(a).minus(safeBig(b)).toFixed(scale)
}

export function bcmul(a: string, b: string, scale: number = 3): string {
  return safeBig(a).times(safeBig(b)).toFixed(scale)
}

export function bcdiv(a: string, b: string, scale: number = 3): string {
  const divisor = safeBig(b)
  if (divisor.eq(0)) {
    throw new Error('Division by zero')
  }
  return safeBig(a).div(divisor).toFixed(scale)
}

export function bccomp(a: string, b: string): number {
  return safeBig(a).cmp(safeBig(b))
}

export function calculateDiscountAmount(
  lineTotal: string,
  discountType: 'percentage' | 'fixed',
  discountValue: string
): string {
  if (discountType === 'percentage') {
    const percent = bcdiv(discountValue, '100')
    return bcmul(lineTotal, percent)
  } else {
    return discountValue
  }
}

export function applyDiscount(
  lineTotal: string,
  discountType: 'percentage' | 'fixed',
  discountValue: string
): string {
  const discountAmount = calculateDiscountAmount(lineTotal, discountType, discountValue)
  return bcsub(lineTotal, discountAmount)
}

export function formatCurrency(
  amount: string | number,
  includeCurrency: boolean = true,
  currency: string = 'EUR',
  scale?: number
): string {
  const decimals = scale ?? getDecimals(currency)
  let big: Big
  try {
    big = typeof amount === 'number' ? new Big(amount) : safeBig(String(amount))
  } catch {
    big = new Big(0)
  }
  const formatted = big.toFixed(decimals)
  return includeCurrency ? `${formatted} ${currency}` : formatted
}
```

- [ ] **Step 2: Run tests to verify they pass**

```bash
cd apps/web && pnpm test -- src/lib/decimal.test.ts
```

Expected: ALL tests PASS.

- [ ] **Step 3: Run TypeScript check**

```bash
cd apps/web && pnpm typecheck
```

Expected: No new errors.

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/lib/decimal.ts
git commit -m "fix(precision): replace parseFloat arithmetic with big.js in lib/decimal.ts"
```

---

## Task 4: Fix `lib/formatCurrency.ts` and `useCurrency.ts`

**Files:**
- Modify: `apps/web/src/hooks/useCurrency.ts:4-13`

- [ ] **Step 1: Add missing currencies to `CURRENCY_DECIMALS` in `useCurrency.ts`**

At `apps/web/src/hooks/useCurrency.ts` lines 4-13, add BHD, IQD, JOD, KWD, OMR to the map:

```typescript
const CURRENCY_DECIMALS: Record<string, number> = {
  TND: 3,
  EUR: 2,
  USD: 2,
  GBP: 2,
  MAD: 2,
  DZD: 2,
  LYD: 3,
  ITL: 2,
  BHD: 3,
  IQD: 3,
  JOD: 3,
  KWD: 3,
  OMR: 3,
}
```

NOTE: `useCurrency.ts:formatAmount` and `lib/formatCurrency.ts` both use `parseFloat` but feed into `Intl.NumberFormat.format()` which requires a native `number`. Converting through `Big` and back to `Number` reintroduces float representation, providing no benefit. These are left as-is — the `Intl.NumberFormat` options (`minimumFractionDigits`/`maximumFractionDigits`) already control the output precision.

- [ ] **Step 2: Run typecheck and tests**

```bash
cd apps/web && pnpm typecheck && pnpm test
```

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/hooks/useCurrency.ts
git commit -m "fix(precision): add missing 3-decimal currencies to CURRENCY_DECIMALS map"
```

---

## Task 5: Fix POS Critical Path — `parseFloat` + `reduce` Loops

**Files:**
- Modify: `apps/web/src/features/pos/pages/POSPage/POSPage.tsx`
- Modify: `apps/web/src/features/pos/organisms/PaymentPanel/PaymentPanel.tsx`
- Modify: `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`
- Modify: `apps/web/src/features/pos/organisms/TransactionCart/TransactionCart.tsx`
- Modify: `apps/web/src/features/pos/organisms/ModifierSelectionModal/ModifierSelectionModal.tsx`

These are the highest-risk float arithmetic paths. Each `parseFloat` + `reduce` loop and `parseFloat * quantity` calculation must use `bcadd`/`bcmul`/`bcsub` from `lib/decimal.ts`.

**Pattern to apply everywhere:**

```typescript
// BEFORE: parseFloat accumulation
items.reduce((sum, item) => sum + parseFloat(item.line_total), 0).toFixed(decimals)

// AFTER: bcadd accumulation
items.reduce((sum, item) => bcadd(sum, item.line_total, decimals), '0')
```

```typescript
// BEFORE: parseFloat multiplication
const grossTotal = parseFloat(item.unit_price) * newQty

// AFTER: bcmul
const grossTotal = bcmul(item.unit_price, String(newQty), decimals)
```

```typescript
// BEFORE: percentage discount
const discountAmount = (grossTotal * parseFloat(item.discount_percent)) / 100

// AFTER: bcmul + bcdiv
const discountAmount = bcdiv(bcmul(grossTotal, item.discount_percent, decimals + 2), '100', decimals)
```

- [ ] **Step 1: Fix POSPage.tsx**

Import `bcadd`, `bcmul`, `bcdiv`, `bcsub` from `@/lib/decimal`. Replace all `parseFloat` arithmetic at lines 86, 153-168, 173-178, 333-348, 373-398 with bcmath equivalents. Every `.toFixed(decimals)` on a float result becomes a bcmath call that already returns a string at the correct scale.

- [ ] **Step 2: Fix PaymentPanel.tsx**

Lines 49-59: Replace reduce loops with `bcadd` accumulation. Replace `subtotalAfterDiscount + tax` with `bcadd(subtotalAfterDiscount, tax, decimals)`.

- [ ] **Step 3: Fix AdvancedPaymentsModal.tsx**

Lines 163-173: Same pattern as PaymentPanel. Also fix lines 189-213 (totalPaid, remaining, overpayment calculations) to use `bcsub`/`bccomp` instead of float subtraction and epsilon comparison.

- [ ] **Step 4: Fix TransactionCart.tsx**

Lines 90-91: Replace reduce with `bcadd`. Line 344: Replace `parseFloat * quantity` with `bcmul`.

- [ ] **Step 5: Fix ModifierSelectionModal.tsx**

Lines 94-108: Replace `parseFloat` accumulation loop with `bcadd` for modifier price adjustments.

- [ ] **Step 6: Run POS tests**

```bash
cd apps/web && pnpm test -- src/features/pos/
```

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/pos/
git commit -m "fix(pos): replace parseFloat arithmetic with bcmath in POS critical path"
```

---

## Task 6: Fix POS Components — Hardcoded `.toFixed(2)`

**Files:**
- Modify: `apps/web/src/features/pos/components/CashTenderedModal.tsx:33,42,46`
- Modify: `apps/web/src/features/pos/components/CheckoutSuccessDialog.tsx:66`
- Modify: `apps/web/src/features/pos/components/ReturnItemsModal.tsx:139,285`
- Modify: `apps/web/src/features/pos/pages/ZReportDetailPage/ZReportDetailPage.tsx:213`
- Modify: `apps/web/src/pages/POS/POSTransactions.tsx:315`
- Modify: `apps/web/src/features/pos/hooks/useDiscountPreview.ts:106`

- [ ] **Step 1: Fix CashTenderedModal.tsx**

Add `const { decimals } = useCurrency()`. Replace `.toFixed(2)` at lines 33, 42, 46 with `.toFixed(decimals)`.

- [ ] **Step 2: Fix CheckoutSuccessDialog.tsx**

Add `useCurrency()`. Replace `.toFixed(2)` at line 66 with `.toFixed(decimals)`.

- [ ] **Step 3: Fix ReturnItemsModal.tsx**

Replace `.toFixed(3)` at line 139 and `.toFixed(2)` at line 285 with `.toFixed(decimals)`.

- [ ] **Step 4: Fix ZReportDetailPage.tsx**

Replace `.toFixed(2)` at line 213 with `.toFixed(decimals)`.

- [ ] **Step 5: Fix POSTransactions.tsx**

Replace `Number(m.amount.toFixed(3))` at line 315 with proper `parseFloat(bcmul(String(m.amount), '1', decimals))` or simply pass the string.

- [ ] **Step 6: Fix useDiscountPreview.ts**

Replace `.toFixed(2)` at line 106 with `.toFixed(decimals)`. This hook needs `getDecimals` from useCurrency since it can't use the hook directly.

- [ ] **Step 7: Run POS tests**

```bash
cd apps/web && pnpm test -- src/features/pos/
```

- [ ] **Step 8: Commit**

```bash
git add apps/web/src/features/pos/ apps/web/src/pages/POS/
git commit -m "fix(pos): replace hardcoded .toFixed(2) with currency-aware decimals"
```

---

## Task 7: Fix Documents Module — Hardcoded `.toFixed(2)`

**Files:**
- Modify: `apps/web/src/features/documents/components/CreateCreditNoteForm.tsx`
- Modify: `apps/web/src/features/documents/components/CreditNoteList.tsx:40`
- Modify: `apps/web/src/features/documents/components/CreditNoteDetail.tsx:35`
- Modify: `apps/web/src/features/documents/CreateCreditNotePage.tsx`
- Modify: `apps/web/src/features/documents/CreateReturnNotePage.tsx`
- Modify: `apps/web/src/features/documents/ReturnNoteListPage.tsx:208`
- Modify: `apps/web/src/features/documents/components/CreateReturnNoteForm.tsx`
- Modify: `apps/web/src/features/documents/components/costing/AdditionalCostsForm.tsx`
- Modify: `apps/web/src/features/documents/components/costing/LandedCostBreakdown.tsx`
- Modify: `apps/web/src/types/creditNote.ts:257`

- [ ] **Step 1: Fix all `.toFixed(2)` calls in document components**

For each component: add `const { decimals } = useCurrency()` if not present, replace `.toFixed(2)` with `.toFixed(decimals)`.

For `types/creditNote.ts`: add a `scale` parameter to `calculateRemainingCreditableAmount()` and use `bcsub` from `lib/decimal.ts` instead of float subtraction.

- [ ] **Step 2: Run document tests**

```bash
cd apps/web && pnpm test -- src/features/documents/
```

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/features/documents/ apps/web/src/types/creditNote.ts
git commit -m "fix(documents): replace hardcoded .toFixed(2) with currency-aware decimals"
```

---

## Task 8: Fix Inventory Module — Hardcoded `.toFixed(2)`

**Files:**
- Modify: `apps/web/src/features/inventory/ProductForm.tsx:394`
- Modify: `apps/web/src/features/inventory/StockLevelsPage.tsx:514`
- Modify: `apps/web/src/features/inventory/components/ProductStockLevels.tsx` (~12 calls)
- Modify: `apps/web/src/features/inventory/components/pricing/ProductPricingCard.tsx` (~8 calls)
- Modify: `apps/web/src/features/inventory/components/pricing/PriceInputWithMargin.tsx` (~3 calls)
- Modify: `apps/web/src/features/inventory/components/ProductDocumentsTab.tsx:306`
- Modify: `apps/web/src/features/batches/pages/BatchDetailPage.tsx` (~6 calls)
- Modify: `apps/web/src/features/batches/pages/BatchListPage.tsx:205`

- [ ] **Step 1: Fix all `.toFixed(2)` calls in inventory/batch components**

Same pattern: add `useCurrency()`, replace `.toFixed(2)` with `.toFixed(decimals)`.

- [ ] **Step 2: Run inventory tests**

```bash
cd apps/web && pnpm test -- src/features/inventory/ src/features/batches/
```

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/features/inventory/ apps/web/src/features/batches/
git commit -m "fix(inventory): replace hardcoded .toFixed(2) with currency-aware decimals"
```

---

## Task 9: Fix Treasury, Withholding, VAT, and Other Modules

**Files:**
- Modify: `apps/web/src/features/treasury/PaymentForm.tsx:272,292`
- Modify: `apps/web/src/features/treasury/components/OpenInvoicesList.tsx:75`
- Modify: `apps/web/src/features/treasury/components/AllocationPreview.tsx:42`
- Modify: `apps/web/src/features/treasury/components/PaymentAllocationForm.tsx:65`
- Modify: `apps/web/src/features/treasury/components/ToleranceSettingsDisplay.tsx:52,53`
- Modify: `apps/web/src/features/withholding/components/WithholdingPreviewModal.tsx`
- Modify: `apps/web/src/features/withholding/WithholdingCertificatesList.tsx`
- Modify: `apps/web/src/features/withholding/WithholdingCertificateDetail.tsx`
- Modify: `apps/web/src/features/withholding/pages/SalesWithholdingTrackingPage.tsx`
- Modify: `apps/web/src/features/vat-reporting/pages/VatPeriodsPage.tsx:34,35`
- Modify: `apps/web/src/features/expenses/components/organisms/ExpenseCard.tsx:62`
- Modify: `apps/web/src/components/ui/DeliveryNoteSearchSelect.tsx:95`
- Modify: `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:586`
- Modify: `apps/web/src/types/treasury.ts:160-165,210-218`

- [ ] **Step 1: Fix `types/treasury.ts` — replace float arithmetic with bcadd**

Replace `parseFloat(total) + parseFloat(amount)` in `calculateManualAllocationsTotal` and `getTotalToleranceWriteoff` with `bcadd` from `lib/decimal.ts`. Accept `scale` parameter.

- [ ] **Step 2: Fix treasury components**

Add `useCurrency()`, replace `.toFixed(2)` with `.toFixed(decimals)`.

- [ ] **Step 3: Fix withholding components**

Replace `.toFixed(3)` with `.toFixed(decimals)`. For withholding rate percentage displays (`.toFixed(2)` on a percentage), leave as-is (Category C).

- [ ] **Step 4: Fix VAT, expenses, and shared components**

Same pattern for VatPeriodsPage, ExpenseCard, DeliveryNoteSearchSelect, RecordPaymentModal.

- [ ] **Step 5: Run all affected tests**

```bash
cd apps/web && pnpm test -- src/features/treasury/ src/features/withholding/ src/features/vat-reporting/ src/features/expenses/ src/components/
```

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/treasury/ apps/web/src/features/withholding/ apps/web/src/features/vat-reporting/ apps/web/src/features/expenses/ apps/web/src/components/ apps/web/src/types/treasury.ts
git commit -m "fix(precision): replace hardcoded .toFixed(2) in treasury, withholding, VAT, and shared components"
```

---

## Task 10: Update Test Mocks and Full Verification

**Files:**
- Modify: `apps/web/src/features/pos/pages/POSPage/POSPage.test.tsx`
- Modify: `apps/web/src/features/pos/organisms/PaymentPanel/PaymentPanel.test.tsx`
- Modify: `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.test.tsx`
- Modify: `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.integration.test.tsx`
- Modify: `apps/web/src/features/pos/molecules/DiscountInput/DiscountInput.test.tsx`
- Modify: `apps/web/src/features/pos/molecules/ProductCard/ProductCard.test.tsx`
- Modify: `apps/web/src/features/pos/organisms/ProductInfoModal/ProductInfoModal.test.tsx`
- Modify: `apps/web/src/features/pos/molecules/TransactionDiscountInput/TransactionDiscountInput.test.tsx`
- Modify: `apps/web/src/features/pos/pages/ShiftDashboardPage/ShiftDashboardPage.test.tsx`

- [ ] **Step 1: Update useCurrency mocks in test files**

In each test file, the mock `toFixed` function should use the currency's actual decimal count:

```typescript
// BEFORE:
toFixed: (value: number) => value.toFixed(2),

// AFTER:
toFixed: (value: number) => value.toFixed(decimals),
```

Where `decimals` comes from the test's mock currency. Most tests use EUR (2) which is already correct, but ensure TND-specific tests use 3.

- [ ] **Step 2: Run full test suite**

```bash
cd apps/web && pnpm test
```

- [ ] **Step 3: Run typecheck**

```bash
cd apps/web && pnpm typecheck
```

- [ ] **Step 4: Run lint**

```bash
cd apps/web && pnpm lint
```

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/
git commit -m "test: update useCurrency mocks for currency-aware decimals"
```

---

## Task 11: Final Push

- [ ] **Step 1: Run full preflight**

```bash
cd apps/api && composer test && ./vendor/bin/phpstan --no-progress
cd apps/web && pnpm test && pnpm typecheck && pnpm lint
```

- [ ] **Step 2: Push to main**

```bash
git push origin main
```
