# POS Round 2: TND Decimals, Modifier Screen, Advanced Payments, Onboarding

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix TND 3-decimal rounding, redesign modifier selection as chip layout, improve advanced payments UX, add web onboarding checklist.

**Architecture:** POS desktop (Tauri 2 + React) at `apps/pos/`, Web dashboard (React) at `apps/web/`, Backend (Laravel) at `apps/api/`. Currency decimals come from `useCurrency()` hook which returns `{ currency, decimals, format }`.

**Tech Stack:** React 19, TypeScript strict, Zustand 5, Tailwind CSS 4, Tauri 2, Vitest, Laravel 12, PHPUnit

---

## Task 1: Fix Hardcoded `.toFixed(2)` — Use Dynamic Currency Decimals

**Files:**
- Modify: `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx:38,42`
- Modify: `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:143,154`
- Modify: `apps/pos/src/pages/HomePage.tsx:308,338,340`
- Modify: `apps/pos/src/lib/offline/receiptService.ts` (12 instances)
- Modify: `apps/pos/src/lib/offline/zReportService.ts` (9 instances)
- Modify: `apps/pos/src/hooks/useCustomerDisplaySync.ts:41`
- Modify: `apps/pos/src/api/reportApi.ts:145-146,158-159`

The pattern is always the same: replace `.toFixed(2)` with `.toFixed(decimals)` where `decimals` comes from `getCurrencyDecimals()` or `useCurrency().decimals`.

- [ ] **Step 1: Fix CashPaymentScreen.tsx**

The hook `useCurrency()` is already imported and `decimals` is available. Destructure it:
```typescript
const { format, currency, decimals } = useCurrency();
```

Line 38: `total.toFixed(2)` → `total.toFixed(decimals)`
Line 42: `amount.toFixed(2)` → `amount.toFixed(decimals)`

- [ ] **Step 2: Fix AdvancedPaymentsModal.tsx**

`useCurrency()` is already imported (line 94). Add `decimals`:
```typescript
const { format, decimals } = useCurrency();
```

Line 143: `remaining.toFixed(2)` → `remaining.toFixed(decimals)`
Line 154: `remaining.toFixed(2)` → `remaining.toFixed(decimals)`

- [ ] **Step 3: Fix HomePage.tsx discount calculations**

Import `getCurrencyDecimals` from `@/lib/currency` and get decimals from the auth store, OR simpler: import `getDecimals` if it's exported from cartStore. Actually, `cartStore.ts` has a private `getDecimals()` — use the same pattern inline.

Add at the top of the component (near where `useCartStore` is used):
```typescript
const { decimals: currencyDecimals } = useCurrency();
```

(Import `useCurrency` from `@/lib/currency` if not already imported.)

Line 308: `discountAmount.toFixed(2)` → `discountAmount.toFixed(currencyDecimals)`
Line 338: `discountAmount.toFixed(2)` → `discountAmount.toFixed(currencyDecimals)`
Line 340: `lineTotal.toFixed(2)` → `lineTotal.toFixed(currencyDecimals)`

- [ ] **Step 4: Fix receiptService.ts**

This is a non-React file (no hooks). Import `getCurrencyDecimals` and accept currency as a parameter, OR add a `decimals` parameter to the functions.

The simplest fix: add `import { getCurrencyDecimals } from '@/lib/currency'` and at the top of each function that uses `.toFixed(2)`, compute:
```typescript
const decimals = getCurrencyDecimals(currency);
```

Then replace all `.toFixed(2)` with `.toFixed(decimals)`.

Check the function signatures — they may already receive `currency` or can derive it from the receipt/cart data. If not, the functions need to accept a `currency` parameter. Read the file carefully before changing.

- [ ] **Step 5: Fix zReportService.ts**

Same pattern as receiptService. Import `getCurrencyDecimals`, get `currency` from the report data or function parameters, replace `.toFixed(2)` with `.toFixed(decimals)`.

- [ ] **Step 6: Fix useCustomerDisplaySync.ts and reportApi.ts**

Line 41 in `useCustomerDisplaySync.ts`: this is a React hook, so use `useCurrency().decimals` or `getCurrencyDecimals()` from the auth store.

Lines 145-146, 158-159 in `reportApi.ts`: non-React — use `getCurrencyDecimals()`.

- [ ] **Step 7: Run tests**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 8: Commit**

```bash
git commit -m "fix(pos): replace hardcoded .toFixed(2) with dynamic currency decimals for TND support"
```

---

## Task 2: Redesign Modifier Selection Modal — Chip Layout

**Files:**
- Modify: `apps/pos/src/components/organisms/ModifierSelectionModal/ModifierSelectionModal.tsx`

The current modal uses `size="lg"` (max-w-lg, 70vh) with full-width stacked buttons (56px each). On a 15" touchscreen, a product with 5 groups / 19 modifiers needs 1200px of scrolling.

**Target:** Show ALL groups at once (no tabs), options as flex-wrap chips, modal size `xl` or `full`.

- [ ] **Step 1: Change modal size**

Line 130: change `size="lg"` to `size="full"`.
Remove `min-h-[500px]` from line 131 — fullscreen modal doesn't need it.

- [ ] **Step 2: Replace tab navigation with all-groups-visible layout**

Remove the tab navigation (lines 141-176) and `activeGroupIndex` state. Instead, show ALL groups in a scrollable list. Replace the current structure with:

```tsx
<div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
  {groups.map((group) => {
    const selected = selections[group.id] ?? new Set();
    const satisfied = isGroupSatisfied(group, selected);

    return (
      <div key={group.id} className="mb-4">
        {/* Group header */}
        <div className="mb-2 flex items-center justify-between">
          <span className="text-sm font-semibold text-gray-900">
            {group.name}
            {group.is_required && !satisfied && (
              <span className="ml-1 text-red-500">*</span>
            )}
          </span>
          {group.selection_type === 'multiple' && (
            <span className="text-xs text-gray-500">
              {t('modifiers.selectUpTo', { max: group.max_selections })}
              {' '}({selected.size}/{group.max_selections})
            </span>
          )}
        </div>

        {/* Chip grid */}
        <div className="flex flex-wrap gap-2">
          {group.modifiers
            .filter((m) => m.is_active)
            .sort((a, b) => a.position - b.position)
            .map((modifier) => {
              const isSelected = selected.has(modifier.id);
              const priceAdj = parseFloat(modifier.price_adjustment);

              return (
                <button
                  key={modifier.id}
                  onClick={() => handleToggleModifier(group, modifier)}
                  className={cn(
                    'flex items-center gap-2 rounded-full border-2 px-4 py-2 text-sm font-medium transition-all',
                    isSelected
                      ? 'border-primary-500 bg-primary-50 text-primary-700'
                      : 'border-gray-200 bg-white text-gray-700 hover:border-gray-300',
                  )}
                >
                  {/* Selection indicator */}
                  {isSelected && (
                    <Check className="h-3.5 w-3.5 text-primary-600" />
                  )}
                  {modifier.name}
                  {priceAdj !== 0 && (
                    <span className={cn(
                      'text-xs',
                      priceAdj > 0 ? 'text-gray-500' : 'text-green-600',
                    )}>
                      {priceAdj > 0 ? '+' : ''}{format(modifier.price_adjustment)}
                    </span>
                  )}
                </button>
              );
            })}
        </div>
      </div>
    );
  })}
</div>
```

- [ ] **Step 3: Remove unused state and imports**

Remove `activeGroupIndex` state (line 49) and `activeGroup` derived value (line 59). Remove `Circle` from lucide imports (line 6) — only `Check` is needed now.

- [ ] **Step 4: Compact the footer**

Change footer (line 264) from `mt-4 ... pt-4` to `mt-2 ... pt-2`.
Change confirm button from `min-h-[56px] ... py-4 text-lg` to `min-h-[48px] ... py-3 text-base`.

- [ ] **Step 5: Run tests**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

Existing `ModifierSelectionModal.test.tsx` should still pass since the component API (props) hasn't changed.

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(pos): redesign modifier selection as chip layout — all groups visible, no scrolling"
```

---

## Task 3: Improve Advanced Payments Modal Layout

**Files:**
- Modify: `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`

The modal is already fullscreen (`size="full"`). The improvements are:
1. Show the NumPad always (not just in touchMode) since this is a POS terminal
2. Better use of horizontal space — payment methods as larger buttons
3. Compact the config panel

- [ ] **Step 1: Always show NumPad (remove touchMode guard)**

Line 386: Remove the `touchMode && selectedMethod &&` condition. Change to just `selectedMethod &&`:
```typescript
{selectedMethod && (
  <div className="mt-auto">
    <NumPad
      value={amount}
      onChange={(val) => {
        setAmount(val);
        setValidationError(null);
      }}
    />
  </div>
)}
```

- [ ] **Step 2: Make payment method buttons larger for touch**

Line 265: Change `min-h-[68px]` to `min-h-[72px]` and icon size from `h-5 w-5` to `h-6 w-6`:
```typescript
'flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-xl border-2 p-3 text-center transition-colors',
```

Line 271: `<Icon className="h-6 w-6" />`
Line 272: Change `text-xs` to `text-sm`:
```typescript
<span className="text-sm font-medium leading-tight">
```

- [ ] **Step 3: Compact the config panel spacing**

Line 283-284: Change `mb-3 ... p-3` to `mb-2 ... p-2`.
Line 284: Change `space-y-2` to `space-y-1.5`.

- [ ] **Step 4: Fix `.toFixed(2)` (if not already done in Task 1)**

Lines 143 and 154 should already use `decimals` from Task 1. Verify.

- [ ] **Step 5: Run tests**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(pos): improve advanced payments layout — always show numpad, larger touch targets"
```

---

## Task 4: Web Onboarding Setup Checklist

**Files:**
- Create: `apps/api/app/Modules/Tenant/Application/Services/OnboardingChecklistService.php`
- Create: `apps/api/app/Modules/Tenant/Domain/Enums/OnboardingStep.php`
- Create: `apps/api/app/Modules/Tenant/Presentation/Controllers/OnboardingController.php`
- Modify: `apps/api/app/Modules/Tenant/routes.php` (add route)
- Create: `apps/web/src/features/settings/api/onboardingApi.ts`
- Create: `apps/web/src/features/settings/components/SetupChecklist.tsx`
- Create: `apps/web/src/features/settings/pages/SetupPage.tsx`
- Modify: `apps/web/src/pages/DashboardPage.tsx` (add alert banner)

This is a web-only feature. The POS desktop app is not involved.

- [ ] **Step 1: Create OnboardingStep enum**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Domain\Enums;

enum OnboardingStep: string
{
    case CompanyInfo = 'company_info';
    case TaxConfig = 'tax_config';
    case PaymentMethods = 'payment_methods';
    case PaymentRepositories = 'payment_repositories';
    case PosTerminal = 'pos_terminal';
    case FirstProduct = 'first_product';

    public function isRequired(): bool
    {
        return match ($this) {
            self::CompanyInfo, self::TaxConfig, self::PaymentMethods, self::PaymentRepositories => true,
            self::PosTerminal, self::FirstProduct => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CompanyInfo => 'Company Information',
            self::TaxConfig => 'Tax Configuration',
            self::PaymentMethods => 'Payment Methods',
            self::PaymentRepositories => 'Payment Repositories',
            self::PosTerminal => 'POS Terminal',
            self::FirstProduct => 'First Product',
        };
    }

    public function settingsPath(): string
    {
        return match ($this) {
            self::CompanyInfo => '/settings/company',
            self::TaxConfig => '/settings/taxes',
            self::PaymentMethods => '/settings/payment-methods',
            self::PaymentRepositories => '/settings/payment-repositories',
            self::PosTerminal => '/settings/terminals',
            self::FirstProduct => '/catalog/products',
        };
    }
}
```

- [ ] **Step 2: Create OnboardingChecklistService**

This service queries across modules using their public interfaces. Read the existing module structure to understand how to query:
- Company info: check if `company.name`, `company.address`, `company.tax_id` are filled
- Tax config: check if at least one active tax rate exists
- Payment methods: check if at least one active payment method exists
- Payment repositories: check if at least one active `cash_register` repository exists
- POS terminal: check if at least one terminal exists
- First product: check if at least one active product exists

Use constructor injection with the relevant repository interfaces. Do NOT import models directly from other modules — use `Shared/Contracts/` or each module's public service. If no shared contract exists, query the database directly via the tenant connection (this is acceptable for a read-only checklist).

The service returns an array of `['step' => OnboardingStep, 'completed' => bool, 'required' => bool]`.

- [ ] **Step 3: Create OnboardingController**

```php
public function status(): JsonResponse
{
    $checklist = $this->checklistService->getStatus();
    return response()->json(['data' => $checklist]);
}
```

Route: `GET /onboarding/status` with `['api', 'auth:sanctum', SetPermissionsTeam::class]` middleware.

- [ ] **Step 4: Create frontend API + SetupChecklist component**

`onboardingApi.ts`:
```typescript
import { apiGet } from '@/lib/api';

interface OnboardingItem {
  step: string;
  label: string;
  completed: boolean;
  required: boolean;
  settings_path: string;
}

export function fetchOnboardingStatus(): Promise<OnboardingItem[]> {
  return apiGet('/onboarding/status');
}
```

`SetupChecklist.tsx`: Render the checklist items with completion status, progress bar, and links to settings pages. Use the patterns from `apps/web/src/features/settings/` for styling consistency. Show a green checkmark for completed items, yellow badge for required incomplete items, gray for optional.

- [ ] **Step 5: Add alert banner to Dashboard**

In `DashboardPage.tsx`, add a dismissible alert banner at the top that shows when required onboarding steps are incomplete. Use React Query to fetch onboarding status. Link to `/settings/setup`.

- [ ] **Step 6: Create SetupPage**

A dedicated page at `/settings/setup` that shows the full checklist with descriptions and direct links to each settings area.

- [ ] **Step 7: Run backend tests**

Run: `cd apps/api && php artisan test --filter=Onboarding`

- [ ] **Step 8: Run frontend tests**

Run: `cd apps/web && pnpm typecheck`

- [ ] **Step 9: Commit**

```bash
git commit -m "feat(web): add onboarding setup checklist with dashboard alert banner"
```

---

## Task Dependencies

```
Task 1 (TND decimals) ──→ Task 3 (advanced payments, needs decimals fix first)
Task 2 (modifier chips) ── independent
Task 4 (onboarding) ── independent, web only
```

Tasks 1, 2, and 4 can run in parallel. Task 3 depends on Task 1.
