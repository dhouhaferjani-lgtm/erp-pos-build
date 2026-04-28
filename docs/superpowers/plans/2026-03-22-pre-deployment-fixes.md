# Pre-Deployment Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all issues identified in the code review before tomorrow's F&B client deployment.

**Architecture:** Small, targeted fixes across sidebar gating, PIN hash access, frontend types, and new tenant registration. All additive or corrective — no architectural changes.

**Tech Stack:** Laravel 12 / PHP 8.2+, React 19 / TypeScript strict, PHPStan level 8.

---

## Issues to Fix

| # | Issue | Severity | Risk |
|---|-------|----------|------|
| 1 | Sidebar hides composite items for new F&B tenants without Inventory | Critical (future signups) | Safe for tomorrow (backfill covers) |
| 2 | `getAttributes()['pos_pin']` fragile — should use `$user->pos_pin` | Important | Low |
| 3 | Frontend types missing `effective_cost`, `recipe_cost`, `margin_percentage` | Important | Recipe margin won't display in edit form |
| 4 | New F&B tenant registration should auto-include Inventory in extras | Important (future signups) | Safe for tomorrow |

---

## Task 1: Fix Sidebar Module Mapping for Composite Items

**Files:**
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:80`

**Context:** `MODULE_NAME_MAP` maps `inventoryAndCatalog` to `'Inventory'` (string). The sidebar filter hides the entire section if the module isn't enabled. But this section contains composite items, menus, and categories — which should be visible even without Inventory (they're part of the `CompositeItems` default module for F&B).

The map already supports arrays (line 76: `automotive: ['Vehicle', 'Workshop', 'PlatformIntegration']`). The `isModuleEnabledForVertical` function (line 306-323) shows the section if ANY module in the array is enabled.

- [ ] **Step 1: Change the mapping to an array**

In `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`, change line 80 from:

```typescript
inventoryAndCatalog: 'Inventory',
```

to:

```typescript
inventoryAndCatalog: ['Inventory', 'CompositeItems'],
```

This means the "Inventaire & Catalogue" section shows if the tenant has EITHER `Inventory` OR `CompositeItems` enabled. F&B verticals always have `CompositeItems` as a default module, so the section is always visible for them. The individual children (`products`, `stockLevels`, etc.) still map to `'Inventory'` and will be hidden individually.

- [ ] **Step 2: Verify `categories` should also be visible without Inventory**

Currently `categories: 'Inventory'` (line 82). Categories are used by composite items too, not just products. Change:

```typescript
categories: ['Inventory', 'CompositeItems'],
```

- [ ] **Step 3: Verify frontend compiles**

Run: `cd apps/web && pnpm typecheck`
Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/components/organisms/Sidebar/Sidebar.tsx
git commit -m "fix(sidebar): show Inventory & Catalog section when CompositeItems module enabled"
```

---

## Task 2: Fix PIN Hash Access — Use Model Property Instead of getAttributes()

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:128`

**Context:** The `hashed` cast on `pos_pin` only hashes on **write** (set). On read, `$user->pos_pin` returns the raw bcrypt hash from the database. Using `$user->getAttributes()['pos_pin']` is unnecessarily fragile — if the column is renamed or null, it returns null silently. `$user->pos_pin` is equivalent and follows Eloquent conventions.

- [ ] **Step 1: Replace getAttributes() with direct property access**

In `PosAuthController.php` line 128, change:

```php
'pin_hash' => $user->getAttributes()['pos_pin'],
```

to:

```php
'pin_hash' => $user->pos_pin,
```

- [ ] **Step 2: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/POS/Presentation/Controllers/PosAuthController.php --level=8`
Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php
git commit -m "fix(pos): use model property for pin_hash instead of getAttributes()"
```

---

## Task 3: Add Missing Cost Fields to Frontend CompositeItem Type

**Files:**
- Modify: `apps/web/src/features/catalog/types/compositeItem.ts:8-29`
- Modify: `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx`

**Context:** The backend `CompositeItemData` DTO returns `effective_cost`, `recipe_cost`, and `margin_percentage` but the frontend TypeScript interface doesn't declare them. This means recipe-based cost/margin won't display when editing a composite item that has a recipe.

- [ ] **Step 1: Add fields to CompositeItemData interface**

In `apps/web/src/features/catalog/types/compositeItem.ts`, add after `manual_cost`:

```typescript
export interface CompositeItemData {
  id: string
  code: string
  name: string
  vertical_type: VerticalType
  base_price: string
  manual_cost: string | null
  effective_cost: string | null      // ← add
  recipe_cost: string | null         // ← add
  margin_percentage: number | null   // ← add
  production_type: ProductionType
  // ... rest unchanged
}
```

- [ ] **Step 2: Show recipe cost in edit form when available**

In `CompositeItemFormPage.tsx`, find where the margin is displayed (the paragraph showing `Marge: X%`). Update to show recipe cost when available in edit mode:

Find the margin display logic. When `data?.recipe_cost` is present (edit mode with recipe), show both:

```tsx
{data?.recipe_cost && (
  <div className="rounded-md bg-blue-50 p-3 text-sm space-y-1">
    <div className="flex justify-between">
      <span className="text-blue-700">{t('compositeItems.fields.recipeCost')}</span>
      <span className="font-medium text-blue-900">{data.recipe_cost}</span>
    </div>
    {data.margin_percentage !== null && (
      <div className="flex justify-between">
        <span className="text-blue-700">{t('compositeItems.fields.margin')}</span>
        <span className="font-medium text-blue-900">{data.margin_percentage}%</span>
      </div>
    )}
  </div>
)}
```

Keep the existing client-side margin calculation for create mode (when no recipe exists).

- [ ] **Step 3: Verify frontend compiles**

Run: `cd apps/web && pnpm typecheck`
Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/features/catalog/types/compositeItem.ts apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx
git commit -m "fix(catalog): add cost fields to frontend CompositeItem type, show recipe cost in edit form"
```

---

## Task 4: Auto-Include Inventory for New F&B Tenant Registration

**Files:**
- Find and modify: The tenant initialization/registration service that sets `enabled_extras`

**Context:** When a new tenant registers with a CoffeeShop or Restaurant vertical, `enabled_extras` defaults to `[]`. This means they won't have Inventory access. The backfill migration covers existing tenants, but new signups after deployment will be affected.

- [ ] **Step 1: Find the registration service**

Search for where `enabled_extras` is set during tenant creation:
```bash
grep -r "enabled_extras" apps/api/app --include="*.php" -l
```

Look for the tenant initialization/registration service. It likely sets `enabled_extras` to `[]` or doesn't set it at all (defaults to `[]`).

- [ ] **Step 2: Add Inventory to default extras for F&B verticals**

In the tenant initialization service, after the tenant is created with a vertical, check if the vertical is CoffeeShop or Restaurant. If so, add `'Inventory'` to `enabled_extras`:

```php
use App\Enums\Vertical;

// After tenant creation, check if F&B vertical needs Inventory
$fnbVerticals = [Vertical::CoffeeShop, Vertical::Restaurant];
if (in_array($tenant->vertical, $fnbVerticals, true)) {
    $extras = $tenant->enabled_extras ?? [];
    if (! in_array('Inventory', $extras, true)) {
        $extras[] = 'Inventory';
        $tenant->update(['enabled_extras' => $extras]);
    }
}
```

**Important:** This is a TEMPORARY measure. Eventually, Inventory should NOT be auto-included — it should be a paid add-on. But for the current go-live, all F&B tenants need it. Add a comment: `// TODO: Remove when Inventory becomes a separately purchased module`

- [ ] **Step 3: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan analyse <path-to-modified-file> --level=8`
Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add <path-to-modified-file>
git commit -m "fix(tenant): auto-include Inventory in extras for new F&B tenants"
```

---

## Task 5: Final Verification

- [ ] **Step 1: Run PHPStan on all modified files**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/POS/Presentation/Controllers/PosAuthController.php --level=8`
Expected: No errors.

- [ ] **Step 2: Run frontend typecheck**

Run: `cd apps/web && pnpm typecheck`
Expected: No errors.

- [ ] **Step 3: Run Pint**

Run: `cd apps/api && ./vendor/bin/pint --test app/Modules/POS/ app/Modules/Tenant/`
Expected: No style issues in our files.

- [ ] **Step 4: Push to GitHub**

```bash
git push origin main
```
