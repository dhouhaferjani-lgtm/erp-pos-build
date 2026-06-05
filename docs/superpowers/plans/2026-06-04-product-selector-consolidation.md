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

## Transfer line-table spec (research-backed, 2026-06-04)

Multi-source research across Odoo, SAP S/4HANA STO, Dynamics 365 Business Central, NetSuite, Zoho Inventory, ERPNext (22 sources, adversarially verified). Confidence noted per point.

**Confirmed (high):**
- A transfer line is **product + quantity, with NO per-line unit price / tax / amount** — Zoho's transfer line has `item, description, quantity_transfer, unit, serial_numbers, batches` but no rate/tax/amount (its PO/SO lines do); SAP forbids prices on intra-company STOs. Validates our product+quantity model with cost allocated separately. [zoho.com/inventory/api/v1/transferorders, help.sap.com STO docs]
- The one transfer-specific column with **no PO analog is source on-hand/available** (Zoho `Source Stock`/`Destination Stock`). Add it; we already have per-location available/reserved. [zoho.com/.../transfer-orders.html]
- **Two-step transfers add a second quantity column** (demand vs received/done): Odoo Demand/Done, BC Qty-to-Ship/Shipped/Qty-to-Receive, SAP in-transit vs received → surface only post-draft (in_transit/completed), keep the create screen single-quantity. [odoo two_steps docs, learn.microsoft.com BC, help.sap.com]
- **Header-level source/destination** is the norm; per-line warehouses are rare (ERPNext only). Keep from/to at header. [docs.frappe.io stock-entry]

**Medium / inferred:**
- **Lot/expiry/serial via a per-line picker action/modal gated by the product's tracking flag — not always-on columns.** For pharma (lot+expiry, FEFO) and automotive (serial), add a gated "details" action. [zoho nested batch/serial arrays; BC Get Bin Content]
- Data entry = per-line fill **+ a bulk "Select items" action** for many lines. Barcode-scan-to-add-line / paste-from-spreadsheet / tab-to-append were NOT verified — plausible, not proven. [learn.microsoft.com BC]
- **Add-line control placement is NOT directly documented in any verified source.** Putting it **after the last row** (auto-appending bottom row) is inferred from the universally grid-based, downward-appending entry model and matches our PO `DocumentLineEditor` + the owner's top-down-entry requirement. Treat as a design decision, not a citation.

**Resulting column config for `<LineItemsTable>`:**
- **Transfer (create):** `Product (SKU+name) | Available @ source | Quantity | remove` — add-line **after the last row**; no price/tax/total, no totals footer.
- **Transfer (detail/receive):** add demand-vs-received second quantity column; keep existing `unit_cost_snapshot` / `allocated_transfer_cost` here (NOT on create).
- **Purchase/Sales (PO DocumentLineEditor):** `drag | Article | Description | Qty | Unit price | Tax % | Total | remove` + totals footer.
- **Both:** bottom add-line, inline product search, gated lot/serial/expiry detail action.

Open follow-ups (not blocking): cite exact add-line placement per ERP via UI screenshots; confirm keyboard/scan ergonomics; decide how the source-available readout behaves during draft (avoid implying a reservation before in_transit).

## Workstream B — Unit-aware quantity precision

Problem: qty step/decimals are hardcoded (transfer `decimalPlaces=4`, PO `step=1`). They should reflect each product's **unit of measure** — pieces → integer (step 1, 0 decimals); weight/volume → configured decimals.

1. **Source of truth:** product unit-of-measure + its decimal scale. Review the existing `UnitDecimalSettings` screen and product `unit` fields. Expose `quantity_decimals` (or unit→scale) on the product/line payload — `ProductPickerValue` currently lacks it, so this needs a narrow API/DTO addition.
2. **Helper:** `getQuantityDecimals(unit)` / `useQuantityScale(product)` returns decimals (0 for pieces). `<QuantityCell>` derives `step = 1/10^decimals` and min/validation from it.
3. **Seeding:** ensure demo/seeder products carry a unit of measure (the user noted seeding may be needed). Update `DemoTenantSeeder` and the parapharmacy/coffee-shop seeders.
4. **Apply consistently** across all quantity fields via `<QuantityCell>`.
5. **Tie into the existing precision workstream** ([[project_precision_drift_remediation]] phases 2–12, [[project_inventory_quantity_precision]]). Storage stays at 4-decimal canonical quantity; display/step are unit-driven. Confirm validation accepts the unit's granularity and never truncates stored precision.

**Risk:** keep storage precision (4 decimals) decoupled from display/step precision so no data is lost; fiscal/canonical payloads unaffected.

## Workstream C — Batch & expiry management, incl. in transfers (para-pharmacy first client)

**Context — already exists:** `Batch` (batch_number, manufacturing_date, expiry_date, `expiry_status` OK/APPROACHING/WARNING/CRITICAL/EXPIRED, is_recalled, can_be_sold, days_until_expiry) + per-location `BatchStock` (quantity/reserved/available). Frontend at `features/batches/`. **Gap:** `StockTransferLine` (product_id, quantity, unit_cost_snapshot, allocated_transfer_cost) has **no batch reference** — transfers move product quantity without preserving batch identity/expiry. Sales/POS/receipts batch selection should be audited for FEFO too.

**Goal:** full batch lifecycle including within inventory transfers — the first client is a para-pharmacy, so batch + expiry + FEFO are mandatory.

- **C1 — Transfer-with-batches (PRIORITY, tied to the transfer rebuild in Workstream A):**
  - Transfer line → batch allocation: for batch-tracked products, the gated per-line lot/expiry **detail action** (from the research spec) lets the user pick which batch(es) + qty to move, defaulting to **FEFO**, showing expiry date + `expiry_status`, and blocking expired/recalled (`can_be_sold = false`).
  - On completion, move `BatchStock` source→destination preserving batch identity + expiry; respect the two-step lifecycle (batch qty in-transit then received). WAC unaffected (cost is company-wide per product).
  - Backend: `StockTransferLine` gains batch allocations (batch_id + qty); validate available batch stock at source.
- **C2 — Batch selection across the app (FEFO):** sales/POS (auto-FEFO at sale, block expired/recalled), purchase/goods-receipt (capture batch_number + expiry on receipt), adjustments/counting (batch-aware).
- **C3 — Expiry surfaces:** expiry dashboard/report by status, recall workflow, FEFO/expiry settings.
- **C4 — Vertical gating:** batch/expiry detail action appears only when the product is batch-tracked (pharma); automotive serial reuses the same gated-action pattern with serial instead of batch.

**Risks:** if batch identity is part of the signed/canonical SALE_RECEIPT, treat as a versioned event (like unit_price) — do NOT alter signed bytes silently. Keep per-location batch stock consistent with `stock_levels`. FEFO + recall are correctness-sensitive — test thoroughly.

## Sequencing

1. **Inventory transfer FIRST** — rebuild the transfer create table to the research-backed spec (product + available@source + quantity; add-line after the last row; gated batch/expiry detail-action stub). Reference screen + para-pharmacy priority.
2. **Workstream B** — unit-aware quantity helper + seeding, so transfer (and everything) steps correctly.
3. **Extract `<LineItemsTable>` + `<QuantityCell>`** from `DocumentLineEditor` (behavior-preserving + document-total regression tests), then refactor the transfer onto the shared component.
4. **Workstream C1 — batch-in-transfers** (FEFO, `BatchStock` source→destination move) once the transfer table + detail action exist.
5. **Product-selector consolidation** — retire `ProductSearchSelect` + `ProductSelector` (multi) onto the canonical `ProductPicker` / new `ProductMultiPicker`.
6. **Migrate remaining line-entry screens** (counting, recipes, modifiers, batches) onto the shared primitives.
7. **Workstream C2/C3** — batch selection across sales/POS/receipts + expiry surfaces.

Each step: own branch, TDD, Codex review saved to `docs/superpowers/reviews/`, PR to `dev`, document-total regression checks where applicable.

Each step: TDD, own PR, Codex review, document-total regression checks where applicable.

## Out of scope / notes

- Pre-existing react-doctor findings in touched files (`no-giant-component`, `prefer-useReducer`, the `setActiveIndex` effect, `role` usage on `ProductPicker`) — address opportunistically per file, not the sweep's focus.
- All colors via design tokens (no hardcoded Tailwind colors) — enforced by the PostToolUse hook (CLAUDE.md #18).

## Effort

- Workstream A: medium-large (extraction + 5–6 migrations; fiscally sensitive `DocumentLineEditor`).
- Workstream B: small-medium (helper + seeding + wiring).
- Recommend a dedicated session, own branch, Codex review per migration.
