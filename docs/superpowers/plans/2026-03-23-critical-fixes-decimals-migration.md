# Critical Fixes: Decimal Migration, Dependency Arrays, Onboarding i18n, Modifier Tests

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 4 critical/important issues found during code review — DB migration for missed decimal columns, stale useCallback closures, onboarding label i18n, and vacuous modifier tests.

**Architecture:** Backend (Laravel 12) at `apps/api/`, POS desktop (React + Tauri 2) at `apps/pos/`, Web dashboard (React) at `apps/web/`.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL 16, React 19, TypeScript strict, Vitest

---

## Task 1: Database Migration — Widen Missed Monetary Columns to Scale 3

**Files:**
- Create: `apps/api/database/migrations/2026_03_23_100000_widen_missed_monetary_columns_to_scale_3.php`
- Test: Run migration, verify column types

This migration covers columns that were missed by the original `2026_03_11_200000_widen_monetary_columns_to_scale_3.php`. It follows the exact same pattern: skip SQLite, use raw `ALTER TABLE ... ALTER COLUMN ... TYPE decimal(precision, 3)`.

### Columns to widen:

| Table | Column | Current | Target |
|-------|--------|---------|--------|
| `document_tax_details` | `tax_base` | decimal(15,2) | decimal(15,3) |
| `document_tax_details` | `tax_amount` | decimal(15,2) | decimal(15,3) |
| `document_lines` | `tax_amount` | decimal(15,2) | decimal(15,3) |
| `document_lines` | `recoverable_tax_amount` | decimal(15,2) | decimal(15,3) |
| `document_lines` | `non_recoverable_tax_amount` | decimal(15,2) | decimal(15,3) |
| `coupon_usages` | `discount_amount` | decimal(15,2) | decimal(15,3) |
| `promotions` | `max_discount_amount` | decimal(15,2) | decimal(15,3) |
| `earning_rules` | `max_earn_per_transaction` | decimal(15,2) | decimal(15,3) |
| `earning_rules` | `max_earn_per_day` | decimal(15,2) | decimal(15,3) |

**NOT included** (already adequate):
- `document_tax_details.tax_rate` — decimal(5,2) is correct for percentages
- `pos_order_lines` — already at decimal(15,4)
- `promotions.discount_value` — already at decimal(12,4)
- `earning_rules.reward_value` — already at decimal(15,4)

- [ ] **Step 1: Create the migration file**

Create `apps/api/database/migrations/2026_03_23_100000_widen_missed_monetary_columns_to_scale_3.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Columns missed by 2026_03_11_200000_widen_monetary_columns_to_scale_3.
     *
     * Format: table => [[column, precision, new_scale]]
     */
    private const COLUMNS = [
        'document_tax_details' => [
            ['tax_base', 15, 3],
            ['tax_amount', 15, 3],
        ],
        'document_lines' => [
            ['tax_amount', 15, 3],
            ['recoverable_tax_amount', 15, 3],
            ['non_recoverable_tax_amount', 15, 3],
        ],
        'coupon_usages' => [
            ['discount_amount', 15, 3],
        ],
        'promotions' => [
            ['max_discount_amount', 15, 3],
        ],
        'earning_rules' => [
            ['max_earn_per_transaction', 15, 3],
            ['max_earn_per_day', 15, 3],
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            $alterClauses = array_map(
                static fn (array $col): string => sprintf(
                    'ALTER COLUMN %s TYPE decimal(%d, %d)',
                    $col[0],
                    $col[1],
                    $col[2],
                ),
                $columns,
            );

            DB::statement(sprintf(
                'ALTER TABLE %s %s',
                $table,
                implode(', ', $alterClauses),
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            $alterClauses = array_map(
                static fn (array $col): string => sprintf(
                    'ALTER COLUMN %s TYPE decimal(%d, 2)',
                    $col[0],
                    $col[1],
                ),
                $columns,
            );

            DB::statement(sprintf(
                'ALTER TABLE %s %s',
                $table,
                implode(', ', $alterClauses),
            ));
        }
    }
};
```

- [ ] **Step 2: Verify migration syntax**

Run: `cd apps/api && php artisan migrate:status | tail -5` to confirm the migration file is detected.

- [ ] **Step 3: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=512M`

- [ ] **Step 4: Commit**

```bash
git add apps/api/database/migrations/2026_03_23_100000_widen_missed_monetary_columns_to_scale_3.php
git commit -m "fix(db): widen missed monetary columns to scale 3 for TND — document_tax_details, document_lines tax, coupon_usages, promotions, earning_rules"
```

---

## Task 2: Fix Stale `useCallback` Dependency Arrays

**Files:**
- Modify: `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx` (2 spots)
- Modify: `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx` (2 spots)
- Modify: `apps/pos/src/pages/HomePage.tsx` (2 spots)

- [ ] **Step 1: Fix CashPaymentScreen.tsx**

The `decimals` variable from `useCurrency()` is used inside two `useCallback` hooks but not in their dependency arrays.

Find `handleExact` (around line 38):
```typescript
const handleExact = useCallback(() => {
  setTenderedStr(total.toFixed(decimals));
}, [total]);
```
Change deps to: `[total, decimals]`

Find `handleDenomination` (around line 42):
```typescript
const handleDenomination = useCallback((amount: number) => {
  setTenderedStr(amount.toFixed(decimals));
}, []);
```
Change deps to: `[decimals]`

- [ ] **Step 2: Fix AdvancedPaymentsModal.tsx**

Find `handleSelectMethod` (around line 143-149). The dep array should include `decimals`:
```typescript
}, [remaining]);
```
Change to: `[remaining, decimals]`

Find `handlePayRemaining` (around line 154-156). Same fix:
```typescript
}, [remaining]);
```
Change to: `[remaining, decimals]`

- [ ] **Step 3: Fix HomePage.tsx**

Find `handleApplyTransactionDiscount` (around line 296-314). The dep array should include `currencyDecimals`:
```typescript
}, []);
```
Change to: `[currencyDecimals]`

Find `handleApplyLineDiscount` (around line 318-350). The dep array should include `currencyDecimals`:
```typescript
}, [discountItemId]);
```
Change to: `[discountItemId, currencyDecimals]`

- [ ] **Step 4: Run typecheck and tests**

Run: `cd apps/pos && pnpm typecheck && pnpm vitest run`

- [ ] **Step 5: Commit**

```bash
git add apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx apps/pos/src/pages/HomePage.tsx
git commit -m "fix(pos): add decimals to useCallback dependency arrays to prevent stale closures"
```

---

## Task 3: Fix Onboarding Labels — Use i18n Translation Keys

**Files:**
- Modify: `apps/web/src/features/settings/components/SetupChecklist.tsx:82`
- Modify: `apps/api/app/Modules/Tenant/Presentation/Controllers/OnboardingController.php` (remove unused $user)

The translation keys already exist in both locale files:
- `en/settings.json`: `onboarding.steps.company_info`, `onboarding.steps.tax_config`, etc.
- `fr/settings.json`: same keys with French translations

### 3a: Fix SetupChecklist to use t() keys

- [ ] **Step 1: Change label rendering**

In `apps/web/src/features/settings/components/SetupChecklist.tsx`, line 82:

Change:
```tsx
<span className="flex-1 text-sm font-medium text-gray-900">{item.label}</span>
```
To:
```tsx
<span className="flex-1 text-sm font-medium text-gray-900">
  {t(`onboarding.steps.${item.step}`)}
</span>
```

This uses the `step` field (e.g., `company_info`) as the translation key, mapping to `onboarding.steps.company_info` which already exists in both locale files.

### 3b: Clean up unused $user in OnboardingController

- [ ] **Step 2: Remove unused variable**

In `apps/api/app/Modules/Tenant/Presentation/Controllers/OnboardingController.php`:

Remove line 7: `use App\Modules\Identity\Domain\User;`
Remove lines 21-22:
```php
/** @var User $user */
$user = $request->user();
```

- [ ] **Step 3: Run checks**

Run: `cd apps/web && pnpm typecheck`
Run: `cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=512M`

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/features/settings/components/SetupChecklist.tsx apps/api/app/Modules/Tenant/Presentation/Controllers/OnboardingController.php
git commit -m "fix(web): use i18n translation keys for onboarding labels, remove unused User import"
```

---

## Task 4: Fix Vacuous Modifier Selection Tests

**Files:**
- Modify: `apps/pos/src/components/organisms/ModifierSelectionModal/__tests__/ModifierSelectionModal.test.tsx`

After the chip layout redesign (Task 2 from Round 2), tabs no longer exist. Several tests try to find a `<button>` with text "Size" to click a tab, but now "Size" is rendered as a `<span>` group header. The `if (sizeTab) fireEvent.click(sizeTab)` guard silently skips the click, making these tests pass vacuously.

Since all groups are now always visible, these tests should directly interact with the modifiers without tab switching.

- [ ] **Step 1: Fix "shows modifier group tabs" test (line 111)**

Rename and update:
```typescript
it('shows all modifier groups simultaneously', () => {
  renderModal();
  // All groups visible at once (no tabs)
  expect(screen.getByText('Toppings')).toBeInTheDocument();
  expect(screen.getByText('Size')).toBeInTheDocument();
  // All modifiers from all groups visible
  expect(screen.getByText('Extra Cheese')).toBeInTheDocument();
  expect(screen.getByText('Small')).toBeInTheDocument();
});
```

- [ ] **Step 2: Fix "switches between group tabs" test (line 164)**

Rename and simplify — all modifiers are already visible, no switching needed:
```typescript
it('shows all modifiers from all groups without navigation', () => {
  renderModal();
  // Toppings group
  expect(screen.getByText('Extra Cheese')).toBeInTheDocument();
  expect(screen.getByText('Bacon')).toBeInTheDocument();
  expect(screen.getByText('Mushrooms')).toBeInTheDocument();
  // Size group
  expect(screen.getByText('Small')).toBeInTheDocument();
  expect(screen.getByText('Medium')).toBeInTheDocument();
  expect(screen.getByText('Large')).toBeInTheDocument();
});
```

- [ ] **Step 3: Fix "enables confirm" test (line 185)**

Remove the tab-switching logic — just click "Small" directly since all groups are visible:
```typescript
it('enables confirm when all required groups are satisfied', () => {
  renderModal();
  // Size group is required — select Small (visible without tab switching)
  fireEvent.click(screen.getByText('Small'));

  const addButton = screen.getByText('modifiers.addToCart');
  expect(addButton.closest('button')).not.toBeDisabled();
});
```

- [ ] **Step 4: Fix "calls onConfirm" test (line 198)**

Remove tab-switching — just click modifiers directly:
```typescript
it('calls onConfirm with selected modifiers', () => {
  const onConfirm = vi.fn();
  renderModal({ onConfirm });

  // Select a topping (visible — all groups shown)
  fireEvent.click(screen.getByText('Bacon'));
  // Select a size (visible — all groups shown)
  fireEvent.click(screen.getByText('Medium'));

  // Confirm
  fireEvent.click(screen.getByText('modifiers.addToCart'));

  expect(onConfirm).toHaveBeenCalledOnce();
  const selectedModifiers = onConfirm.mock.calls[0]![0];
  expect(selectedModifiers).toHaveLength(2);
  expect(selectedModifiers).toEqual(
    expect.arrayContaining([
      expect.objectContaining({ modifier_id: 'mod-2', name: 'Bacon' }),
      expect.objectContaining({ modifier_id: 'mod-m', name: 'Medium' }),
    ]),
  );
});
```

- [ ] **Step 5: Run tests**

Run: `cd apps/pos && pnpm vitest run src/components/organisms/ModifierSelectionModal`

All tests should pass and actually test the correct behavior.

- [ ] **Step 6: Run full test suite**

Run: `cd apps/pos && pnpm vitest run`

- [ ] **Step 7: Commit**

```bash
git add apps/pos/src/components/organisms/ModifierSelectionModal/__tests__/ModifierSelectionModal.test.tsx
git commit -m "test(pos): fix modifier selection tests for chip layout — remove stale tab-switching assertions"
```

---

## Task Dependencies

```
Task 1 (DB migration) ── independent
Task 2 (dep arrays)   ── independent
Task 3 (i18n labels)  ── independent
Task 4 (modifier tests) ── independent
```

All 4 tasks are fully independent and can run in any order. However, Task 1 (DB migration) should be reviewed most carefully since it alters production database schema.
