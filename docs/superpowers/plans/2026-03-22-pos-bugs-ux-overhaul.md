# POS Bugs & UX Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 4 POS bugs, redesign cash payment as full-screen, optimize layout spacing for 15" touchscreen, enable Windows fullscreen/kiosk mode.

**Architecture:** Desktop POS app (Tauri 2 + React) at `apps/pos/`. Zustand stores for state. Lucide icons. i18n via react-i18next. All currency amounts are strings in CartItem, numbers in store computed methods.

**Tech Stack:** React 19, TypeScript strict, Zustand 5, Tailwind CSS 4, Tauri 2, Vitest

**Spec:** `docs/superpowers/specs/2026-03-22-pos-bugs-ux-overhaul-design.md`

---

## Phasing & Build Checkpoints

- **After Task 5:** Build Tauri app — this ships the critical bug fixes + spacing improvements
- **After Task 8:** Rebuild Tauri app — ships the full UX overhaul (new payment screen, layout restructure)

**Deferred (not in this plan):**
- Spec Section 2.3: Advanced Payment Screen refactor — existing fullscreen modal works, optimize later
- Spec Section 3: Onboarding Setup Checklist (web) — configure client manually for tomorrow, build checklist post-deploy

---

## Task 1: Fix Transaction Discount — Percentage Treated as Absolute

**Files:**
- Modify: `apps/pos/src/components/organisms/DiscountModal/DiscountModal.tsx`
- Modify: `apps/pos/src/pages/HomePage.tsx:292-300`
- Test: `apps/pos/src/stores/__tests__/cartStore.test.ts`

- [ ] **Step 1: Write failing test for percentage transaction discount**

In `apps/pos/src/stores/__tests__/cartStore.test.ts`, add after line 139:

```typescript
it('calculates total with percentage transaction discount', () => {
  useCartStore.getState().addItem(makeProduct({ sale_price: '100.00' }));
  // 20% of 100 = 20 discount, total should be 80
  useCartStore.getState().setTransactionDiscount({ amount: '20.00' });
  expect(useCartStore.getState().total()).toBeCloseTo(80);
});
```

This test passes now (since amount is already absolute). We need a test that exercises the *handler* logic. But the handler is in HomePage (React component), not the store. The store itself is correct — it expects absolute amounts. So the real test is: verify the DiscountModal passes the right data.

Instead, write an integration-style test. Create a new test file:

Create: `apps/pos/src/components/organisms/DiscountModal/__tests__/discountCalculation.test.ts`

```typescript
import { describe, it, expect } from 'vitest';

/**
 * Tests the percentage-to-absolute conversion logic that must exist
 * in the transaction discount handler (HomePage.tsx).
 */
describe('transaction discount calculation', () => {
  function computeTransactionDiscount(
    subtotal: number,
    type: 'percentage' | 'fixed',
    value: string,
  ): number {
    if (type === 'percentage') {
      return (subtotal * parseFloat(value)) / 100;
    }
    return parseFloat(value);
  }

  it('converts percentage to absolute amount', () => {
    expect(computeTransactionDiscount(100, 'percentage', '20')).toBe(20);
  });

  it('converts 50% on small order correctly', () => {
    expect(computeTransactionDiscount(8.80, 'percentage', '50')).toBeCloseTo(4.40);
  });

  it('passes fixed amount through unchanged', () => {
    expect(computeTransactionDiscount(100, 'fixed', '15')).toBe(15);
  });

  it('handles 100% discount', () => {
    expect(computeTransactionDiscount(25.50, 'percentage', '100')).toBeCloseTo(25.50);
  });
});
```

- [ ] **Step 2: Run test to verify it passes (this validates the logic we'll extract)**

Run: `cd apps/pos && pnpm vitest run src/components/organisms/DiscountModal/__tests__/discountCalculation.test.ts`

- [ ] **Step 3: Update DiscountModal to pass discount type**

In `apps/pos/src/components/organisms/DiscountModal/DiscountModal.tsx`:

Change the callback interface (lines 11-14) from:
```typescript
onApplyTransactionDiscount: (data: {
  amount: string;
  reason: string;
}) => void;
```
To:
```typescript
onApplyTransactionDiscount: (data: {
  type: 'percentage' | 'fixed';
  value: string;
  reason: string;
}) => void;
```

Change `handleApply` (lines 54-57) from:
```typescript
onApplyTransactionDiscount({
  amount: value,
  reason: reason.trim(),
});
```
To:
```typescript
onApplyTransactionDiscount({
  type: discountType,
  value,
  reason: reason.trim(),
});
```

- [ ] **Step 4: Update HomePage handler to convert percentage to absolute**

In `apps/pos/src/pages/HomePage.tsx`, change `handleApplyTransactionDiscount` (lines 292-300) from:
```typescript
const handleApplyTransactionDiscount = useCallback(
  (data: { amount: string; reason: string }) => {
    useCartStore.getState().setTransactionDiscount({
      amount: data.amount,
      reason: data.reason || undefined,
    });
  },
  [],
);
```
To:
```typescript
const handleApplyTransactionDiscount = useCallback(
  (data: { type: 'percentage' | 'fixed'; value: string; reason: string }) => {
    const subtotal = useCartStore.getState().subtotal();
    const tax = useCartStore.getState().taxAmount();
    const totalBeforeDiscount = subtotal + tax;
    let discountAmount: number;
    if (data.type === 'percentage') {
      discountAmount = (totalBeforeDiscount * parseFloat(data.value)) / 100;
    } else {
      discountAmount = parseFloat(data.value);
    }
    useCartStore.getState().setTransactionDiscount({
      amount: discountAmount.toFixed(2),
      reason: data.reason || undefined,
    });
  },
  [],
);
```

- [ ] **Step 5: Run tests to verify nothing broke**

Run: `cd apps/pos && pnpm vitest run`

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src/components/organisms/DiscountModal/ apps/pos/src/pages/HomePage.tsx apps/pos/src/stores/__tests__/cartStore.test.ts
git commit -m "fix(pos): convert percentage discount to absolute before storing in cart"
```

---

## Task 2: Fix Line Item Discount — Tax Not Recalculated

**Files:**
- Modify: `apps/pos/src/stores/cartStore.ts:37` (export `computeTaxAmount`)
- Modify: `apps/pos/src/pages/HomePage.tsx:306-335`
- Test: `apps/pos/src/stores/__tests__/cartStore.test.ts`

- [ ] **Step 1: Write failing test**

In `apps/pos/src/stores/__tests__/cartStore.test.ts`, add:

```typescript
it('recalculates tax_amount when line discount is applied via setState', () => {
  useCartStore.getState().addItem(makeProduct({ sale_price: '100.00', tax_rate: '10' }));
  const item = useCartStore.getState().items[0]!;

  // tax on 100 = 10
  expect(parseFloat(item.tax_amount)).toBeCloseTo(10);

  // Simulate line discount: 20% off → lineTotal = 80
  useCartStore.setState((state) => ({
    items: state.items.map((i) => {
      const grossTotal = parseFloat(i.unit_price) * i.quantity;
      const discountAmount = (grossTotal * 20) / 100;
      const lineTotal = Math.max(0, grossTotal - discountAmount);
      return {
        ...i,
        discount_type: 'percentage' as const,
        discount_percent: '20',
        discount_amount: discountAmount.toFixed(2),
        line_total: lineTotal.toFixed(2),
        tax_amount: (lineTotal * parseFloat(i.tax_rate) / 100).toFixed(2),
      };
    }),
  }));

  const updated = useCartStore.getState().items[0]!;
  // tax on 80 = 8
  expect(parseFloat(updated.tax_amount)).toBeCloseTo(8);
  // total = 80 + 8 = 88
  expect(useCartStore.getState().total()).toBeCloseTo(88);
});
```

- [ ] **Step 2: Run test to verify it passes (this validates the expected behavior)**

Run: `cd apps/pos && pnpm vitest run src/stores/__tests__/cartStore.test.ts`

- [ ] **Step 3: Export `computeTaxAmount` from cartStore**

In `apps/pos/src/stores/cartStore.ts`, change line 37 from:
```typescript
function computeTaxAmount(lineTotal: number, taxRate: string): string {
```
To:
```typescript
export function computeTaxAmount(lineTotal: number, taxRate: string): string {
```

- [ ] **Step 4: Fix `handleApplyLineDiscount` in HomePage.tsx**

In `apps/pos/src/pages/HomePage.tsx`, add import at the top:
```typescript
import { computeTaxAmount } from '@/stores/cartStore';
```

Change lines 320-328 from:
```typescript
const lineTotal = Math.max(0, grossTotal - discountAmount);
return {
  ...item,
  discount_type: data.type,
  discount_percent: data.type === 'percentage' ? data.value : undefined,
  discount_amount: discountAmount.toFixed(2),
  discount_reason: data.reason || undefined,
  line_total: lineTotal.toFixed(2),
};
```
To:
```typescript
const lineTotal = Math.max(0, grossTotal - discountAmount);
return {
  ...item,
  discount_type: data.type,
  discount_percent: data.type === 'percentage' ? data.value : undefined,
  discount_amount: discountAmount.toFixed(2),
  discount_reason: data.reason || undefined,
  line_total: lineTotal.toFixed(2),
  tax_amount: computeTaxAmount(lineTotal, item.tax_rate),
};
```

- [ ] **Step 5: Run tests**

Run: `cd apps/pos && pnpm vitest run`

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src/stores/cartStore.ts apps/pos/src/pages/HomePage.tsx apps/pos/src/stores/__tests__/cartStore.test.ts
git commit -m "fix(pos): recalculate tax_amount when applying line item discount"
```

---

## Task 3: Improve Payment Error Messages

**Files:**
- Modify: `apps/pos/src/locales/en/pos.json` (errors section)
- Modify: `apps/pos/src/locales/fr/pos.json` (errors section)

- [ ] **Step 1: Update English error messages**

In `apps/pos/src/locales/en/pos.json`, change the errors section:
```json
"noCashMethod": "No cash payment method configured. Please set up a cash payment method in Settings → Payment Methods.",
"noCashRegister": "No cash register configured. Please set up a cash register in Settings → Payment Repositories."
```

- [ ] **Step 2: Update French error messages**

In `apps/pos/src/locales/fr/pos.json`, change the errors section:
```json
"noCashMethod": "Aucun moyen de paiement espèces configuré. Veuillez configurer un moyen de paiement espèces dans Paramètres → Moyens de paiement.",
"noCashRegister": "Aucune caisse enregistreuse configurée. Veuillez configurer une caisse dans Paramètres → Dépôts de paiement."
```

- [ ] **Step 3: Commit**

```bash
git add apps/pos/src/locales/
git commit -m "fix(pos): improve cash payment error messages with setup guidance"
```

---

## Task 4: Windows Fullscreen — Remove Decorations in Kiosk Mode

**Files:**
- Modify: `apps/pos/src/App.tsx:151-164`

- [ ] **Step 1: Update fullscreen effect to also remove decorations**

In `apps/pos/src/App.tsx`, replace lines 151-164:
```typescript
// Apply fullscreen on startup if the setting is enabled
useEffect(() => {
  if (!fullscreen) return;
  const applyFullscreen = async () => {
    try {
      if (isTauriEnvironment()) {
        const { getCurrentWindow } = await import('@tauri-apps/api/window');
        await getCurrentWindow().setFullscreen(true);
      }
    } catch {
      // Ignore — not critical
    }
  };
  void applyFullscreen();
}, [fullscreen]);
```

With:
```typescript
// Apply fullscreen + hide decorations when the setting is enabled
useEffect(() => {
  const applyWindowMode = async () => {
    try {
      if (!isTauriEnvironment()) return;
      const { getCurrentWindow } = await import('@tauri-apps/api/window');
      const win = getCurrentWindow();
      if (fullscreen) {
        await win.setDecorations(false);
        await win.setFullscreen(true);
      } else {
        await win.setFullscreen(false);
        await win.setDecorations(true);
      }
    } catch {
      // Ignore — not critical
    }
  };
  void applyWindowMode();
}, [fullscreen]);
```

- [ ] **Step 2: Verify TypeScript compiles**

Run: `cd apps/pos && pnpm typecheck`

- [ ] **Step 3: Commit**

```bash
git add apps/pos/src/App.tsx
git commit -m "fix(pos): hide window decorations in fullscreen mode for true kiosk experience"
```

---

## Task 5: Spacing & Touch Optimization

**Files:**
- Modify: `apps/pos/src/pages/HomePage.tsx:424-448` (main layout padding + consumption mode)
- Modify: `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx` (cart spacing)
- Modify: `apps/pos/src/components/pos/PaymentSummary.tsx` (compact totals)
- Modify: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:126-177` (search + categories)
- Modify: `apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx` (compact rows)

- [ ] **Step 1: Compact the main layout padding and inline consumption toggle**

In `apps/pos/src/pages/HomePage.tsx`, change the product panel (line 425):
```typescript
<div className="flex flex-[7] flex-col overflow-hidden border-r border-gray-200 bg-gray-50 p-4">
```
To:
```typescript
<div className="flex flex-[7] flex-col overflow-hidden border-r border-gray-200 bg-gray-50 p-2">
```

Compact the consumption mode section spacing (lines 426-438) from:
```typescript
{isFnB && (
  <div className="mb-4 space-y-3">
    <ConsumptionModeToggle
      value={consumptionMode}
      onChange={handleConsumptionModeChange}
    />
    {consumptionMode === 'SUR_PLACE' && (
      <TableSelector
        selectedTableId={selectedTableId}
        onSelectTable={setSelectedTableId}
      />
    )}
  </div>
)}
```
To:
```typescript
{isFnB && (
  <div className="mb-2 space-y-2">
    <ConsumptionModeToggle
      value={consumptionMode}
      onChange={handleConsumptionModeChange}
    />
    {consumptionMode === 'SUR_PLACE' && (
      <TableSelector
        selectedTableId={selectedTableId}
        onSelectTable={setSelectedTableId}
      />
    )}
  </div>
)}
```

**Important:** Do NOT remove the ConsumptionModeToggle here — it moves inline with the search bar in Task 7. Build Checkpoint 1 must still have it visible.

- [ ] **Step 2: Compact the TransactionCart spacing**

In `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx`:

Change header (line 57):
```typescript
<div className="flex items-center justify-between border-b border-gray-200 px-4 py-3">
```
To:
```typescript
<div className="flex items-center justify-between border-b border-gray-200 px-3 py-2">
```

Change cart items container (line 90):
```typescript
<div className="flex-1 overflow-y-auto px-4 py-3">
```
To:
```typescript
<div className="flex-1 overflow-y-auto px-3 py-1">
```

Change items spacing (line 98):
```typescript
<div className="space-y-2">
```
To:
```typescript
<div className="divide-y divide-gray-100">
```

Change payment summary container (line 115):
```typescript
<div className="px-4 pb-4">
```
To:
```typescript
<div className="px-3 pb-2">
```

Change shift info footer (line 128):
```typescript
<div className="mt-3 flex justify-between border-t border-gray-100 pt-3 text-xs text-gray-500">
```
To:
```typescript
<div className="mt-1 flex justify-between border-t border-gray-100 pt-1 text-xs text-gray-500">
```

- [ ] **Step 3: Compact the PaymentSummary**

In `apps/pos/src/components/pos/PaymentSummary.tsx`:

Change the container (line 32):
```typescript
<div className="space-y-3 border-t border-gray-200 pt-4">
```
To:
```typescript
<div className="space-y-2 border-t border-gray-200 pt-2">
```

Change the total box (line 46):
```typescript
<div className="rounded-xl bg-gray-900 p-4 text-white">
```
To:
```typescript
<div className="rounded-lg bg-gray-900 px-3 py-2 text-white">
```

Change total text (line 49):
```typescript
<span className="text-3xl font-bold">{format(total)}</span>
```
To:
```typescript
<span className="text-2xl font-bold">{format(total)}</span>
```

Change payment buttons container (line 54):
```typescript
<div className="flex gap-3">
```
To:
```typescript
<div className="flex gap-2">
```

Change cash button padding (line 58):
```typescript
className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-green-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-green-700 active:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
```
To:
```typescript
className="flex flex-[3] items-center justify-center gap-2 rounded-lg bg-green-600 px-4 py-3 text-base font-semibold text-white transition-colors hover:bg-green-700 active:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
```

Change advanced button padding (line 68):
```typescript
className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-primary-600 px-6 py-4 text-lg font-semibold text-white transition-colors hover:bg-primary-700 active:bg-primary-800 disabled:cursor-not-allowed disabled:opacity-50"
```
To:
```typescript
className="flex flex-1 items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-3 text-base font-semibold text-white transition-colors hover:bg-primary-700 active:bg-primary-800 disabled:cursor-not-allowed disabled:opacity-50"
```

- [ ] **Step 4: Compact the ProductGrid header**

In `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`:

Change the root container gap (line 127):
```typescript
<div className="flex h-full flex-col gap-4">
```
To:
```typescript
<div className="flex h-full flex-col gap-2">
```

Change search input padding (line 137):
```typescript
className="w-full rounded-lg border border-gray-300 py-3 pl-10 pr-10 text-base focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
```
To:
```typescript
className="w-full rounded-lg border border-gray-300 py-2 pl-10 pr-10 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
```

- [ ] **Step 5: Run typecheck and tests**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src/pages/HomePage.tsx apps/pos/src/components/
git commit -m "fix(pos): compact spacing for 15-inch touchscreen — reduce padding, tighter cart rows"
```

---

## 🔨 BUILD CHECKPOINT 1: Build Tauri Application

- [ ] **Build the Tauri desktop app with all Phase 1 fixes**

Run: `cd apps/pos && pnpm tauri build`

This produces the deployable installer with bugs 1-4 fixed + spacing improvements. Save this build as the safe fallback for tomorrow's deployment.

---

## Task 6: Full-Screen Quick Cash Payment

**Files:**
- Create: `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx`
- Create: `apps/pos/src/components/organisms/CashPaymentScreen/index.ts`
- Create: `apps/pos/src/lib/denominations.ts`
- Modify: `apps/pos/src/pages/HomePage.tsx` (swap CashTenderedModal for CashPaymentScreen)

- [ ] **Step 1: Create denomination config**

Create `apps/pos/src/lib/denominations.ts`:

```typescript
/** Bill denominations by currency code. */
const DENOMINATIONS: Record<string, number[]> = {
  EUR: [5, 10, 20, 50, 100],
  TND: [5, 10, 20, 50],
  GBP: [5, 10, 20, 50],
  USD: [5, 10, 20, 50, 100],
};

const DEFAULT_DENOMINATIONS = [5, 10, 20, 50, 100];

/**
 * Returns denomination buttons for a given currency and total.
 * Shows "Exact" + up to 3 bills that are >= the total.
 */
export function getDenominations(currency: string, total: number): number[] {
  const bills = DENOMINATIONS[currency] ?? DEFAULT_DENOMINATIONS;
  return bills.filter((d) => d >= total).slice(0, 3);
}
```

- [ ] **Step 2: Create CashPaymentScreen component**

Create `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx`:

```typescript
import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useCurrency } from '@/lib/currency';
import { getDenominations } from '@/lib/denominations';
import { NumPad } from '@/components/molecules/NumPad';
import { ArrowLeft, Banknote, CheckCircle2, Printer, Delete, AlertCircle } from 'lucide-react';

export interface CashPaymentScreenProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (tenderedAmount: number) => void;
  total: number;
  isProcessing: boolean;
  error?: string | null;
}

export function CashPaymentScreen({
  isOpen,
  onClose,
  onConfirm,
  total,
  isProcessing,
  error,
}: CashPaymentScreenProps) {
  const { t } = useTranslation('pos');
  const { format, currency } = useCurrency();
  const [tenderedStr, setTenderedStr] = useState('');

  useEffect(() => {
    if (isOpen) setTenderedStr('');
  }, [isOpen]);

  const tenderedNum = parseFloat(tenderedStr) || 0;
  const changeDue = Math.max(0, tenderedNum - total);
  const isValid = tenderedNum >= total && tenderedStr !== '';

  const handleExact = useCallback(() => {
    setTenderedStr(total.toFixed(2));
  }, [total]);

  const handleDenomination = useCallback((amount: number) => {
    setTenderedStr(amount.toFixed(2));
  }, []);

  const handleConfirm = useCallback(() => {
    if (isValid && !isProcessing) onConfirm(tenderedNum);
  }, [isValid, isProcessing, onConfirm, tenderedNum]);

  const denominations = getDenominations(currency, total);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-gray-900 text-white">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-700 px-4 py-3">
        <button
          onClick={onClose}
          className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-400 hover:bg-gray-800 hover:text-white"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('cashPayment.back')}
        </button>
        <div className="flex items-center gap-2 text-lg font-bold">
          <Banknote className="h-5 w-5" />
          {t('cashPayment.title')}
        </div>
        <div className="w-20" />
      </div>

      {/* Error */}
      {error && (
        <div className="mx-4 mt-3 flex items-center gap-2 rounded-lg border border-red-700 bg-red-900/50 p-3">
          <AlertCircle className="h-4 w-4 shrink-0 text-red-400" />
          <p className="text-sm text-red-300">{error}</p>
        </div>
      )}

      {/* Main content */}
      <div className="flex flex-1 overflow-hidden">
        {/* Left: amounts */}
        <div className="flex flex-[2] flex-col items-center justify-center border-r border-gray-700 p-6">
          <div className="text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-gray-500">
              {t('cashPayment.amountDue')}
            </p>
            <p className="mt-2 text-4xl font-bold">{format(total)}</p>
          </div>

          <div className="mt-8 text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-gray-500">
              {t('cashPayment.tendered')}
            </p>
            <p className="mt-2 text-3xl font-bold text-blue-400">
              {tenderedStr ? format(tenderedNum) : format(0)}
            </p>
          </div>

          <div className="mt-8 w-full max-w-xs rounded-xl border border-green-800 bg-green-950 p-4 text-center">
            <p className="text-xs font-medium uppercase tracking-widest text-green-500">
              {t('cashPayment.changeDue')}
            </p>
            <p className="mt-2 text-3xl font-bold text-green-400">{format(changeDue)}</p>
          </div>
        </div>

        {/* Right: numpad */}
        <div className="flex flex-[3] flex-col p-4">
          {/* Denomination buttons */}
          <div className="mb-3 flex gap-2">
            <button
              onClick={handleExact}
              className="flex-1 rounded-lg border border-blue-600 bg-blue-950 px-3 py-3 text-sm font-semibold text-blue-300 active:bg-blue-900"
            >
              {t('cashPayment.exact')}
            </button>
            {denominations.map((amount) => (
              <button
                key={amount}
                onClick={() => handleDenomination(amount)}
                className="flex-1 rounded-lg border border-gray-600 bg-gray-800 px-3 py-3 text-sm font-medium active:bg-gray-700"
              >
                {amount} {currency}
              </button>
            ))}
          </div>

          {/* Numpad */}
          <div className="flex-1">
            <NumPad value={tenderedStr} onChange={setTenderedStr} />
          </div>

          {/* Confirm button */}
          <button
            onClick={handleConfirm}
            disabled={!isValid || isProcessing}
            className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 py-4 text-lg font-bold transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <CheckCircle2 className="h-5 w-5" />
            {isProcessing ? t('cashPayment.processing') : t('cashPayment.complete')}
          </button>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Create index barrel export**

Create `apps/pos/src/components/organisms/CashPaymentScreen/index.ts`:

```typescript
export { CashPaymentScreen } from './CashPaymentScreen';
export type { CashPaymentScreenProps } from './CashPaymentScreen';
```

- [ ] **Step 4: Add i18n keys for cash payment screen**

In `apps/pos/src/locales/en/pos.json`, add a `cashPayment` section:
```json
"cashPayment": {
  "back": "Back",
  "title": "Cash Payment",
  "amountDue": "Amount Due",
  "tendered": "Cash Tendered",
  "changeDue": "Change Due",
  "exact": "Exact",
  "processing": "Processing...",
  "complete": "Complete & Print Receipt"
}
```

In `apps/pos/src/locales/fr/pos.json`, add:
```json
"cashPayment": {
  "back": "Retour",
  "title": "Paiement espèces",
  "amountDue": "Montant dû",
  "tendered": "Montant reçu",
  "changeDue": "Monnaie à rendre",
  "exact": "Exact",
  "processing": "Traitement...",
  "complete": "Terminer et imprimer"
}
```

- [ ] **Step 5: Swap CashTenderedModal for CashPaymentScreen in HomePage**

In `apps/pos/src/pages/HomePage.tsx`:

Change the import (line 17):
```typescript
import { CashTenderedModal } from '@/components/organisms/CashTenderedModal';
```
To:
```typescript
import { CashPaymentScreen } from '@/components/organisms/CashPaymentScreen';
```

Change the modal usage (lines 476-483):
```typescript
<CashTenderedModal
  isOpen={showCashModal}
  onClose={() => setShowCashModal(false)}
  onConfirm={(amount) => void handleCashConfirm(amount)}
  total={total()}
  isProcessing={isProcessing}
  error={paymentError}
/>
```
To:
```typescript
<CashPaymentScreen
  isOpen={showCashModal}
  onClose={() => setShowCashModal(false)}
  onConfirm={(amount) => void handleCashConfirm(amount)}
  total={total()}
  isProcessing={isProcessing}
  error={paymentError}
/>
```

- [ ] **Step 6: Write tests for denominations and CashPaymentScreen**

Create `apps/pos/src/lib/__tests__/denominations.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { getDenominations } from '../denominations';

describe('getDenominations', () => {
  it('returns EUR bills >= total, max 3', () => {
    expect(getDenominations('EUR', 15.95)).toEqual([20, 50, 100]);
  });

  it('returns TND bills >= total', () => {
    expect(getDenominations('TND', 8)).toEqual([10, 20, 50]);
  });

  it('returns empty when total exceeds all bills', () => {
    expect(getDenominations('EUR', 200)).toEqual([]);
  });

  it('uses default for unknown currency', () => {
    expect(getDenominations('XYZ', 5)).toEqual([5, 10, 20]);
  });
});
```

- [ ] **Step 7: Run typecheck and tests**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 8: Commit**

```bash
git add apps/pos/src/components/organisms/CashPaymentScreen/ apps/pos/src/lib/denominations.ts apps/pos/src/lib/__tests__/ apps/pos/src/pages/HomePage.tsx apps/pos/src/locales/
git commit -m "feat(pos): full-screen cash payment with numpad and smart denominations"
```

---

## Task 7: Layout Restructure — Cart Left, Products Right

**Files:**
- Modify: `apps/pos/src/pages/HomePage.tsx:409-473` (swap panel order)
- Modify: `apps/pos/src/stores/settingsStore.ts` (add `cartPosition` setting)

- [ ] **Step 1: Add `cartPosition` setting to settingsStore**

In `apps/pos/src/stores/settingsStore.ts`, add to the interface:
```typescript
cartPosition: 'start' | 'end';
setCartPosition: (position: 'start' | 'end') => void;
```

Add to the store defaults:
```typescript
cartPosition: 'start',
```

Add the setter:
```typescript
setCartPosition: (position: 'start' | 'end') => {
  set({ cartPosition: position });
},
```

- [ ] **Step 2: Restructure HomePage layout — cart first (left), products second (right)**

In `apps/pos/src/pages/HomePage.tsx`, import `useSettingsStore` cart position:
```typescript
const cartPosition = useSettingsStore((s) => s.cartPosition);
```

Replace the main layout (lines 409-473). The key change: swap the order so cart comes first, and use `flex-direction` to support configurable position:

```typescript
return (
  <div className={`flex h-full relative ${cartPosition === 'end' ? 'flex-row-reverse' : 'flex-row'}`}>
    {/* Barcode scan feedback */}
    {scanMessage && (
      <div
        className={`absolute left-1/2 top-2 z-50 -translate-x-1/2 rounded-lg px-4 py-2 text-sm font-medium shadow-lg transition-opacity ${
          scanMessage.type === 'success'
            ? 'bg-green-600 text-white'
            : 'bg-red-600 text-white'
        }`}
      >
        {scanMessage.text}
      </div>
    )}

    {/* Cart panel — left by default (35%) */}
    <div className="flex-[4] min-w-[340px] border-r border-gray-200">
      <TransactionCart
        items={cartItems}
        subtotal={subtotal()}
        taxAmount={taxAmount()}
        total={total()}
        itemCount={itemCount()}
        onUpdateQuantity={updateQuantity}
        onRemoveItem={removeItem}
        onClearCart={clearCart}
        onPayCash={handlePayCash}
        onAdvancedPayments={handleAdvancedPayments}
        onQuantityTap={handleQuantityTap}
        onDiscount={() => setShowDiscountModal(true)}
        onHold={handleHold}
        onRecall={() => setShowHeldModal(true)}
        onLineDiscount={handleLineDiscount}
        onEditModifiers={handleEditModifiers}
        shiftNumber={shift.shift_number}
        openingCash={shift.opening_cash}
        paymentMethods={paymentMethods}
      />
    </div>

    {/* Product grid panel — right by default (65%) */}
    <div className="flex flex-[7] flex-col overflow-hidden bg-gray-50 p-2">
      {isFnB && consumptionMode === 'SUR_PLACE' && (
        <div className="mb-2">
          <TableSelector
            selectedTableId={selectedTableId}
            onSelectTable={setSelectedTableId}
          />
        </div>
      )}
      <ProductGrid
        products={products}
        categories={categories}
        onAddToCart={handleAddToCart}
        onCustomize={handleCustomize}
        cartProductIds={cartProductIds}
        isLoading={productsLoading}
        consumptionModeToggle={isFnB ? (
          <ConsumptionModeToggle
            value={consumptionMode}
            onChange={handleConsumptionModeChange}
          />
        ) : undefined}
      />
    </div>

    {/* All modals remain unchanged below */}
```

- [ ] **Step 3: Add consumptionModeToggle prop to ProductGrid**

In `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`, add prop:

```typescript
export interface ProductGridProps {
  // ...existing props...
  consumptionModeToggle?: React.ReactNode;
}
```

In the component, destructure it and render inline with search:

Change the search bar section (lines 128-177) to include the toggle inline:
```typescript
{/* Search bar + consumption toggle + display mode */}
<div className="flex items-center gap-2">
  {consumptionModeToggle}
  <div className="relative flex-1">
    {/* ...existing search input... */}
  </div>
  {/* ...existing display mode toggle... */}
</div>
```

- [ ] **Step 4: Run typecheck and tests**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/pages/HomePage.tsx apps/pos/src/stores/settingsStore.ts apps/pos/src/components/organisms/ProductGrid/
git commit -m "feat(pos): cart-left layout with configurable position and inline consumption toggle"
```

---

## Task 8: Contrast & Visual Polish Audit

**Files:**
- Modify: `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx`
- Modify: `apps/pos/src/components/pos/PaymentSummary.tsx`
- Modify: `apps/pos/src/components/molecules/CartLineItem/CartLineItem.tsx`
- Modify: `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx`

This task is a visual polish pass. The implementing agent should:

- [ ] **Step 1: Audit current contrast ratios**

Check all text colors against backgrounds using WCAG 4.5:1 minimum:
- `text-gray-400` on white background = 2.7:1 (FAILS). Change to `text-gray-500` (4.6:1).
- `text-gray-500` on `bg-gray-50` = ~3.7:1 (borderline). Consider `text-gray-600`.
- Cart empty state `text-gray-400` — change to `text-gray-500`.
- Shift info `text-xs text-gray-500` — small text, ensure readable.

- [ ] **Step 2: Fix contrast violations**

In all files above, replace `text-gray-400` with `text-gray-500` where it's used for informational text (not decorative). Replace `text-gray-500` with `text-gray-600` where it sits on `bg-gray-50`.

- [ ] **Step 3: Verify visually**

Run: `cd apps/pos && pnpm dev` and inspect in browser.

- [ ] **Step 4: Commit**

```bash
git add apps/pos/src/components/
git commit -m "fix(pos): improve text contrast ratios to meet WCAG AA minimum"
```

---

## 🔨 BUILD CHECKPOINT 2: Rebuild Tauri Application

- [ ] **Rebuild with all changes including UX overhaul**

Run: `cd apps/pos && pnpm tauri build`

This produces the final build with full UX overhaul. If time permits, deploy this version; otherwise fall back to Build Checkpoint 1.

---

## Task Dependencies

```
Task 1 (discount fix) ─────────┐
Task 2 (line discount fix) ────┤
Task 3 (error messages) ───────┼──→ BUILD CHECKPOINT 1 ──→ Task 6 (cash screen) ──┐
Task 4 (fullscreen) ───────────┤                           Task 7 (layout) ────────┼──→ Task 8 (contrast) ──→ BUILD CHECKPOINT 2
Task 5 (spacing) ──────────────┘                                                   │
                                                                                   │
```

Tasks 1-5 are independent of each other and can run in parallel.
Tasks 6-7 are independent of each other and can run in parallel after Build 1.
Task 8 depends on Tasks 6-7 (needs final layout to audit contrast).
