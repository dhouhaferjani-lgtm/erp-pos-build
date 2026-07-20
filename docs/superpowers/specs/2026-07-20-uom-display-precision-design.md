# UoM Display Precision — Design Spec (Rev 2)

**Date:** 2026-07-20 (Rev 2 same day — 3-lane adversarial review reconciled: inventory A-W-F, fiscal-pos A-W-F, frontend-conventions A-W-F; verdicts in `docs/superpowers/specs/reviews/2026-07-20-uom-display-precision-spec-review.md`)
**Status:** APPROVED FOR PLANNING
**Owner decision:** fix properly + guards so the class cannot recur; scanner-driven audit approved. Implementation: Claude Opus agents. Branch `feat/uom-display-precision`, worktree `../erp.uom-precision`, base `1c8a5cd94`.

## 1. Problem

Quantities are stored canonically at `decimal(N,4)` but must be **displayed and incremented at the product's unit precision** (`units.decimal_places` — `docs/architecture/precision-contract.md` line 10: "per-unit via units.decimal_places"). The display half of the contract has zero enforcement and no canonical POS pathway, so new quantity surfaces re-introduce raw scale-4 display. Observed: POS refill sheet prefills `1.0000` for a Piece-unit product (`units.pc`, `decimal_places=0`).

Verified root causes (3-agent audit + 3-lane review, all against `1c8a5cd94`; backend paths under `apps/api/`):

- **Backend**: `apps/api/app/Modules/Inventory/Application/Services/ReplenishmentSuggestionService.php:16` hardcodes `FLOOR_QUANTITY='1.0000'`, rounds at fixed `QuantityScale::SCALE=4` (70–76), never joins units. `ReplenishmentQueryService.php:60-77` + `ReplenishmentRequestResource.php:25-26` pass `requested_qty`/`suggested_qty` through verbatim.
- **POS (client-only gap)**: the device product feed is **`/products`** (`pullProductsCore` → `apiGet('/products')`, syncService.ts:589), served by `ProductController::index` returning `ProductData` — which **already emits `quantity_decimals`** (ProductData.php:42,93,157-164; `unitOfMeasure` eager-loaded at ProductController.php:102). The POS **client drops the field**: `POSProduct` type, `ProductRow`/`rowToProduct`, `upsertProducts` (productRepository.ts:138-216), sqlite schema — none carry it. `RequestRefillSheet.tsx:164-178` renders a bare `<input>` with the raw cached string (line 71). POS has no QuantityInput atom; `no-hardcoded-step` ESLint exists only in `apps/web/eslint-rules/`. NOTE: `pos/sync/pull` (`SyncController::pull`) is registered but called by NO client (verified) — do NOT touch it; 🎫 separate retirement ticket.
- **Web**: `ReplenishmentQueuePage.tsx:145,192` render raw wire strings; `AddToPoDialog.tsx:139-140` and `CreateTransferDialog.tsx:140-141` hardcode `decimalPlaces={4}` **and `min="0.0001"`** (sub-unit bound unsatisfiable for integer-only inputs); `SupplierInvoiceCreatePage.tsx:538,626`. Correct pattern (`getQuantityDecimals`, `lib/quantityScale.ts:7-14`, clamps [0,4]) used by ReceiveGoodsDialog, DocumentLineEditor, CreateStockTransferPage, StockLevelsPage, ExpiryWriteOffPage. **Two divergent `formatQuantity` helpers exist**: `lib/decimal.ts:206` (PADS to scale — canonical for this feature) vs `lib/format.ts:133` (TRIMS zeros — opposite semantics).
- **Data**: seeded UoMs are CORRECT (units.pc decimal_places=0 → 840/1212 products; ParapharmacySeeder.php:949-977,1004-1013). But 100% of seeded `stock_levels` (4789 shop + 889 warehouse) have NULL min/max — the order-up-to path never runs on demo data.

## 2. Goals

1. Human-facing quantity values render at unit precision; piece products: whole numbers, step 1.
2. POS device knows each product's `quantity_decimals` (client mapping of an already-served field).
3. Guards fail CI on NEW violations of the display half (ratchet mechanics; ESLint guards deliver this via warn + lint-ratchet like existing precision rules — stated explicitly).
4. Demo data exercises real order-up-to with a deterministic pinned assertion.
5. Docs + reviewer checklists carry the display invariant.

**Non-goals:** unit conversion UX; per-location UoM; storage scale changes; legacy `products.unit` string; FormRequest validation regex narrowing (separate ticket); mobile; POS `TransactionCart` quantity editing (follow-up ticket — noted, not silent creep); `SyncController::pull` retirement (ticket).

## 3. Design

### 3.1 Backend — unit-aware emission (Wave 1)

- **`QuantityScale::formatForUnit(string $value, ?Unit $unit): string`** in `apps/api/app/Shared/Domain/QuantityScale.php`:
  `QuantityScale::round($value, $unit?->decimal_places ?? self::SCALE, $unit?->rounding_method?->value ?? self::HALF_UP)` then string-format to exactly that many decimals (0 → no decimal point). ⚠️ `rounding_method` is a `RoundingMethod` **enum** (Unit.php:65) — pass `->value` (cases half_up/floor/ceil match QuantityScale constants exactly; verified). Name `formatForUnit` is free (QuantityScale has only `round`). NOTE (documented, intentional): applying the unit's rounding method changes the *suggested value itself*, not just its rendering — benign for a suggestion.
- **`ReplenishmentSuggestionService`**: computes at scale 4 internally (intermediates rule unchanged); output passes through `formatForUnit`. Batched unit lookup keyed by `products.unit_id` (single query; variants carry NO own unit — verified — product-grain precision is correct). Floor value stays 1 whole unit, rendered per-unit (`1` / `1.000`).
- **`ReplenishmentRequestResource`**: adds `quantity_decimals` (int, from the product's unit, default 4). This single addition serves the POS feed AND all web replenishment surfaces (queue/matrix/AddToPo/CreateTransfer consume the same `ReplenishmentLine` rows — verified). **Decision (R1): `requested_qty` stays scale-4 on the wire** (stable contract; clients format for display using `quantity_decimals`). `suggested_qty` is emitted unit-formatted by the server (it is server-authored advice, not user input).
- **No server change to `/products`** — `quantity_decimals` already ships. No server DB migration anywhere.
- One additional resource change (from R4 enumeration): the purchase-order **receipt/invoice lines** resource feeding `usePurchaseOrderReceiptLinesForSupplierInvoice` adds `quantity_decimals` (for SupplierInvoiceCreatePage:626; the :538 manual-line path already has it via `ProductPickerValue` — ProductPicker.tsx:21-27,73-74).

### 3.2 POS — client mapping, atom, sheet (Wave 2)

- **sqlite v62**: `ALTER TABLE products ADD COLUMN quantity_decimals INTEGER` (nullable, null→4), v61 idempotence pattern (migrations.ts:1899-1911 guarded by `isDuplicateColumnError`). Pre-v62 rows stay NULL until next upsert — safe.
- **`upsertProducts` — four coordinated edits in lockstep** (mismatch = silent param misalignment across the batch): `PARAMS_PER_ROW` 18→19 (productRepository.ts:138; 50×19=950 < 999 OK), per-row value-clause template (:149-151), INSERT column list (:195), `ON CONFLICT ... DO UPDATE SET` block (:197-216). Plus `POSProduct` type (types/product.ts), `ProductRow`/`rowToProduct`.
- **POS `QuantityInput` atom** (`apps/pos/src/components/atoms/QuantityInput.tsx`): props `value` (string), `onChange(string)`, `decimalPlaces`; derives step; per-precision regex (`^\d+(\.\d{1,N})?$`, N=0 → `^\d+$`); strings only. **Uses POS semantic tokens** (border-border-subtle / bg-surface-raised / text-ink / focus:ring-action — rule 18), NOT web `designTokens` imports.
- **`formatQuantity(value, decimalPlaces)`** in new `apps/pos/src/lib/quantity.ts` (bcmath-string, pad semantics, no parseFloat). POS has no existing helper (verified) — this one is legitimately new.
- **`RequestRefillSheet`**: precision from the **`product` prop it already receives** (`product.quantity_decimals ?? 4`) — no repository/join change. Prefill formats `cached.suggested_qty` through `formatQuantity`; input becomes the atom; submit payload unchanged semantics, validated at product precision.
- Compatibility (verified): pull parser whitelists fields (unknown `quantity_decimals` ignored by v60/v61 devices); POS QUANTITY_PATTERN and server regex (`PosReplenishmentController.php:45`) both accept `1` and `1.0000`; replenishment pull validator only type-checks `suggested_qty`. No new queues; no TEXT-timestamp comparisons; zero fiscal surface.

### 3.3 Web — align to existing pattern (Wave 3)

- **Canonical helper: `lib/decimal.ts:formatQuantity` (pad semantics).** Do NOT add a third helper. `lib/format.ts:formatQuantity` (trim) — deprecate: rename to `formatQuantityTrimmed` and migrate its call sites mechanically, OR leave with a `@deprecated` JSDoc pointing at decimal.ts (implementer decides in plan; guard anchors on the canonical import path either way).
- `AddToPoDialog`, `CreateTransferDialog`: `decimalPlaces={getQuantityDecimals(line)}` (rows carry `quantity_decimals` per §3.1) **and derive `min`** from precision (0 places → `min="1"`, else `min=step`). Queue/matrix (`ReplenishmentQueuePage.tsx:145,192`): render `formatQuantity(line.requested_qty, getQuantityDecimals(line))`.
- `SupplierInvoiceCreatePage`: `:538` uses `line.product.quantity_decimals` (already present); `:626` uses the field added to receipt/invoice line payloads (§3.1) + FE types (`supplier-invoices/types.ts:213-234`, `InvoiceLineFormState`) + `prefilledLines` plumbing (:240-283).
- **`ReplenishmentCapturePage.tsx:77` is EXCLUDED**: its standing quantity field renders before any product is selected — no product in scope (verified). Stays scale-4; explicitly exempted in guard baselines. 🎫 optional UX follow-up (product-first entry), not this feature.

### 3.4 Guards — scanner-driven audit (Wave 4)

Ratchet mechanics for all: committed baseline of pre-existing findings; CI fails on NEW entries; baseline shrink-only (guard asserts baseline entries still exist or must be removed). First full run = the application-wide audit; findings either fixed in-wave (mechanical) or baselined + ticketed.

1. **PHPStan `ForbidFixedScaleQuantityEmission`** (`apps/api`, alongside ForbidHardcodedBcmathScale): in `app/Modules/*/Presentation/**`, flag string literals `/^\d+\.0{4}$/` and `QuantityScale::round(..., QuantityScale::SCALE, ...)` calls. Remedy: `formatForUnit` or move to Application layer.
2. **Web ESLint `no-literal-decimal-places`** — **scoped** to avoid the ~22 legitimate fixed-scale sites (percent/money/points/multipliers — e.g. ProductPricingSection margin_percent, loyalty EarningRuleFormModal, expense forms): rule fires only on `QuantityInput` with literal `decimalPlaces` **inside an include-list of product-quantity feature dirs** (`features/replenishment`, `features/purchases`, `features/documents`, `features/inventory`, `features/stock-transfers`, `features/batches`, `features/products/sections/ProductInventorySection*`); all other dirs exempt by design (documented in rule header).
3. **POS ESLint**: **copy** `no-hardcoded-step.js` into `apps/pos/eslint-rules/` (it does not exist there — web-only today) and register in the POS flat config (mechanical — verified plugin pattern). New `no-raw-quantity-input`: `<input inputMode="decimal">` outside atom sources is an error, **with exemptions**: `MoneyInput.tsx` atom, and any input whose accessible name/field binds money (ShiftClosurePage cash count, CustomerAttachPanel verified as the false-positive set) — implemented as a source-file allowlist in the rule; the one true positive today is RequestRefillSheet:167, fixed in Wave 2.
4. **AST scanner `audit-quantity-display.mjs`** (web+pos; wired like `audit-tanstack-keys.mjs` into lint/preflight/CI — template verified): flags JSX render-position identifiers from an **explicit inclusion list** — `requested_qty`, `suggested_qty`, `received_qty`, line-item `quantity` — not wrapped in the **canonical** `formatQuantity` (import-resolved to `lib/decimal.ts` on web / `lib/quantity.ts` on POS) or rendered via `QuantityInput`. **Exclusion set**: `*_count`, `total_*`, `available_*`, `reserved_*`, `stock_quantity`, `min_*`/`max_*`, `component_*`/`required_*`. Baseline + ratchet.
5. **Seeder assertion test** (PHPUnit, path-scoped): after seeding, assert the pinned grain (§3.5) yields a suggestion `> 1` whole-unit-formatted at its named shop location via `suggestionsForLocation(tenant, company, <that location_id>, ...)`.

### 3.5 Seeders (Wave 4)

- **`DemoPharmacySeeder::seedTunisiaStock`** (updateOrCreate — genuinely re-run-safe): add `min_quantity`/`max_quantity` to the values array so re-runs backfill NULLs. Formula must be **deterministic per (product, location)** despite `shopQuantityFor` re-randomizing qty each run: derive from a stable hash of (sku, location code) — e.g. `min = 2 + (hash % 4)`, `max = min × 3` — rounded to unit precision, min floored to ≥1 for 0-dp units.
- **`ParapharmacySeeder::seedStockLevels`** (warehouse; uses `create()`, fresh tenant each run — no backfill semantics needed): add min/max at creation with the same deterministic formula. Do NOT claim updateOrCreate here.
- **Pinned deterministic grain for §3.4.5**: seeder explicitly sets SKU `PB-BAB-0060` at shop `STORE-SOU` to qty=5, min=6, max=12 (fixed literals, not formula) → suggestion = `12 − 5 = 7` → asserted as `'7'` (piece unit, 0 dp). Values chosen so max−available > 1 (non-floor, F3-proof).

### 3.6 Docs + review loop (Wave 4)

- `docs/architecture/precision-contract.md`: new "Emission & display" section (storage-4 vs display-per-unit split, `formatForUnit`, canonical FE helpers incl. the decimal.ts-vs-format.ts resolution, POS data contract, the four guards).
- Root `CLAUDE.md` rule 19 bullet: display/emission invariant + guard names.
- Reviewer agents (`.claude/agents/{inventory-costing,fiscal-pos,frontend-conventions}-reviewer.md`): add display-precision checklist item.

## 4. Compatibility & deploy

No server migrations. API additive (`quantity_decimals` on two resources; `suggested_qty` narrows decimals — validators type-check only, verified). Old device + new server / new device + old server both safe (§3.2). POS device update: sqlite v62 stacks on owed v60/v61 — one update ships all. Staging demo reseed (already owed) picks up min/max. Push-to-dev auto-deploy safe: zero migrations, seeder changes are self-contained.

## 5. Test strategy (TDD per task; suites BY PATH only)

Backend: `formatForUnit` unit tests (0/2/3 dp; half_up/floor/ceil via enum `->value`; null unit → scale-4); suggestion service piece vs kg (`1` vs `1.000`) + real order-up-to from min/max + batched-lookup N+1 test; resource test for `quantity_decimals`; **update pinned-string tests**: `PosReplenishmentControllerTest.php:181-184,201-202,225-226` (suggested_qty — re-seed with explicit units), `ReplenishmentActionsTest.php:97,145`; `requested_qty` pins (:85,93,106) stay green (wire unchanged). POS: v62 idempotence (fresh ≡ upgrade); upsert param-alignment test (19-param row round-trips all fields); atom tests (step, integer-only at 0 dp); sheet prefill test (`1.0000` + decimals 0 → shows `1`); fixtures to revisit: `RequestRefillSheet.test.tsx:130,143,164,206`, `replenishmentSyncService.test.ts:204`, `replenishmentOutboxRepository.test.ts:105,294`. Web: dialog decimals+min derivation tests; queue/matrix formatting test; scanner self-tests (fixture violations caught; exemptions honored; baseline ratchet). Guards: each rule gets fixture-based tests proving the enumerated false-positive set does NOT fire.

## 6. Resolved review record

R1 → §3.1 decision + §5 consumer list. R2 → enum `->value` (§3.1). R3 → inclusion/exclusion lists (§3.4.4). R4 → enumerated (§3.1/§3.3). R5 → TransactionCart ticketed non-goal. Inventory F1-F5 folded; F6 superseded (SyncController::pull has no callers — verified by controller). POS lane 1-4 folded. FE lane 1-8 folded.
