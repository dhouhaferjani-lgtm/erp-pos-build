# F&B Migration: Manual Cost, Inventory Module Gating & Import Fixes

## Context

First F&B client migrating from an existing POS system. They sell simple products and composite items (prepared menu items). The current system requires full recipe/ingredient setup to track cost on composite items, which creates a migration bottleneck: importing 50+ menu items means manually building 50 recipes before cost tracking works.

Industry standard (Square, Toast, Lightspeed, Loyverse) is to offer a simplified mode where menu items have a manual cost field, with inventory tracking (recipes, ingredients, stock deduction) as a paid add-on module.

## Design Decisions (Agreed)

1. **`manual_cost` on composite items** — flat cost field the user types in, used for margin/profit dashboards
2. **No GL entries from manual_cost** — purely for reporting. COGS GL entries only happen through the existing invoice posting flow when inventory tracking is enabled
3. **Recipe cost overrides manual cost** — when a recipe exists, `recipe.calculated_cost` takes precedence. UI shows both side-by-side so the user can spot discrepancies
4. **Inventory as paid extra for all F&B verticals** — moved from `defaultModules` to `compatibleExtras` for CoffeeShop and Restaurant
5. **Customer receipts unchanged** — cost data is internal only, never printed

---

## Stream 1: Import Fixes

### 1.1 Fix Column Mapping Not Sent to Backend

**Problem:** `importApi.ts` `createJob()` accepts `_columnMapping` (underscore-prefixed = unused) and never appends it to the FormData. The mapping step in the wizard is cosmetic — the backend receives raw CSV headers.

**Fix:**

**Frontend** — `apps/web/src/features/import/api/importApi.ts`:
- Remove underscore prefix from `_columnMapping` parameter
- Append `column_mapping` as a JSON-stringified value in the FormData

**Backend Controller** — `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php`:
- Accept `column_mapping` from the request (JSON string, decode to associative array)
- Pass the mapping to the import service

**Backend Pipeline** — Apply mapping in `ImportService` (the orchestrator), AFTER `SpreadsheetParserService` returns raw `{headers, rows}` but BEFORE validation runs:
1. `SpreadsheetParserService::parse()` returns raw headers and rows (unchanged)
2. `ImportService` receives the column mapping (e.g., `{"Price": "sale_price", "Code": "sku"}`)
3. `ImportService` renames row keys according to the mapping before passing to validation
4. Unmapped source columns are dropped
5. Downstream processing (validation, import) sees clean target column names

This keeps the parser pure (it just reads the file) and puts the mapping logic in the orchestrator where it belongs.

**Behavior:** If a user maps their CSV column "Price" to target "sale_price", the backend renames the column before validation runs. Unmapped columns are dropped.

### 1.2 Remove stock_levels Card from Import Dashboard

**Problem:** The stock_levels import is unconditionally blocked with a 403 in the controller. The dashboard card leads to a dead end.

**Fix:**
- `apps/web/src/features/import/pages/ImportDashboardPage.tsx`: Remove the `stock_levels` entry from `IMPORT_TYPES`
- `apps/web/src/features/import/components/ImportTypeCard.tsx`: Remove `stock_levels` from the icon map
- Keep `stock_levels` in the backend `ImportType` enum and frontend `ImportType` union (no breaking changes to types — the type is used in import history views for past imports)

### 1.3 Update Template Example Rows

**Problem:** Downloadable CSV templates don't include example values for new fields.

**Fix** — `apps/api/app/Modules/Import/Services/MigrationWizardService.php` `generateExampleRows()`:
- **Products case:** Add example values for `category_name`, `tax_rate`, `unit`, `is_active`
- **CompositeItems case:** Add example value for `manual_cost`

---

## Stream 2: `manual_cost` on Composite Items

### 2.1 Database Migration

Add `manual_cost` column to `composite_items` table:

```sql
ALTER TABLE composite_items
ADD COLUMN manual_cost DECIMAL(15,4) NULL DEFAULT NULL;
```

Nullable because existing composite items won't have it set. Absence means "no cost data".

### 2.2 Domain Model

**File:** `app/Modules/Catalog/Domain/Entities/CompositeItem.php`

- Add `manual_cost` to `$fillable`
- Add `manual_cost` cast to decimal
- Add methods:

```php
/**
 * Get the effective cost for this composite item.
 * Recipe calculated cost takes precedence over manual cost.
 * A recipe with null calculated_cost means "never calculated" — falls back to manual.
 * A recipe with zero calculated_cost is a valid result (ingredients have no cost yet).
 */
public function getEffectiveCost(): ?string
{
    $recipeCost = $this->activeRecipe?->calculated_cost;

    if ($recipeCost !== null) {
        return $recipeCost;
    }

    return $this->manual_cost;
}

/**
 * Get the profit margin percentage based on effective cost and base_price.
 * Returns null if no cost data is available or base_price is zero.
 */
public function getMarginPercentage(): ?float
{
    $cost = $this->getEffectiveCost();

    if ($cost === null || $this->base_price === null || (float) $this->base_price === 0.0) {
        return null;
    }

    return round(((float) $this->base_price - (float) $cost) / (float) $this->base_price * 100, 2);
}
```

**Cost resolution semantics:**
- `recipe.calculated_cost` is `null` → recipe never calculated → fall back to `manual_cost`
- `recipe.calculated_cost` is `0.0000` → valid result (ingredients have no cost data yet) → show zero, do NOT fall back
- No recipe exists → fall back to `manual_cost`

### 2.3 Application Layer — DTO

**File:** `app/Modules/Catalog/Application/DTOs/CompositeItemData.php` (or equivalent response DTO)

Add fields:
- `manual_cost: ?string`
- `effective_cost: ?string` (computed from `getEffectiveCost()`)
- `recipe_cost: ?string` (from `activeRecipe.calculated_cost`)
- `margin_percentage: ?float` (computed from `getMarginPercentage()`)

These flow to the frontend for the side-by-side display.

### 2.4 Presentation Layer — Controller/Resource

Update the composite item resource/transformer to include `manual_cost`, `effective_cost`, `recipe_cost`, and `margin_percentage` in API responses.

Update the store/update request validation to accept `manual_cost` as `nullable|numeric|min:0`.

### 2.5 Import Service

**File:** `app/Modules/Catalog/Application/Services/CompositeItemImportService.php`

Add `manual_cost` to the upsert attributes (same pattern as `tax_rate`).

**File:** `app/Modules/Import/Domain/Enums/ImportType.php`

Add `manual_cost` to CompositeItems optional columns and validation rules (`nullable|numeric|min:0`).

### 2.6 Frontend — Composite Item Form

**File:** Composite item create/edit form component

Add `manual_cost` input field next to `base_price`:

```
Sell Price:   [  4.50  ]
Cost:         [  1.20  ]    Margin: 73.3%
```

When inventory module is enabled AND a recipe exists:
```
Sell Price:   [  4.50  ]
Recipe Cost:    1.45         Margin: 67.8%
Manual Cost:  [  1.20  ]    (reference only)
```

The `manual_cost` field is always editable. When recipe cost exists, it's visually secondary (smaller text, muted color) with a label like "Manual estimate (reference)".

### 2.7 Frontend — Import Wizard

Add `manual_cost` to `TARGET_COLUMNS` for `composite_items` in `ImportWizardPage.tsx`.

---

## Stream 3: Inventory as Paid Module

### 3.1 Vertical Configuration Change

**File:** `config/verticals.php`

For `coffee_shop` and `restaurant` verticals:
- Remove `'Inventory'` from `default_modules`
- Add `'Inventory'` to `compatible_extras`

This single change cascades through the existing module gating infrastructure:
- `CompanyConfigService` computes `all_enabled_modules` from defaults + extras
- `RequireModule` middleware checks against this
- `useCompanyConfig().hasModule()` checks against this

### 3.2 Backend Route Gating

**Route files that need restructuring (routes currently share a single middleware group):**

**Catalog module** — `app/Modules/Catalog/Presentation/routes.php`:
- Split into TWO route groups in the same file:
  - **Ungated group** (existing middleware): Composite item CRUD routes
  - **Inventory-gated group** (add `module:Inventory`): Recipe CRUD, recipe line CRUD, variant, and modifier group routes

**Product module** — `app/Modules/Product/routes.php`:
- Split into TWO route groups:
  - **Ungated group**: Category CRUD routes (categories are used by composite items too)
  - **Inventory-gated group** (add `module:Inventory`): Product CRUD, product search, product export routes

**Inventory module** — `app/Modules/Inventory/Presentation/routes.php`:
- Add `module:Inventory` middleware to the entire route group (currently has none)
- Covers: stock-levels, stock-movements, stock-reservations, inventory-countings

**Routes that remain fully accessible (no changes):**
- Composite item CRUD (menu items)
- Category CRUD
- Partner CRUD (suppliers/customers for contact info)
- POS routes
- Treasury, Accounting routes

### 3.3 Frontend Feature Gating

Use `hasModule('Inventory')` to conditionally render. Use the existing `ModuleGuard` component (`apps/web/src/components/guards/ModuleGuard.tsx`) for sidebar nav items — consistent with how Vehicle module gating works.

**Hide when Inventory disabled:**
- Products nav item (sidebar — wrap in `ModuleGuard`)
- Stock nav section (sidebar — wrap in `ModuleGuard`)
- Purchase Orders nav item (sidebar — wrap in `ModuleGuard`)
- Recipe tab/section in composite item detail
- Import types: `products`, `product_images`, `stock_levels` on import dashboard
- "Ingredients" or "Recipe" buttons/links on composite item cards

**Show always:**
- Composite Items (with `manual_cost` field)
- Categories
- Partners
- POS
- Import dashboard (with `partners`, `composite_items`, `opening_balances` only when Inventory disabled)

### 3.4 Composite Item Form Adaptation

The composite item form behaves differently based on module state:

**Without Inventory module:**
- Shows `manual_cost` field (editable, primary cost input)
- No recipe tab/section
- Margin calculated from `manual_cost`

**With Inventory module:**
- Shows recipe builder tab
- Shows `manual_cost` field (editable, but secondary when recipe exists)
- Shows `recipe.calculated_cost` as primary cost when recipe exists
- Side-by-side display when both exist
- Margin calculated from `effective_cost` (recipe cost ?? manual cost)

### 3.5 POS Behavior

**Without Inventory module:**
- Composite items sell normally
- No stock deduction (no ingredients to deduct)
- `manual_cost` available for margin reports
- This already works — `deductCompositeItemStock()` silently returns when no recipe exists

**With Inventory module:**
- Full recipe-based stock deduction as today
- Recipe cost used for margin reports

**`PostCOGSOnInvoice` listener:** This listener fires on invoice posting and iterates physical product lines. When Inventory is disabled, tenants have no Product records — their invoice lines reference composite items, not products. The listener already skips non-product lines, so no COGS entries are created. No module check needed — the existing behavior is safe.

### 3.6 Activation Flow

When a tenant activates the Inventory module (adds to `enabled_extras`):
- Products page becomes visible
- Recipe builder appears on composite items
- Stock tracking activates
- No data migration needed — they start building recipes and adding ingredients from scratch
- Existing `manual_cost` values remain as reference

**Cache invalidation:** `CompanyConfigService` caches config for 24 hours. When `enabled_extras` is modified on the tenant, the cache key `tenant_config:{$tenant->id}` must be busted. Add cache invalidation to the tenant update flow (model observer or explicit cache clear in the service that modifies `enabled_extras`).

No special "migration wizard" needed. The module simply unlocks features.

### 3.7 Existing Tenant Migration

When deploying this change, existing F&B tenants that were created under the old config (Inventory as default module) would lose access unless handled. **Migration strategy:**
- Write a data migration that adds `'Inventory'` to `enabled_extras` for all existing tenants with `vertical` in `['coffee_shop', 'restaurant']`
- This ensures existing tenants keep their current behavior
- Only NEW tenants start without Inventory (they must activate it as a paid extra)

---

## Data Flow Summary

```
Composite Item Sold (POS Receipt)
  |
  +-- Inventory Module OFF:
  |    +-- No stock deduction
  |    +-- No GL entries for cost
  |    +-- Margin reports use manual_cost
  |
  +-- Inventory Module ON:
       +-- Recipe exists:
       |    +-- Stock deducted per recipe (leaf products)
       |    +-- COGS via invoice posting (existing flow)
       |    +-- Margin reports use recipe.calculated_cost
       |
       +-- No recipe yet:
            +-- No stock deduction (silent skip)
            +-- No GL entries for cost
            +-- Margin reports use manual_cost (fallback)
```

## Cost Resolution Priority

```
effective_cost = recipe.calculated_cost ?? manual_cost ?? null
```

- `recipe.calculated_cost = null` → never calculated → falls back to manual_cost
- `recipe.calculated_cost = 0.0000` → valid zero → used as-is, no fallback
- No active recipe → falls back to manual_cost
- No manual_cost either → null (no cost data available)

## Stream 4: Super Admin Module Management

### 4.1 Backend Endpoint

**File:** `app/Http/Controllers/Api/Admin/SuperAdminController.php`

Add endpoint: `POST /admin/tenants/{id}/update-extras`

**Request:**
```json
{
  "enabled_extras": ["Inventory", "Loyalty"]
}
```

**Validation:**
- `enabled_extras` must be an array of strings
- Each extra must be in `compatibleExtras()` for the tenant's vertical (reject invalid module names)
- Return 422 with clear error if an extra is not compatible with the vertical

**Behavior:**
1. Validate extras against vertical's `compatibleExtras()`
2. Update `tenant.enabled_extras`
3. Bust `CompanyConfigService` cache for this tenant
4. Log the action in audit log (who activated what, when)
5. Return updated tenant with new `enabled_extras`

### 4.2 Frontend Admin UI

**File:** `apps/web/src/features/admin/components/TenantDetailModal.tsx`

Add a "Modules" section to the tenant detail modal:
- Show the tenant's vertical and its `compatibleExtras` as a list
- Each extra rendered as a toggle switch (on/off)
- Currently enabled extras are toggled on
- Toggling triggers a confirmation dialog: "Enable/Disable {module} for {tenant name}?"
- On confirm, calls the `update-extras` endpoint with the full new array
- Success toast: "{module} enabled/disabled for {tenant name}"

### 4.3 Admin Route Registration

Add the route to admin routes (requires `auth:sanctum-admin` + `super_admin` middleware, matching existing admin routes).

---

## What's NOT In Scope

- GL entries from `manual_cost` (Option A — reporting only)
- Stock levels import (remains blocked — use opening balances or manual adjustments)
- Recipe/modifier/variant import (manual setup)
- Product images import (deferred)
- Plan-based feature gating (modules are extras, not tied to subscription plan pricing yet)
- Pricing for the Inventory module (business decision, not a code change)
- `RecipeCostCalculationService` fallback to `manual_cost` for sub-composite-items without recipes (future enhancement)
- Tenant self-service module activation (deferred — tenants request, admin approves for now)
- Stripe-integrated module billing (deferred — manual billing for now)

## Testing Strategy

- **Unit tests:** `CompositeItem.getEffectiveCost()` and `getMarginPercentage()` with all combinations:
  - No cost data (both null)
  - Manual cost only
  - Recipe cost only
  - Both present (recipe wins)
  - Recipe cost is zero (valid zero, no fallback)
  - Recipe cost is null (never calculated, falls back to manual)
- **Integration tests:** Import with `manual_cost` field flows through to DB
- **Integration tests:** Column mapping applied correctly during import (mapped headers renamed, unmapped dropped)
- **Integration tests:** Module gating — Inventory-gated routes return 403 when Inventory not in `enabled_extras`
- **Integration tests:** Category routes remain accessible without Inventory module
- **Integration tests:** Composite item CRUD remains accessible without Inventory module
- **Integration tests:** Admin update-extras endpoint validates against vertical's compatible extras
- **Integration tests:** Admin update-extras busts CompanyConfigService cache
- **Frontend tests:** Composite item form shows correct fields based on module state
- **Frontend tests:** Import dashboard shows correct cards based on module state
- **Frontend tests:** Sidebar nav hides Inventory-related items when module disabled
- **Frontend tests:** Admin module toggles in TenantDetailModal
