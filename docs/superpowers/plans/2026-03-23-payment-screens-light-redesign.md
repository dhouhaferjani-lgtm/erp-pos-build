# Payment Screens Light Theme Redesign

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign CashPaymentScreen (dark→light retheme) and AdvancedPaymentsModal (modal→full-screen 3-column light layout) for visual consistency and dark-mode readiness.

**Architecture:** POS desktop app (Tauri 2 + React) at `apps/pos/`. Both screens become `fixed inset-0 z-50` full-screen divs using semantic Tailwind classes only. No hardcoded hex colors, no dark-specific classes. All logic/behavior preserved; only presentation changes.

**Tech Stack:** React 19, TypeScript strict, Tailwind CSS 4, Vitest, react-i18next

**Spec:** `docs/superpowers/specs/2026-03-23-payment-screens-light-redesign.md`

---

## Task Dependencies

```
Task 1 (CashPaymentScreen retheme) ── independent
Task 2 (AdvancedPaymentsModal redesign) ── independent
```

Both tasks can run in parallel. No shared file modifications.

---

## Task 1: CashPaymentScreen — Dark to Light Retheme

**Files:**
- Modify: `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx`
- Test: `apps/pos/src/components/organisms/CashPaymentScreen/__tests__/CashPaymentScreen.test.tsx`

This is a color-only change. The layout structure (flex row, left flex-2 amounts, right flex-3 numpad) stays identical. Apply the color mapping table from the spec line by line.

- [ ] **Step 1: Run existing tests to establish baseline**

Run: `cd apps/pos && pnpm vitest run src/components/organisms/CashPaymentScreen`

Expected: All 9 tests PASS. These tests check behavior (rendering, button states, callbacks) and don't assert CSS classes, so they should remain green after color changes.

- [ ] **Step 2: Retheme the outer container and header**

In `CashPaymentScreen.tsx`, replace the outer div and header classes:

Line 56 — outer container:
```tsx
// FROM:
<div className="fixed inset-0 z-50 flex flex-col bg-gray-900 text-white">
// TO:
<div className="fixed inset-0 z-50 flex flex-col bg-gray-50 text-gray-900">
```

Line 58 — header:
```tsx
// FROM:
<div className="flex items-center justify-between border-b border-gray-700 px-4 py-3">
// TO:
<div className="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
```

Line 61 — back button:
```tsx
// FROM:
className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-400 hover:bg-gray-800 hover:text-white"
// TO:
className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-900"
```

Line 66 — title (remove explicit color, inherits `text-gray-900` from parent):
```tsx
// FROM:
<div className="flex items-center gap-2 text-lg font-bold">
// TO (same — inherits from parent now):
<div className="flex items-center gap-2 text-lg font-bold">
```

No change needed on title — it was `text-white` via parent inheritance, now inherits `text-gray-900`.

- [ ] **Step 3: Retheme the error banner**

Lines 74-78:
```tsx
// FROM:
<div className="mx-4 mt-3 flex items-center gap-2 rounded-lg border border-red-700 bg-red-900/50 p-3">
  <AlertCircle className="h-4 w-4 shrink-0 text-red-400" />
  <p className="text-sm text-red-300">{error}</p>
</div>
// TO:
<div className="mx-4 mt-3 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 p-3">
  <AlertCircle className="h-4 w-4 shrink-0 text-red-500" />
  <p className="text-sm text-red-700">{error}</p>
</div>
```

- [ ] **Step 4: Retheme the left panel (amounts)**

Line 84 — left panel border:
```tsx
// FROM:
<div className="flex flex-[2] flex-col items-center justify-center border-r border-gray-700 p-6">
// TO:
<div className="flex flex-[2] flex-col items-center justify-center border-r border-gray-200 bg-white p-6">
```

Line 86 — "Amount Due" label:
```tsx
// FROM:
<p className="text-xs font-medium uppercase tracking-widest text-gray-400">
// TO:
<p className="text-xs font-medium uppercase tracking-widest text-gray-500">
```

Line 89 — amount value (remove explicit color, inherits `text-gray-900`):
```tsx
// FROM:
<p className="mt-2 text-4xl font-bold">{format(total)}</p>
// TO (same — inherits text-gray-900):
<p className="mt-2 text-4xl font-bold">{format(total)}</p>
```

No change needed for the amount value — inherits correctly.

Line 94 — discount label:
```tsx
// FROM:
<p className="text-xs font-medium uppercase tracking-widest text-primary-400">
// TO:
<p className="text-xs font-medium uppercase tracking-widest text-primary-600">
```

Line 97 — discount value:
```tsx
// FROM:
<p className="mt-1 text-lg font-bold text-primary-400">-{format(discountAmount)}</p>
// TO:
<p className="mt-1 text-lg font-bold text-primary-600">-{format(discountAmount)}</p>
```

Line 102 — "Tendered" label:
```tsx
// FROM:
<p className="text-xs font-medium uppercase tracking-widest text-gray-400">
// TO:
<p className="text-xs font-medium uppercase tracking-widest text-gray-500">
```

Line 105 — tendered value:
```tsx
// FROM:
<p className="mt-2 text-3xl font-bold text-blue-400">
// TO:
<p className="mt-2 text-3xl font-bold text-primary-600">
```

Lines 110-114 — change due card:
```tsx
// FROM:
<div className="mt-8 w-full max-w-xs rounded-xl border border-green-800 bg-green-950 p-4 text-center">
  <p className="text-xs font-medium uppercase tracking-widest text-green-500">
    {t('cashPayment.changeDue')}
  </p>
  <p className="mt-2 text-3xl font-bold text-green-400">{format(changeDue)}</p>
</div>
// TO:
<div className="mt-8 w-full max-w-xs rounded-xl border-2 border-green-200 bg-green-50 p-4 text-center">
  <p className="text-xs font-medium uppercase tracking-widest text-green-700">
    {t('cashPayment.changeDue')}
  </p>
  <p className="mt-2 text-3xl font-bold text-green-700">{format(changeDue)}</p>
</div>
```

- [ ] **Step 5: Retheme the right panel (numpad area)**

Line 119 — right panel background:
```tsx
// FROM:
<div className="flex flex-[3] flex-col p-4">
// TO:
<div className="flex flex-[3] flex-col bg-gray-50 p-4">
```

Lines 123-126 — exact button:
```tsx
// FROM:
className="flex-1 rounded-lg border border-blue-600 bg-blue-950 px-3 py-3 text-sm font-semibold text-blue-300 active:bg-blue-900"
// TO:
className="flex-1 rounded-lg bg-primary-600 px-3 py-3 text-sm font-semibold text-white active:bg-primary-700"
```

Line 132 — denomination buttons:
```tsx
// FROM:
className="flex-1 rounded-lg border border-gray-600 bg-gray-800 px-3 py-3 text-sm font-medium active:bg-gray-700"
// TO:
className="flex-1 rounded-lg border border-gray-200 bg-white px-3 py-3 text-sm font-medium text-gray-900 active:bg-gray-100"
```

Line 148 — confirm button (green stays, just ensure text-white explicit):
```tsx
// FROM:
className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 py-4 text-lg font-bold transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
// TO:
className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 py-4 text-lg font-bold text-white transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
```

- [ ] **Step 6: Run tests**

Run: `cd apps/pos && pnpm vitest run src/components/organisms/CashPaymentScreen`

Expected: All 9 tests PASS (behavior unchanged).

- [ ] **Step 7: Run typecheck**

Run: `cd apps/pos && pnpm typecheck`

Expected: No errors.

- [ ] **Step 8: Commit**

```bash
git add apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx
git commit -m "feat(pos): retheme CashPaymentScreen from dark to light for consistency"
```

---

## Task 2: AdvancedPaymentsModal — Full-Screen 3-Column Redesign

**Files:**
- Modify: `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`

This is a full rewrite of the JSX return block. All business logic (state, handlers, memos) stays the same. The changes are:
1. Replace `<Modal>` wrapper with `fixed inset-0 z-50` div
2. Change from 2-column (55/45) to 3-column (30/35/35) layout
3. Remove `touchMode` dependency, `showSummary` state, collapsible summary
4. Remove unused imports (`Modal`, `useSettingsStore`, `ChevronDown`, `ChevronRight`)
5. Amount input always `readOnly`, numpad always visible
6. Add `isOpen` early return guard (like CashPaymentScreen)
7. Add `ArrowLeft` import for back button

- [ ] **Step 1: Clean up imports and state**

At the top of `AdvancedPaymentsModal.tsx`:

Remove from lucide imports: `ChevronDown`, `ChevronRight`
Add to lucide imports: `ArrowLeft`
Remove import: `import { Modal } from '@/components/pos/Modal';`
Remove import: `import { useSettingsStore } from '@/stores/settingsStore';`

Inside the component function body:
- Remove: `const touchMode = useSettingsStore((s) => s.touchMode);` (line 95)
- Remove: `const [showSummary, setShowSummary] = useState(false);` (line 104)

- [ ] **Step 2: Add early return guard and replace Modal wrapper**

Add after `const showCardLastFour = ...;` (line 239):
```tsx
if (!isOpen) return null;
```

Replace the entire return block. Remove the `<Modal>` wrapper and replace with a full-screen div. The new return structure is:

```tsx
return (
  <div className="fixed inset-0 z-50 flex flex-col bg-gray-50 text-gray-900">
    {/* Header */}
    <div className="flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3">
      <button
        onClick={handleClose}
        className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-900"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('advancedPayments.back')}
      </button>
      <div className="flex items-center gap-2 text-lg font-bold">
        <Wallet className="h-5 w-5" />
        {t('advancedPayments.title')}
      </div>
      <div className="w-20" />
    </div>

    {/* Main 3-column layout */}
    <div className="flex min-h-0 flex-1 overflow-hidden">
      {/* LEFT COLUMN: Method selection + config */}
      <div className="flex w-[30%] flex-col border-r border-gray-200 bg-white p-4">
        <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
          {t('advancedPayments.selectMethod')}
        </p>
        <div className="flex flex-col gap-2">
          {activeMethods.map((method) => {
            const Icon = getMethodIcon(method);
            const isSelected = selectedMethodId === method.id;
            return (
              <button
                key={method.id}
                onClick={() => handleSelectMethod(method.id)}
                className={cn(
                  'flex min-h-[52px] items-center gap-3 rounded-xl border-2 px-4 py-3 text-left transition-colors',
                  isSelected
                    ? 'border-primary-500 bg-primary-50 text-primary-700'
                    : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50',
                )}
              >
                <Icon className="h-5 w-5 shrink-0" />
                <span className="text-sm font-medium">{method.name}</span>
              </button>
            );
          })}
        </div>

        {/* Config fields — shown when method selected */}
        {selectedMethod && (
          <div className="mt-4 space-y-3 border-t border-gray-200 pt-4">
            {/* Repository dropdown */}
            {compatibleRepositories.length > 1 && (
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-500">
                  {t('advancedPayments.repository')}
                </label>
                <select
                  value={effectiveRepositoryId}
                  onChange={(e) => {
                    setRepositoryId(e.target.value);
                    setValidationError(null);
                  }}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                >
                  <option value="">
                    {t('advancedPayments.selectRepository')}
                  </option>
                  {compatibleRepositories.map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.name}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {/* Reference field */}
            {showReference && (
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-500">
                  {t('advancedPayments.reference')}
                </label>
                <input
                  type="text"
                  value={reference}
                  onChange={(e) => setReference(e.target.value)}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                />
              </div>
            )}

            {/* Card last 4 */}
            {showCardLastFour && (
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-500">
                  {t('advancedPayments.cardLastFour')}
                </label>
                <input
                  type="text"
                  maxLength={4}
                  value={cardLastFour}
                  onChange={(e) =>
                    setCardLastFour(e.target.value.replace(/\D/g, ''))
                  }
                  className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none"
                />
              </div>
            )}
          </div>
        )}

        {/* Add Payment button — pinned to bottom */}
        {selectedMethod && (
          <button
            onClick={handleAddPayment}
            className="mt-auto rounded-xl bg-primary-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-primary-700 active:bg-primary-800"
          >
            {t('advancedPayments.addPayment')}
          </button>
        )}
      </div>

      {/* CENTER COLUMN: Amount + NumPad */}
      <div className="flex w-[35%] flex-col bg-gray-50 p-4">
        {/* Amount display */}
        <div className="mb-3 text-center">
          <p className="text-xs font-medium uppercase tracking-widest text-gray-500">
            {t('advancedPayments.amount')}
          </p>
          <p className="mt-1 text-3xl font-bold text-gray-900">
            {amount ? format(parseFloat(amount)) : format(0)}
          </p>
        </div>

        {/* Pay Remaining pill */}
        {remaining > 0 && (
          <div className="mb-3 text-center">
            <button
              onClick={handlePayRemaining}
              className="inline-flex rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-primary-700"
            >
              {t('advancedPayments.payRemaining')}: {format(remaining)}
            </button>
          </div>
        )}

        {/* Amount input (hidden, readOnly — numpad controls it) */}
        <input
          type="hidden"
          value={amount}
        />

        {/* NumPad */}
        <div className="flex-1">
          <NumPad
            value={amount}
            onChange={(val) => {
              setAmount(val);
              setValidationError(null);
            }}
          />
        </div>
      </div>

      {/* RIGHT COLUMN: Balance + Payments list + Complete */}
      <div className="flex w-[35%] flex-col border-l border-gray-200 bg-white p-4">
        {/* Total due card */}
        <div className="mb-4 rounded-xl bg-primary-600 p-4 text-white">
          <p className="text-xs font-medium uppercase tracking-wider opacity-80">
            {t('advancedPayments.totalDue')}
          </p>
          <p className="mt-1 text-3xl font-bold">{format(total)}</p>
        </div>

        {/* Payment lines — scrollable */}
        <div className="mb-3 flex-1 overflow-y-auto">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
            {t('advancedPayments.addedPayments')}
          </p>
          {paymentLines.length === 0 ? (
            <p className="py-6 text-center text-sm text-gray-400">
              {t('advancedPayments.noPayments')}
            </p>
          ) : (
            <div className="space-y-2">
              {paymentLines.map((line) => (
                <div
                  key={line.id}
                  className="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5"
                >
                  <div>
                    <p className="text-sm font-medium text-gray-900">
                      {line.methodName}
                    </p>
                    <p className="text-xs text-gray-500">
                      {line.repositoryName}
                      {line.cardLastFour && ` · *${line.cardLastFour}`}
                      {line.reference && ` · ${line.reference}`}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold text-gray-900">
                      {format(line.amount)}
                    </span>
                    <button
                      onClick={() => handleRemoveLine(line.id)}
                      className="rounded-lg p-1.5 text-red-500 hover:bg-red-50"
                      aria-label={t('advancedPayments.delete')}
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Footer — balance + error + complete */}
        <div className="border-t border-gray-200 pt-3">
          {/* Balance rows */}
          <div className="mb-2 space-y-1 text-sm">
            <div className="flex justify-between">
              <span className="text-gray-500">
                {t('advancedPayments.totalPaid')}
              </span>
              <span className="font-medium text-gray-900">
                {format(totalPaid)}
              </span>
            </div>
            {remaining > 0 && (
              <div className="flex justify-between text-orange-600">
                <span>{t('advancedPayments.remaining')}</span>
                <span className="font-medium">{format(remaining)}</span>
              </div>
            )}
            {overpayment > 0 && (
              <div className="flex justify-between text-green-600">
                <span>{t('advancedPayments.changeDue')}</span>
                <span className="font-medium">{format(overpayment)}</span>
              </div>
            )}
          </div>

          {/* Validation / API error */}
          {(validationError || error) && (
            <div className="mb-2 flex items-center gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
              <AlertTriangle className="h-4 w-4 shrink-0" />
              {validationError || error}
            </div>
          )}

          {/* Complete button */}
          <button
            onClick={() => void handleComplete()}
            disabled={!isFullyPaid || isProcessing}
            className="w-full rounded-xl bg-green-600 px-6 py-3.5 text-lg font-semibold text-white transition-colors hover:bg-green-700 active:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {isProcessing
              ? t('advancedPayments.processing')
              : t('advancedPayments.completeTransaction')}
          </button>
        </div>
      </div>
    </div>
  </div>
);
```

- [ ] **Step 3: Add "back" translation key if missing**

Check `apps/pos/src/locales/en/common.json` and `fr/common.json` for `advancedPayments.back`. If missing, add:

English: `"back": "Back"`
French: `"back": "Retour"`

Under the `advancedPayments` key in the `pos` namespace (check which file the pos namespace maps to — likely `apps/pos/src/locales/en/pos.json` or inside the common.json).

- [ ] **Step 4: Run typecheck**

Run: `cd apps/pos && pnpm typecheck`

Expected: No errors.

- [ ] **Step 5: Run all POS tests**

Run: `cd apps/pos && pnpm vitest run`

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx
git add apps/pos/src/locales/  # if translation keys were added
git commit -m "feat(pos): redesign AdvancedPaymentsModal as full-screen 3-column light layout"
```
