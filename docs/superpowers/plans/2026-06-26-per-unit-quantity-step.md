# Per-Unit Quantity Step + Onboarding-Safe 403s — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every quantity selector steps by its product's unit precision (pieces → 1, kg/L → fractional) instead of a hardcoded 0.0001; and a freshly-provisioned tenant never hits a bare `uom.view` 403 (permission is seeded, and any denial is descriptive + i18n).

**Architecture:** Unit precision already lives on `Unit.decimal_places` and is surfaced as `ProductData.quantity_decimals` (camelCase-free, FE field `quantity_decimals`). The FE helper `getQuantityDecimals()` maps any `{quantity_decimals}` object → a clamped [0,4] integer that `QuantityInput` turns into `step`. The fix is **threading**: carry `quantity_decimals` from product → line/row objects → the `decimalPlaces` prop at every site that today hardcodes `{4}`. Where the consuming payload doesn't yet carry it (saved document lines, stock levels, expiry batches), add it to the backend DTO and eager-load `product.unitOfMeasure`. Workstream B is an independent, additive seeder + exception-handler change.

**Tech Stack:** Laravel 12 / PHP 8.4 (apps/api), React 19 / Vite / TS strict / Vitest (apps/web), Spatie permissions, PHPUnit.

## Global Constraints

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.qty-step` (branch `fix/per-unit-quantity-step` off `origin/dev`). Deps already installed (composer + pnpm).
- **Storage scale is NOT changing.** Quantity stays `decimal(15,4)`. Only the **UI increment** follows the unit. Do NOT add a `step`/`is_integer` column — `decimal_places` is the source of truth.
- **`decimal_places` source of truth seed values** (verified in `UomSeeder.php`): `pc`/`pair`/`doz` = 0; `g`=2, `kg`=3; `ml`=0, `l`=3; `mm`=0, `cm`=1, `m`=2; `min`=0. `ProductData.quantityDecimals()` returns the unit's `decimal_places` **only if `relationLoaded('unitOfMeasure')`**, else falls back to **4**.
- **`getQuantityDecimals(obj)`** (`apps/web/src/lib/quantityScale.ts`) accepts any `{ quantity_decimals?: number | null }` and clamps to `[0,4]`. Reuse it; do not reimplement.
- **Tests by PATH only.** Backend: `php artisan test tests/Feature/...Test.php` — NEVER the full suite (it fatals on a pre-existing broken test and crashes the laptop). FE: `pnpm test -- <path>`.
- **typescript:transform needs array cache:** `CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array BROADCAST_CONNECTION=null php artisan typescript:transform`.
- **Validation/error envelope:** `{ error: { code, message, errors? } }`. Backend errors use `__()` i18n (`lang/en/*.php`, `lang/fr/*.php`). Frontend text via `t()` (rule 11).
- **Precision rule 19:** quantities are strings end-to-end; use `QuantityInput`/`MoneyInput`; no `parseFloat`/`Number()` on money/qty. (Pre-existing `parseFloat` in `DocumentLineEditor`/`DocumentForm` is OUT OF SCOPE — see "Observed but out of scope" — do not expand into it.)
- Preflight (`./scripts/preflight.sh`) green before finishing; reconcile with `origin/dev` (rule 21).

## Observed but OUT OF SCOPE (flag, do not fix here)

- `DocumentLineData::fromModel` formats `quantity` at **currency** scale (`CurrencyScale::bcformat($line->quantity, $scale)` where `$scale`≈3) rather than quantity scale 4 — a pre-existing precision drift. Leave it; note for the precision-drift remediation track.
- `DocumentLineEditor`/`DocumentForm` store quantity as `parseFloat(value)` (a number) — pre-existing rule-19 drift in the document editor's state model. Not this PR.
- POS cart (`apps/web` `CartLineItem` and device `apps/pos`) uses `+1/−1` buttons (already integer steps); no `QuantityInput`, so no per-unit-step bug exists there. **No change.**
- Goods receipt (`GoodsReceiptListPage`) has no `QuantityInput`/`QuantityCell` editable stepper. **No change.**
- `RecipeLineEditor.tsx:261,330` and `ModifierGroupFormPage.tsx:352` (`decimalPlaces={4}`) belong to the **composite/recipe (F&B) module which is DEFERRED to after the parapharmacy launch** (memory `project_composite_inventory_86ing_deferred`). Their line payloads do not carry `quantity_decimals`, so a correct fix requires backend work in a deferred module. **Documented skip** (see Task 11) — do NOT add backend surface for deferred modules in this PR.
- `{2}`/`{1}` literals on loyalty points, tier thresholds, `recipe_multiplier`, `wastage_percent`, and service base qty are **ratios/percents/points, not product-unit quantities** — intentional, leave unchanged.

---

## Phase 0 — Backend data + ProductData contract (the keystone)

### Task 0: Confirm "pieces" unit precision = 0 and ProductData surfaces it

**Files:**
- Test: `apps/api/tests/Feature/Modules/Product/ProductQuantityDecimalsTest.php` (create)
- Reference: `apps/api/database/seeders/UomSeeder.php` (verify, no edit expected), `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:101-108`

**Interfaces:**
- Produces: confidence that `ProductData::fromModel($product)` with an eager-loaded `unitOfMeasure` of code `pc` yields `quantity_decimals === 0`, and `kg` yields `3`; unloaded relation yields fallback `4`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Product\Application\DTOs\ProductData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UomSeeder;
use Tests\TestCase;
// + the project's standard tenant test setup trait/parent — mirror a sibling
//   test in tests/Feature/Modules/Product (e.g. how it boots a tenant DB + seeds).

final class ProductQuantityDecimalsTest extends TestCase
{
    public function test_pieces_unit_yields_zero_quantity_decimals(): void
    {
        $this->seed(UomSeeder::class);
        $product = $this->makeProductOnUnit('pc'); // helper: create a Product with unit_of_measure_id = pc unit, then ->load('unitOfMeasure')

        $data = ProductData::fromModel($product);

        $this->assertSame(0, $data->quantity_decimals);
    }

    public function test_kilogram_unit_yields_three_quantity_decimals(): void
    {
        $this->seed(UomSeeder::class);
        $product = $this->makeProductOnUnit('kg');

        $data = ProductData::fromModel($product);

        $this->assertSame(3, $data->quantity_decimals);
    }

    public function test_missing_unit_relation_falls_back_to_four(): void
    {
        $product = $this->makeProductWithoutLoadedUnit();

        $data = ProductData::fromModel($product);

        $this->assertSame(4, $data->quantity_decimals);
    }
}
```

> Implementation note: write `makeProductOnUnit`/`makeProductWithoutLoadedUnit` against the real schema (valid UUID FKs; check required Product columns first — memory). Mirror an existing Product feature test's tenant bootstrapping exactly.

- [ ] **Step 2: Run it — expect RED** (`php artisan test tests/Feature/Modules/Product/ProductQuantityDecimalsTest.php`). If `quantity_decimals` is already correct, the test goes GREEN immediately — that's the confirmation; keep it as a regression guard.
- [ ] **Step 3:** Only if a test fails because `pc` ≠ 0 in the seeder, fix `UomSeeder.php` for `pc`/`pair`/`doz` to `decimal_places => 0`. (Verified already 0 — likely no edit.)
- [ ] **Step 4: Run — expect GREEN.**
- [ ] **Step 5: Commit** — `test(product): pin quantity_decimals derivation (pieces=0, kg=3, fallback=4)`

---

## Phase 1 — Core: every document type steps by its unit (the felt bug)

This is the priority. `DocumentLineEditor` renders the qty cell for invoice / quote / sales order / purchase order / credit note / delivery note. Two line-creation paths (new-from-search, new-from-quick-modal) + the saved-document reload path must all carry `quantity_decimals`.

### Task 1: Backend — expose `quantity_decimals` on document line payloads

> **REVISED per Codex review (blocking #1, #2; should-fix #1, #2).** Document responses do NOT eager-load per-controller — they centralize through the shared `HandlesDocuments` trait. The fix is the shared relation lists + the two non-trait `DocumentData` paths (generic + credit-note post). Conversion endpoints return RAW Eloquent models (not `DocumentData`) so they cannot carry `quantity_decimals` — scoped out (FE re-fetches via show after navigating). `DraftPersistenceService` does NOT serialize `DocumentData` — dropped.

**Files:**
- Modify: `apps/api/app/Modules/Document/Application/DTOs/DocumentLineData.php` (add field + derive in `fromModel`)
- Modify: `apps/api/app/Modules/Document/Presentation/Controllers/Concerns/HandlesDocuments.php` — add `'lines.product.unitOfMeasure'` to BOTH `defaultRelations()` (:96, currently `['lines']`) and `detailRelations()` (:111, currently `['lines', 'allocations.payment.paymentMethod']`). **This single change covers invoice/quote/sales-order/purchase-order/return-note/delivery-note show + store + update** (they all use these two methods — verified: Invoice show :142/store :280/update :407 etc.).
- Modify: `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:147` (generic `GET /documents/{id}`) — change `->with(['lines', 'vehicleContext'])` → add `'lines.product.unitOfMeasure'`. (Feeds `DocumentData::fromModel` at :160.)
- Modify: `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php` — the **post** path serializes `DocumentData::fromModel($postedCreditNote)` (:359); eager-load `lines.product.unitOfMeasure` on `$postedCreditNote` before that call. (The credit-note **editor** loads its lines from the SOURCE invoice's show endpoint — `CreateCreditNotePage.tsx:108` `GET /invoices/{id}` — already covered by `detailRelations`; `formatCreditNote()` show/confirm does not feed `DocumentLineEditor`, so it is OUT of scope.)
- Test: `apps/api/tests/Feature/Modules/Document/DocumentLineQuantityDecimalsTest.php` (create)

**Interfaces:**
- Produces: `DocumentLineData::$quantity_decimals: int` (FE field `quantity_decimals`), derived as: `relationLoaded('product') && product?->relationLoaded('unitOfMeasure') ? product->unitOfMeasure->decimal_places : 4`.

**Out of scope (verified, scoped not skipped):** `DocumentConversionController` endpoints (:36/:75/:104/:216/:275/:349) return raw `$model->load(['lines',…])` Eloquent JSON, not `DocumentData`, so `quantity_decimals` cannot be injected there without a DTO; after a conversion the FE navigates to the new document and re-fetches via its **show** endpoint (covered above). Leave conversion loads unchanged. `DraftController::autoSave` returns only `{draft_id, saved_at, line_count}` — no line serialization.

- [ ] **Step 1: Write the failing test** — GET a saved invoice with one `pc` line and one `kg` line; assert each line's `quantity_decimals` (0 and 3). Use the real document-create flow + tenant bootstrap from a sibling Document feature test.

```php
public function test_document_show_returns_per_line_quantity_decimals(): void
{
    // seed UOM + roles; create a product on `pc` and one on `kg`;
    // create an invoice (via the real store endpoint or factory) with a line per product.
    $response = $this->getJson("/api/v1/invoices/{$invoice->id}");

    $response->assertOk();
    $lines = collect($response->json('data.lines'));
    $this->assertSame(0, $lines->firstWhere('product_id', $piecesProductId)['quantity_decimals']);
    $this->assertSame(3, $lines->firstWhere('product_id', $kgProductId)['quantity_decimals']);
}
```

- [ ] **Step 2: Run — expect RED** (`assertSame(0, …)` fails: key missing). `php artisan test tests/Feature/Modules/Document/DocumentLineQuantityDecimalsTest.php`
- [ ] **Step 3: Implement.** In `DocumentLineData`:

```php
public int $quantity_decimals,   // add to constructor (after line_total or near quantity)
```
```php
// in fromModel():
quantity_decimals: ($line->relationLoaded('product') && $line->product !== null
    && $line->product->relationLoaded('unitOfMeasure') && $line->product->unitOfMeasure !== null)
        ? $line->product->unitOfMeasure->decimal_places
        : 4,
```
Then make the relation loaded on every `DocumentData` response path:
```php
// HandlesDocuments::defaultRelations() — change the base array:
$relations = ['lines', 'lines.product.unitOfMeasure'];
// HandlesDocuments::detailRelations():
$relations = ['lines', 'lines.product.unitOfMeasure', 'allocations.payment.paymentMethod'];
```
```php
// DocumentController.php:147 generic show:
->with(['lines', 'lines.product.unitOfMeasure', 'vehicleContext'])
// CreditNoteController post path, before DocumentData::fromModel($postedCreditNote):
$postedCreditNote->load(['lines.product.unitOfMeasure']);
```
Eloquent dedupes the `lines` + `lines.product.unitOfMeasure` nested-load pair, so listing both is safe. Confirm `Product` model `unitOfMeasure()` relation name (`apps/api/app/Modules/Product/Domain/Product.php:306`).

- [ ] **Step 4: Run — expect GREEN.**
- [ ] **Step 5: Regenerate types** — `CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array BROADCAST_CONNECTION=null php artisan typescript:transform` (from `apps/api`). Confirm the generated `DocumentLineData` TS type in `packages/shared/types/` now has `quantity_decimals: number`.
- [ ] **Step 6: Commit** — `feat(document): expose quantity_decimals per line for unit-aware qty step`

### Task 2: Frontend — thread `quantity_decimals` through `DocumentLineEditor`

**Files:**
- Modify: `apps/web/src/features/documents/components/DocumentLineEditor.tsx` (Product iface :31, DocumentLine iface :56, handleAddProduct :184, quick-modal mapping :676, QuantityCell :354)
- Modify: `apps/web/src/types/document.ts:18` — add `quantity_decimals?: number` to the **hand-written** `DocumentLineData` interface (this is what `DocumentForm` consumes via the local `Document` type, NOT the generated type — per Codex blocking #4, regenerating `packages/shared/types` alone won't make `l.quantity_decimals` compile).
- Modify: `apps/web/src/features/documents/DocumentForm.tsx:245-255` (saved-doc line mapping)
- Test: `apps/web/src/features/documents/components/__tests__/DocumentLineEditor.quantityStep.test.tsx` (create)

**Interfaces:**
- Consumes: `getQuantityDecimals` from `@/lib/quantityScale`; backend `Product` list payload field `quantity_decimals` (verified: `GET /products` eager-loads `unitOfMeasure` at `ProductController.php:73,90`; `ProductData.quantity_decimals` derived at :103); `DocumentLineData.quantity_decimals` (Task 1).
- Produces: `DocumentLine.quantity_decimals?: number | null`.

> **Test note (Codex should-fix #4):** the qty input's accessible name is `t('sales:lineItems.quantity')` (rendered via `aria-label`). In a multi-line render use `getAllByLabelText` (labels repeat per row); match the actual rendered label string for the test's i18n setup (with the standard mock, that's the key `sales:lineItems.quantity`). A single-line render may use `getByLabelText`.

- [ ] **Step 1: Write the failing test** — render `DocumentLineEditor` with a controlled line whose `quantity_decimals: 0`; assert the qty input `step === '1'`. Second case `quantity_decimals: 3` → `step === '0.001'`; absent → `'0.0001'`.

```tsx
import { render, screen } from '@testing-library/react'
// mock the providers/hooks this component needs (i18n, stores, query) per the
// existing DocumentLineEditor.test.tsx setup — copy its wrapper.

it('steps by 1 for a pieces line', () => {
  renderEditor({ lines: [makeLine({ quantity_decimals: 0 })] })
  expect(screen.getByLabelText(/quantity/i)).toHaveAttribute('step', '1')
})

it('steps fractionally for a kg line', () => {
  renderEditor({ lines: [makeLine({ quantity_decimals: 3 })] })
  expect(screen.getByLabelText(/quantity/i)).toHaveAttribute('step', '0.001')
})

it('falls back to 0.0001 when the line has no unit precision', () => {
  renderEditor({ lines: [makeLine({})] })
  expect(screen.getByLabelText(/quantity/i)).toHaveAttribute('step', '0.0001')
})
```

- [ ] **Step 2: Run — expect RED** (`step` is `'0.0001'` for the pieces case). `pnpm test -- DocumentLineEditor.quantityStep`
- [ ] **Step 3: Implement.**
  - Add `quantity_decimals?: number | null` to the local `interface Product` (line 31) and `interface DocumentLine` (line 56).
  - In `handleAddProduct` (line ~185 object): add `quantity_decimals: product.quantity_decimals ?? null,`.
  - In the quick-modal `onSuccess` mapping (line ~676 `lineProduct`): add `quantity_decimals: product.quantity_decimals ?? null,` (and add the field to the modal's product type if needed).
  - Change the QuantityCell at line 354 from `decimalPlaces={4}` to `decimalPlaces={getQuantityDecimals(line)}` (add the import).
  - In `DocumentForm.tsx:245-255` saved-line mapping: add `quantity_decimals: l.quantity_decimals,` (now present from Task 1).
- [ ] **Step 4: Run — expect GREEN.**
- [ ] **Step 5: Commit** — `feat(documents): qty step follows each line's unit precision`

### Task 3: Frontend — verify all six document types end-to-end

**Files:** Test: extend `DocumentLineEditor.quantityStep.test.tsx` or add an integration assert in `DocumentForm` test.

- [ ] **Step 1:** Add a test that mounts `DocumentForm` for `documentType` invoice with a saved doc whose line has `quantity_decimals: 0` and asserts `step === '1'` (proves the reload path wires through). One test suffices — all six types share `DocumentLineEditor`. **Codex NIT #2:** the existing `DocumentForm.test.tsx` mocks `DocumentLineEditor` as a bare `<div data-testid="line-editor" />` (:41-42) — a step assertion through that mock is false-green. Either render the REAL `DocumentLineEditor` in this test, or assert the `quantity_decimals` prop reaches a richer mock. Do not reuse the bare-div mock for this assertion.
- [ ] **Step 2: Run — GREEN.**
- [ ] **Step 3: Commit** — `test(documents): saved-document reload keeps unit-aware qty step`

---

## Phase 2 — Stock transfer batch-allocation table

### Task 4: Fix the hardcoded `{4}` on the batch table (FE-only)

**Files:**
- Modify: `apps/web/src/features/stock-transfers/pages/CreateStockTransferPage.tsx:178`
- Test: add to the existing `apps/web/src/features/stock-transfers/__tests__/CreateStockTransferPage.quantityScale.test.tsx`

**Interfaces:** Consumes `getQuantityDecimals(line.product)` — `line.product` is a `ProductPickerValue` already carrying `quantity_decimals` (line 552 on the same page already does this correctly for the allocation table).

- [ ] **Step 1: Write the failing test** — in the existing quantityScale test, assert the **batch** table's qty input steps by `1` when `line.product.quantity_decimals === 0` (today it's hardcoded `0.0001`).
- [ ] **Step 2: Run — RED.** `pnpm test -- CreateStockTransferPage.quantityScale`
- [ ] **Step 3: Implement** — line 178: `decimalPlaces={getQuantityDecimals(line.product)}` (import already present from line 552 usage).
- [ ] **Step 4: Run — GREEN.**
- [ ] **Step 5: Commit** — `fix(stock-transfers): batch table qty step follows unit precision`

---

## Phase 3 — Stock adjustment (Stock Levels)

### Task 5: Backend — expose `quantity_decimals` on stock-level rows

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php` (add field + derive)
- Modify: `apps/api/app/Modules/Inventory/Presentation/Controllers/StockLevelController.php` (eager-load `product.unitOfMeasure` on the index query)
- Test: `apps/api/tests/Feature/Modules/Inventory/StockLevelQuantityDecimalsTest.php` (create)

**Interfaces:** Produces `StockLevelData::$quantity_decimals: int` (field `quantity_decimals`), same derivation pattern as Task 1.

- [ ] **Step 1: Write the failing test** — GET `/api/v1/stock-levels` for a tenant with a `pc` product in stock; assert the row's `quantity_decimals === 0`.
- [ ] **Step 2: Run — RED.** `php artisan test tests/Feature/Modules/Inventory/StockLevelQuantityDecimalsTest.php`
- [ ] **Step 3: Implement** — add `public int $quantity_decimals,` to `StockLevelData`; in `fromModel`: `quantity_decimals: ($stockLevel->relationLoaded('product') && $stockLevel->product !== null && $stockLevel->product->relationLoaded('unitOfMeasure') && $stockLevel->product->unitOfMeasure !== null) ? $stockLevel->product->unitOfMeasure->decimal_places : 4,`. In `StockLevelController` index, the query currently eager-loads product+location at `StockLevelController.php:32` — extend the product load to `product.unitOfMeasure` (e.g. `->with(['product.unitOfMeasure', 'location'])`).
- [ ] **Step 4: Run — GREEN.** Regen types (`typescript:transform`).
- [ ] **Step 5: Commit** — `feat(inventory): expose quantity_decimals on stock levels`

### Task 6: Frontend — stock adjustment modal steps by unit

**Files:**
- Modify: `apps/web/src/features/inventory/StockLevelsPage.tsx` (QuantityInput :558 only)
- Test: `apps/web/src/features/inventory/__tests__/StockLevelsPage.quantityStep.test.tsx` (create or extend)

> **Codex should-fix #6:** do NOT add a local `StockLevel` interface field. `apps/web/src/features/inventory/types.ts:12` aliases `StockLevel` directly to the generated `StockLevelData`; after Task 5's DTO change + `typescript:transform`, `selectedStock` carries `quantity_decimals` automatically. A hand-written field here would be the wrong instruction.

- [ ] **Step 1: Write the failing test** — open the adjust modal for a `selectedStock` with `quantity_decimals: 0`; assert the qty input `step === '1'`.
- [ ] **Step 2: Run — RED.**
- [ ] **Step 3: Implement** — change line 558 to `decimalPlaces={getQuantityDecimals(selectedStock)}` (import the helper). No local-interface edit; the generated `StockLevelData` type (Task 5) supplies the field.
- [ ] **Step 4: Run — GREEN.**
- [ ] **Step 5: Commit** — `fix(inventory): stock adjustment qty step follows unit precision`

---

## Phase 4 — Sweep remaining product-unit `{4}` sites

### Task 7: Expiry write-off batch qty (backend + FE)

> **REVISED per Codex blocking #5 — exact path (no "find it"):** `ExpiryWriteOffPage` → `getExpiredBatches()` (`apps/web/src/features/batches/api/batches.ts:169`) → `GET /api/v1/batches/expired` (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:15`) → `BatchController::expired()` (`…/BatchExpiry/Presentation/Controllers/BatchController.php:234`) → `BatchResource::collection` (:242). It's a **Resource, not a DTO**. Chain is valid: `Batch::product()` (`…/BatchExpiry/Domain/Entities/Batch.php:58`) → `Product::unitOfMeasure()` (`…/Product/Domain/Product.php:306`). The eager-load lives in `FEFOInventoryService::getExpiredBatchesWithStock()` (currently `['product','batchStock']` at `…/Domain/Services/FEFOInventoryService.php:303`). FE type is hand-written `ExpiredBatch.product` at `apps/web/src/features/batches/types.ts:297`.

**Files:**
- Modify: `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:303` — eager-load `'product.unitOfMeasure'` (alongside `'product','batchStock'`).
- Modify: `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:45` — inside the `whenLoaded('product', …)` closure add `'quantity_decimals' => ($product->relationLoaded('unitOfMeasure') && $product->unitOfMeasure !== null) ? $product->unitOfMeasure->decimal_places : 4`.
- Modify: `apps/web/src/features/batches/types.ts:297` — add `quantity_decimals?: number | null` to the `ExpiredBatch.product` object type.
- Modify: `apps/web/src/features/batches/pages/ExpiryWriteOffPage.tsx:229` — replace `decimalPlaces={QUANTITY_SCALE}` with `decimalPlaces={getQuantityDecimals(batch.product)}`.
- Test: BE contract test (`/batches/expired` row `product.quantity_decimals` present, 0 for a `pc` product) + FE step test (`pc` batch → step `1`).

- [ ] **Step 1:** BE failing test → implement DTO field + eager-load → GREEN. Regen types.
- [ ] **Step 2:** FE failing test (step `1` for a `pc` batch) → implement → GREEN.
- [ ] **Step 3:** Keep `max={toQuantityString(batch.available_quantity, QUANTITY_SCALE)}` clamp at storage scale 4 (unchanged — the max bound is a storage concern, not the increment).
- [ ] **Step 4: Commit** — `fix(batches): expiry write-off qty step follows unit precision`

### Task 8: Product form reorder point / reorder quantity (FE-only)

**Files:**
- Modify: `apps/web/src/features/inventory/ProductForm.tsx:865,882` (the two `QuantityInput`s for `reorder_point`/`reorder_quantity`)
- Test: `apps/web/src/features/inventory/__tests__/ProductForm.reorderStep.test.tsx` (create)

**Interfaces (REVISED per Codex blocking #4 + should-fix #7/#8):** `UnitDropdown` is opaque — it owns its units query internally (`useUnits(categoryId)` at `UnitDropdown.tsx:27`) and exposes no units list to `ProductForm`. So `ProductForm` must import `useUnits` from `apps/web/src/features/uom/hooks/useUnits.ts:87` itself, watch `unit_id` (already watched at `ProductForm.tsx:673`), find the selected `Unit`, and read its decimals. **Field naming:** the local `Unit` API type (`apps/web/src/features/uom/api/uomApi.ts:22-23`) has BOTH required snake `decimal_places` and optional camel `decimalPlaces` (it's NOT the generated type). Read defensively: `getQuantityDecimals({ quantity_decimals: selectedUnit?.decimalPlaces ?? selectedUnit?.decimal_places })`. No unit selected → helper falls back to 4.

- [ ] **Step 1:** Write a failing test — render `ProductForm` with units loaded (mock `useUnits` per existing ProductForm tests) and `unit_id` set to the `pc` unit; assert both reorder inputs have `step === '1'`.
- [ ] **Step 2: Run — RED.**
- [ ] **Step 3: Implement** — `const { data: units } = useUnits()` in `ProductForm` (reuse the existing hook + its query key; do NOT duplicate). Resolve `const selectedUnit = units?.find(u => u.id === watchedUnitId)`. Compute `const reorderDecimals = getQuantityDecimals({ quantity_decimals: selectedUnit?.decimalPlaces ?? selectedUnit?.decimal_places })` and pass to both `QuantityInput`s (:865, :882).
- [ ] **Step 4: Run — GREEN.**
- [ ] **Step 5: Commit** — `fix(inventory): product reorder qty step follows selected unit`

### Task 9: Dead-code `DocumentLineRow`

**Files:** `apps/web/src/features/documents/components/DocumentLineRow/DocumentLineRow.tsx:134`

- [ ] **Step 1:** Confirm it is rendered nowhere (`rg "DocumentLineRow" apps/web/src --glob '!*.test.*'` → only its own dir). 
- [ ] **Step 2:** Since it's unused, **delete the component + its test** (DRY/YAGNI) rather than maintain a second doc-line renderer. If deletion touches an export barrel, update it. If a reviewer prefers keeping it, instead change `decimalPlaces={4}` → accept a `quantityDecimals` prop threaded from `DocumentLineData.quantity_decimals`. Default action: **delete**.
- [ ] **Step 3:** `pnpm typecheck` + targeted tests green.
- [ ] **Step 4: Commit** — `chore(documents): remove unused DocumentLineRow (superseded by DocumentLineEditor)`

### Task 10: ESLint guard for hardcoded product-unit step

**Files:** `apps/web/eslint-rules/no-hardcoded-step.js` (review), optionally extend.

- [ ] **Step 1:** Read the existing `no-hardcoded-step` rule. It flags hardcoded `step` but not hardcoded `decimalPlaces={<literal>}`. Decide: extend it to warn on `decimalPlaces={<numeric-literal>}` for `QuantityInput`/`QuantityCell` **only** (allow it on percent/ratio inputs by allow-listing those components or by an inline `// eslint-disable-next-line` with reason on the intentional `{1}`/`{2}` ratio sites).
- [ ] **Step 2:** If extending proves noisy/over-broad, **do not ship a false-positive-heavy rule** — instead add a single doc note in `docs/architecture/precision-contract.md` pointing at `getQuantityDecimals`. Prefer the lint rule only if it lands clean on the current tree.
- [ ] **Step 3: Commit** (if rule added) — `chore(lint): flag hardcoded decimalPlaces on quantity inputs`

### Task 11: Document the deferred-module skips

**Files:** This plan + a short note in the PR description.

- [ ] **Step 1:** In the PR body, list `RecipeLineEditor.tsx:261,330` and `ModifierGroupFormPage.tsx:352` as deliberately skipped: composite/recipe module is deferred post-launch and its line payloads lack `quantity_decimals`; fixing them requires backend work in a deferred module (out of scope per rule 4). No code change.

---

## Phase 5 — Workstream B: onboarding-safe 403s (focused)

### Task 12: Seed `uom.*` in the canonical seeder

**Files:**
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (add `uom.view/create/edit/delete` to the permissions list + grant to roles)
- Test: `apps/api/tests/Feature/Modules/Uom/UomPermissionSeedingTest.php` (create)

**Interfaces:** After seeding, a non-admin role that needs units (manager/cashier per vertical — match how the seeder grants other `*.view` read perms) has `uom.view`; admin has all four.

- [ ] **Step 1: Write the failing test** — seed `RolesAndPermissionsSeeder`; create a user with a non-admin role that should read units; `actingAs` + `getJson('/api/v1/uom/units')` → assert **200** (today: 403, permission doesn't exist).

```php
public function test_seeded_non_admin_role_can_read_units(): void
{
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = $this->makeUserWithRole('Cashier'); // pick a role the seeder defines that legitimately needs units
    $this->actingAs($user)
        ->getJson('/api/v1/uom/units')
        ->assertOk();
}
```

- [ ] **Step 2: Run — RED** (403). `php artisan test tests/Feature/Modules/Uom/UomPermissionSeedingTest.php`
- [ ] **Step 3: Implement (REVISED per Codex should-fix #9 — grants are explicit per-role arrays, not a blanket pattern).** Add `'uom.view','uom.create','uom.edit','uom.delete'` to the `$permissions` array (`RolesAndPermissionsSeeder.php:34`; created via `firstOrCreate` at :376 — additive-safe). Admin auto-gets all via `Permission::all()` (:389). Add `'uom.view'` explicitly to the `syncPermissions([...])` array of each non-admin role that reads units (units feed product/document/stock editors): **manager (:394), cashier (:480), accountant (:606), operator (:575), technician (:549)** as applicable; add `'uom.create','uom.edit','uom.delete'` only to **manager**. Leave **viewer (:514)** with `uom.view` (read). **Additive only** — append to existing arrays, do not remove/reorder (shared file, rule 21). Confirm each role array's exact location before editing.
- [ ] **Step 4: Run — GREEN.**
- [ ] **Step 5: Commit** — `fix(uom): seed uom.* permissions in canonical RolesAndPermissionsSeeder`

### Task 13: Descriptive, i18n authorization-denial handler (explicit ability-carrying exception)

> **REWRITTEN per Codex blocking #3.** `Gate::authorize('uom.view')` throws a vanilla `Illuminate\Auth\Access\AuthorizationException` with NO recoverable ability (verified: `Response::deny()` carries no ability code; `AuthorizationException` has no `ability()` accessor; the codebase has NO Spatie permission-middleware path that would carry it). So we cannot extract `'uom.view'` from the exception. Instead, surface the ability **explicitly**: a small `PermissionDeniedException` that carries the ability, thrown via a one-line controller helper. The global handler renders BOTH it (ability named) and any generic `AuthorizationException` (ability null) into the descriptive envelope — so no onboarding tenant hits a bare 403.

**Files:**
- Create: `apps/api/app/Shared/Exceptions/PermissionDeniedException.php` (extends `Illuminate\Auth\Access\AuthorizationException`, carries `public readonly ?string $ability`). Confirm the correct shared-namespace dir by mirroring a sibling exception's location.
- Create: `apps/api/app/Shared/Authorization/AuthorizesAbility.php` trait (or add the method to the existing base controller) with `protected function authorizeAbility(string $ability): void { if (! \Illuminate\Support\Facades\Gate::allows($ability)) { throw new PermissionDeniedException($ability); } }`. (`Gate::allows` facade matches the existing `Gate::authorize` usage style in `UomController`.)
- Modify: `apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php:36,68,102,121,158,212` — replace each `Gate::authorize('uom.xxx')` with `$this->authorizeAbility('uom.xxx')` (use the trait).
- Modify: `apps/api/bootstrap/app.php` — add an `$exceptions->render(...)` closure (the sibling pattern is `render()`, not `renderable()` — Codex should-fix #10) for `AuthorizationException`, placed alongside the existing auth/not-found/validation renders (~:146-182).
- Modify: `apps/api/lang/en/auth.php` + `apps/api/lang/fr/auth.php` (add `permission_denied` + `permission_denied_generic`).
- Test: `apps/api/tests/Feature/Authorization/AuthorizationResponseTest.php` (create)

**Interfaces:** A denied `uom.view` → HTTP 403, body `{ error: { code: 'FORBIDDEN', message: <i18n remediation>, ability: 'uom.view' } }`. A generic policy denial → same envelope with `ability: null` and the generic message. Never leak internals beyond the ability string.

- [ ] **Step 1: Write the failing test** — `actingAs` a user whose role lacks `uom.view` (e.g. seed roles, strip the grant or use a role without it), `getJson('/api/v1/uom/units')`, assert: 403; `error.code === 'FORBIDDEN'`; `error.ability === 'uom.view'`; `error.message` contains the remediation hint.
- [ ] **Step 2: Run — RED** (today: default Laravel `{ message: "This action is unauthorized." }`). `php artisan test tests/Feature/Authorization/AuthorizationResponseTest.php`
- [ ] **Step 3: Implement.** `PermissionDeniedException`:

```php
final class PermissionDeniedException extends \Illuminate\Auth\Access\AuthorizationException
{
    public function __construct(public readonly ?string $ability = null)
    {
        parent::__construct('This action is unauthorized.');
    }
}
```
Handler in `bootstrap/app.php` (one closure catches the base type, branches for the ability):
```php
$exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
    if (! $request->expectsJson()) {
        return null; // default handling for non-API
    }
    $ability = $e instanceof \App\Shared\Exceptions\PermissionDeniedException ? $e->ability : null;
    return response()->json([
        'error' => [
            'code' => 'FORBIDDEN',
            'message' => $ability !== null
                ? __('auth.permission_denied', ['ability' => $ability])
                : __('auth.permission_denied_generic'),
            'ability' => $ability,
        ],
    ], 403);
});
```
Lang (EN): `'permission_denied' => "You do not have permission for ':ability'. An administrator can grant it under Settings → Roles.",` and `'permission_denied_generic' => 'You do not have permission to perform this action. An administrator can grant access under Settings → Roles.',` (+ FR equivalents). Replace the `Gate::authorize` calls in `UomController` with `$this->authorizeAbility(...)`. **Check for and update any existing UomController authz test** that asserts the old default 403 message.
- [ ] **Step 4: Run — GREEN.**
- [ ] **Step 5: Commit** — `feat(api): descriptive i18n 403 envelope with explicit ability for permission denials`

### Task 14: Audit doc for the remaining permission-seeder gaps

**Files:** `docs/superpowers/audits/2026-06-26-permission-seeder-gaps.md` (create)

- [ ] **Step 1:** Write a short audit listing the ~20 permissions present in `PermissionSeeder` but absent from `RolesAndPermissionsSeeder` (pos.*, pricing.*, payments.refund/reverse, quotes.confirm, withholding.*, credit-notes.cancel, etc.), each with the controller that `Gate::authorize`s it and whether a non-admin role plausibly needs it. Mark as **follow-up** — granting is security-sensitive and must be reviewed per role/vertical, not bulk-granted. Reference `project_go_live_security_audit_2026_06_14`.
- [ ] **Step 2: Commit** — `docs(authz): audit remaining permission-seeder gaps (follow-up)`

---

## Phase 6 — Verify & integrate

### Task 15: Preflight, typecheck, reconcile

- [ ] **Step 1:** Regenerate types once more if any DTO changed; ensure `packages/shared/types` diff is committed.
- [ ] **Step 2:** FE: `pnpm lint && pnpm typecheck` and run the new/affected tests by path. (Do NOT run the full backend suite.)
- [ ] **Step 3:** Backend: run each new test file by path; `./vendor/bin/phpstan` on changed files; `./vendor/bin/pint` changed files.
- [ ] **Step 4:** `git fetch origin dev`; if behind, `git merge origin/dev` first (rule 21). Resolve, re-run affected tests.
- [ ] **Step 5:** Open PR (or hand to `finishing-a-development-branch`). PR body: acceptance-bar checklist + the deferred-module skips (Task 11) + link to the audit doc (Task 14).

## Acceptance bar (from handover)

- A `pc` product: qty arrows step by **1** in invoice, PO, SO, quote, credit note, delivery note, stock transfer (allocation + batch tables), stock adjustment. (POS cart already +1/−1; goods receipt has no stepper — N/A.)
- A `kg`/`l` product still steps fractionally (its `decimal_places`, e.g. `0.001`).
- A freshly-seeded non-admin role can read `/api/v1/uom/units`; any authorization denial returns a descriptive, i18n 403 naming the ability + remediation.
- Tests cover pieces-vs-fractional step (FE) and the per-line/per-stock `quantity_decimals` contract (BE) and the permission grant. Preflight green.

## Self-review notes

- **Spec coverage:** Workstream A sites all mapped to tasks (1–11); A.5 (rounding on unit change) is N/A — no in-place product/unit swap exists in `DocumentLineEditor` (verified). Workstream B mapped to 12–14, scoped to the chosen "uom.* + handler + audit" option.
- **Type consistency:** new field is `quantity_decimals: int` on every DTO (DocumentLineData, StockLevelData, batch product, ProductData-existing); FE field `quantity_decimals?: number | null`; helper `getQuantityDecimals` used everywhere — no divergent names.
- **No backend surface added to deferred modules** (recipe/modifier) — documented skip.
