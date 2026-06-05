# Codex Autonomous Task — AutoERP Line-Entry UI Consistency + Unit-Aware Precision + Batch/Expiry Sweep

> Paste this whole file as the task for Codex in its native app, fully autonomous mode.
> Source of truth for scope/columns/sequencing: `apps/erp/docs/superpowers/plans/2026-06-04-product-selector-consolidation.md`. Read it first, then follow it.

## Mission & operating mode
Execute a multi-workstream sweep in the **AutoERP / IziPOS** monorepo so that **every line-entry screen uses one consistent table + field set**, quantity precision is **unit-of-measure aware**, and **batch/expiry is managed app-wide including inside inventory transfers** (the first client is a **para-pharmacy** — batch + expiry + FEFO are mandatory). Work **A→Z across the whole app**, fully autonomously — do **not** stop for approval between steps. **Start with the inventory transfer.**

Autonomy does **not** mean abandoning discipline. Every change must pass the verification gates below before you move on. If you hit ambiguity or a blocker, record it in the PR/review file and proceed with the safest reversible option.

## Read first
- `apps/erp/CLAUDE.md` (agent operational rules — authoritative)
- `apps/erp/docs/conventions/*` (API responses, routing, authorization, frontend types, react-query, forms, DI)
- `apps/erp/.claude/context/architecture.md`
- `apps/erp/docs/superpowers/plans/2026-06-04-product-selector-consolidation.md` (THIS sweep's plan + research-backed transfer-table spec + column config + sequencing)

## Non-negotiable engineering rules (from CLAUDE.md)
- **TDD**: write the failing test first (PHPUnit backend / Vitest frontend), watch it fail, then minimal code. No production code without a failing test first.
- **Strict typing**: no `mixed` (PHP), no `any` (TS); JSONB ↔ DTO.
- **Enums** for every status/type column. **Events are immutable** (version them: `InvoicePostedV2`).
- **Constructor injection only**; never the `app()` helper.
- **Types flow from backend**: run `php artisan typescript:transform` after DTO changes; never hand-edit generated types in `packages/shared`.
- **i18n**: all user-facing text via `t()` keys (a new namespace needs 3 edits in `i18n.ts`).
- **No hardcoded Tailwind colors** — use design tokens from `lib/designTokens` (`tokens`, `textColors`, `borderColors`, `colors`). NOTE: `colors.gray` does NOT exist — gray-50 is `colors.neutral[50]`, hover-red-50 is `colors.hover.red50`. A PostToolUse hook enforces this.
- **Routes**: `['api','auth:sanctum', SetPermissionsTeam::class]`.
- **API responses**: `apiGet`/`apiPost` already unwrap `response.data.data`; for paginated `{data, meta}` use `api.get` and return `response.data`.
- **Precision**: never `number_format` a float for money/quantity — use `CurrencyScale::bcformat` / bcmath. Quantity is stored canonical at 4 decimals.
- **Multi-tenancy is DATABASE-PER-TENANT** — respect tenant + company scope on every query.
- **Module boundaries**: cross-module only via `Shared/Contracts`, Events, or a public Service.

## Verification gates (run before every commit/PR — definition of done per change)
- `./scripts/preflight.sh` (PHPStan L8, Pint, PHPUnit, `tsc`, ESLint) — or individually:
  - Backend: `cd apps/api && composer test && ./vendor/bin/phpstan && ./vendor/bin/pint`
  - Frontend: `cd apps/web && pnpm test && pnpm typecheck && pnpm lint`
- React regressions: `cd apps/web && npx react-doctor@latest --verbose --diff` — **introduce no new findings**. Pre-existing findings in files you touch are acceptable; do NOT refactor unrelated pre-existing items — note them instead.
- **Zero hardcoded colors** in changed files.
- **End-to-end**: actually exercise the flow (create + complete a transfer; complete a sale) — not just compilation.

## Git workflow
- Branch off `origin/dev`, **one branch per step** (e.g. `feat/transfer-table-rebuild`, `feat/qty-unit-precision`, `feat/line-items-table-extract`, `feat/batch-in-transfers`, `feat/product-selector-consolidation`). **Never touch `main`.** PRs target `dev`.
- For each PR, run an **adversarial self-review and SAVE it to a file** under `apps/erp/docs/superpowers/reviews/YYYY-MM-DD-<topic>-codex-review.md` (do not inline-only). Address findings before merge.
- Conventional commits.

## Steps (in order — follow the plan doc)

### Step 1 — Inventory transfer create table (FIRST; reference screen)
`apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx` (+ types/api).
Build the line table to the research-backed spec:
- Columns: **Product (SKU + name) | Available @ source | Quantity | (remove)**. **No** unit price / tax / total / totals footer.
- **Available @ source** = the chosen source location's available stock for the product (per-location available/reserved already exists). Decision support; warn if qty > available (don't necessarily hard-block in draft).
- New line qty defaults to **1**; **add-line control after the last row** (both already done in PR #166 — keep). Optional: auto-append an empty row / Enter-on-last-qty adds a row.
- Inline product search via the canonical **`ProductPicker`** (opens-on-click, lists products, returns rich object).
- **Gated per-line lot/expiry/serial DETAIL ACTION (modal)** — shown only when the product is batch- or serial-tracked. Stub the action now; wire it in Step 4. Not always-on columns.
- Comfortable for 20+ lines (keyboard tab; optional bulk "add multiple products").

### Step 2 — Unit-aware quantity precision (Workstream B)
- Drive quantity decimals/step from each product's **unit of measure** (pieces → integer, **step 1**; weight/volume → configured decimals). Today transfer hardcodes `decimalPlaces=4`; PO uses `step=1`.
- Source of truth: product unit-of-measure + scale (see `UnitDecimalSettings` + product unit fields). Expose `quantity_decimals` on the product/line payload (`ProductPickerValue` lacks it — add narrowly via backend DTO + `typescript:transform`).
- Helper `getQuantityDecimals(unit)` / `useQuantityScale(product)`; `QuantityInput`/`QuantityCell` derive `step = 1/10^decimals`.
- **Seed** products with a unit of measure (`DemoTenantSeeder` + parapharmacy/coffee-shop seeders).
- Keep storage at 4-decimal canonical; only display/step are unit-driven — never truncate stored precision.

### Step 3 — Extract shared line-item primitives (Workstream A)
- Extract from `DocumentLineEditor` (PO/sales/invoice/credit/delivery line table): a generic **`<LineItemsTable>`** (configurable column set, optional totals footer, **bottom add-line**, optional drag-reorder, empty state) + **`<QuantityCell>`** (unit-aware) + inline product search via canonical `ProductPicker`.
- Refactor `DocumentLineEditor` to consume them — **behavior-preserving**. Guard with **regression tests on document subtotal/tax/total** before vs after (fiscally sensitive). Preserve the services tab, `AddQuickProductModal`, designation/notes cells.
- Refactor the transfer (Step 1) onto `<LineItemsTable>` with the transfer column config. The two screens now share the same components — not lookalikes.

### Step 4 — Batch-in-transfers (Workstream C1; para-pharmacy PRIORITY)
- Existing infra: `Batch` (batch_number, expiry_date, `expiry_status`, is_recalled, `can_be_sold`, days_until_expiry) + per-location `BatchStock`; `features/batches/`.
- `StockTransferLine` gains **batch allocations** (batch_id + qty per allocation); validate available batch stock at source.
- Per-line detail action: for batch-tracked products, pick which batch(es)+qty to move, **default FEFO** (earliest expiry first), show expiry + status, **block expired/recalled** (`can_be_sold=false`).
- On completion, move `BatchStock` source→destination preserving batch identity + expiry; respect the **two-step lifecycle** (in-transit then received). WAC unaffected (cost company-wide per product).
- Automotive: same gated action with **serial** instead of batch.

### Step 5 — Product-selector consolidation (Workstream A.4)
- Make `ProductPicker` the single canonical single-select (rich object). Retire `ProductSearchSelect` → migrate `BatchForm`, `RecipeLineEditor`, `ModifierGroupFormPage`. Replace `ProductSelector` (multi) with a `ProductMultiPicker` (canonical search + cart) → migrate `CouponFormPage`, `PromotionFormPage`, `CreateCountingPage`. Update `SharedSelectors.tenantScope` tests.

### Step 6 — Migrate remaining line-entry screens
- Counting, recipes, modifiers, batches onto the shared `<LineItemsTable>` / picker. Consistent add-line-at-bottom, inline search, gated detail action.

### Step 7 — Batch across sales/POS/receipts + expiry surfaces (Workstream C2/C3)
- Sales/POS: auto-FEFO at sale; block expired/recalled. **CAUTION:** if batch identity is part of the signed/canonical `SALE_RECEIPT`, treat it as a **versioned event** (like `unit_price`) — never alter signed bytes silently; coordinate with the fiscal hash chain.
- Purchase/goods-receipt: capture batch_number + expiry on receipt.
- Expiry dashboard/report by status + recall workflow + FEFO/expiry settings.

## Guardrails — do NOT
- Change fiscal/canonical signed payloads (`SALE_RECEIPT`, etc.) without a versioned event — flag and escalate in the PR if it seems required.
- Alter document subtotal/tax/total behavior during the `DocumentLineEditor` extraction.
- Touch `main`. PRs target `dev`.
- Hardcode colors or user-facing strings.
- Silently expand a step's scope; record blockers/ambiguity in the review file and take the safest reversible path.

## Definition of done (whole sweep)
- Every line-entry screen uses the shared `<LineItemsTable>` + canonical `ProductPicker`; transfer/PO/sales/counting/recipes/modifiers are visually + behaviorally consistent; **add-line after the last row everywhere**; **quantity step is unit-aware everywhere**.
- **Batch + expiry managed app-wide, including within transfers** (FEFO, recall, expiry surfaces).
- Each step shipped as its own PR to `dev`, each with preflight green, **no new react-doctor regressions**, zero hardcoded colors, and a saved Codex review file.
