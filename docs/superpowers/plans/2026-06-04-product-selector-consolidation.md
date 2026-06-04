# UI Consistency & Unit-Aware Precision Sweep — Plan

**Date:** 2026-06-04
**Status:** Plan only — to be executed in a dedicated session with its own branch + per-migration Codex review.
**Supersedes:** the earlier "Product-Selector Consolidation — Phase 2" scope, now folded into Workstream A.
**Shipped already (PR #166, `fix/product-picker-open-on-click`):** ProductPicker open-on-click, alignment, a11y labels, default transfer qty = 1, interim compact transfer table. These are incremental; this sweep replaces the interim table with the extracted shared component.

## Why this sweep

Three issues reported on the stock-transfer page traced to **one root cause: line-entry screens use different table/field components instead of reusing one set.**

- The transfer page is a *lookalike* of the purchase order, not a reuse → different chrome, different fields.
- Quantity step is inconsistent: PO uses a plain input with `step="1"`; the transfer uses `QuantityInput` with `decimalPlaces={4}` → `step=0.0001` (increments by 0.0001, "really frustrating" for piece products).
- Defaults differed (PO qty 1, transfer was empty/0 — fixed in PR #166).

There are also **four** different product selectors (`DocumentLineEditor` search, `ProductSearchSelect`, `ProductSelector`, `ProductPicker`) with inconsistent behavior/return types.

Goal: **(A)** one canonical line-item table + field set used by every line-entry screen, and **(B)** quantity precision/step driven by each product's unit of measure, consistently app-wide.

## Inventory of line-entry surfaces (from the audit)

| Surface | Component today | Line shape |
|---|---|---|
| PO / sales orders / invoices / credit notes / delivery notes | `DocumentLineEditor` (bordered table, drag handles, product/service search, qty/price/tax/total, totals footer) | full `DocumentLine` |
| Stock transfer (`CreateStockTransferPage`) | interim grid table + `ProductPicker` + `QuantityInput` | product + qty (cost allocated separately) |
| Inventory counting (`CreateCountingPage`) | `ProductSelector` (multi) + counted qty | product[] + counted qty |
| Recipes (`RecipeLineEditor` / `CompositeItemFormPage`) | `ProductSearchSelect` + qty | component + qty |
| Modifier groups (`ModifierGroupFormPage`) | `ProductSearchSelect` | product |
| Batches (`BatchForm`) | `ProductSearchSelect` | product |

`DocumentLineEditor` cannot be dropped onto the transfer as-is — it is coupled to the document domain (unit price, tax, tax config, services tab, totals). Genuine reuse means **extracting** the shared chrome + field atoms with a configurable column set.

## Workstream A — Component consolidation

1. **Extract primitives from `DocumentLineEditor`** (behavior-preserving; it is the reference implementation):
   - `<LineItemsTable>` — table chrome (column headers once, divider rows, optional drag-reorder, empty state, optional totals footer) with a **configurable column set**.
   - Inline product selection via the canonical **`ProductPicker`** (already open-on-click + rich object).
   - `<QuantityCell>` — the compact quantity input (unit-aware; see Workstream B).
   - Keep document-only cells (unit price, tax %, line total) as columns supplied by `DocumentLineEditor`.
2. **Refactor `DocumentLineEditor`** to consume the extracted primitives. Behavior-preserving — guard with regression tests on document subtotals/tax/total before vs after (fiscally sensitive). Preserve services tab, `AddQuickProductModal`, designation/notes cells.
3. **Migrate `CreateStockTransferPage`** to `<LineItemsTable>` with columns `[product, quantity, remove]` → now literally the same table + fields as the PO (replaces the interim grid).
4. **Product-selector consolidation** (the old Phase-2 A/B/C):
   - Retire `ProductSearchSelect` → `ProductPicker` (migrate `BatchForm`, `RecipeLineEditor`, `ModifierGroupFormPage`); drop the extra per-caller product fetch since `ProductPicker` returns a rich object.
   - Replace `ProductSelector` (multi) with a `ProductMultiPicker` composing the canonical search row + selected cart; migrate `CouponFormPage`, `PromotionFormPage`, `CreateCountingPage`.
   - `ProductPicker` becomes the single canonical single-select.
5. **Sibling pickers** (`PartnerPicker`/`ServicePicker`/`VehiclePicker`/`BundlePicker`): apply the same open-on-click treatment where a default list is cheap; large datasets may keep a small min-chars/pagination.

**Risk:** `DocumentLineEditor` is the largest, fiscally-sensitive consumer (line totals feed documents). Extraction must be behavior-preserving — snapshot/regression document totals; never alter canonical/fiscal payloads.

## Workstream B — Unit-aware quantity precision

Problem: qty step/decimals are hardcoded (transfer `decimalPlaces=4`, PO `step=1`). They should reflect each product's **unit of measure** — pieces → integer (step 1, 0 decimals); weight/volume → configured decimals.

1. **Source of truth:** product unit-of-measure + its decimal scale. Review the existing `UnitDecimalSettings` screen and product `unit` fields. Expose `quantity_decimals` (or unit→scale) on the product/line payload — `ProductPickerValue` currently lacks it, so this needs a narrow API/DTO addition.
2. **Helper:** `getQuantityDecimals(unit)` / `useQuantityScale(product)` returns decimals (0 for pieces). `<QuantityCell>` derives `step = 1/10^decimals` and min/validation from it.
3. **Seeding:** ensure demo/seeder products carry a unit of measure (the user noted seeding may be needed). Update `DemoTenantSeeder` and the parapharmacy/coffee-shop seeders.
4. **Apply consistently** across all quantity fields via `<QuantityCell>`.
5. **Tie into the existing precision workstream** ([[project_precision_drift_remediation]] phases 2–12, [[project_inventory_quantity_precision]]). Storage stays at 4-decimal canonical quantity; display/step are unit-driven. Confirm validation accepts the unit's granularity and never truncates stored precision.

**Risk:** keep storage precision (4 decimals) decoupled from display/step precision so no data is lost; fiscal/canonical payloads unaffected.

## Sequencing

1. **Workstream B helper + seeding** (small, unblocks correct qty everywhere). Optional interim: transfer qty `step=1` to match the PO until B lands.
2. **Extract `<LineItemsTable>` + `<QuantityCell>`** from `DocumentLineEditor` (behavior-preserving + regression tests).
3. **Migrate transfer** → shared table (replaces interim from PR #166).
4. **Product-selector consolidation** (A.4) folds in.
5. **Migrate remaining screens** (counting, recipes, modifiers, batches).

Each step: TDD, own PR, Codex review, document-total regression checks where applicable.

## Out of scope / notes

- Pre-existing react-doctor findings in touched files (`no-giant-component`, `prefer-useReducer`, the `setActiveIndex` effect, `role` usage on `ProductPicker`) — address opportunistically per file, not the sweep's focus.
- All colors via design tokens (no hardcoded Tailwind colors) — enforced by the PostToolUse hook (CLAUDE.md #18).

## Effort

- Workstream A: medium-large (extraction + 5–6 migrations; fiscally sensitive `DocumentLineEditor`).
- Workstream B: small-medium (helper + seeding + wiring).
- Recommend a dedicated session, own branch, Codex review per migration.
