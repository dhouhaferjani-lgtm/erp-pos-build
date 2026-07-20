# UoM Display Precision — Design Spec (Rev 1)

**Date:** 2026-07-20
**Status:** Draft — pending adversarial review
**Owner decision:** fix properly + build guards so the class of bug cannot recur; scanner-driven audit approved. Implementation agents: Claude Opus (Codex optional). Branch `feat/uom-display-precision`, worktree `../erp.uom-precision`, base `1c8a5cd94` (= origin/dev 2026-07-20).

## 1. Problem

Quantities are stored canonically at `decimal(N,4)` (precision contract, storage half) but must be **displayed and incremented at the product's unit precision** (`units.decimal_places` — contract display half, `docs/architecture/precision-contract.md` line 10: "per-unit via units.decimal_places"). The display half has **zero enforcement**, no canonical POS pathway, and a structural gap (POS never receives UoM data), so every new quantity surface re-introduces raw scale-4 display. Observed 2026-07-20: POS refill sheet prefills `1.0000` for a Piece-unit product (`units.pc`, `decimal_places=0`).

Root-cause audit (3-agent, 2026-07-20 — findings verified against dev `1c8a5cd94`):

- **Backend**: `app/Modules/Inventory/Application/Services/ReplenishmentSuggestionService.php:16` hardcodes `FLOOR_QUANTITY = '1.0000'`; rounds at fixed `QuantityScale::SCALE = 4` (lines 70–76); never joins products/units. `ReplenishmentQueryService.php:60-77` and `ReplenishmentRequestResource.php:26` pass it through verbatim. Working per-UoM pattern exists: `ProductData::quantityDecimals()` (ProductData.php:157-164), `UnitConversionService.php:46`.
- **POS**: `RequestRefillSheet.tsx:164-178` renders a bare `<input>` with the raw cached string (line 71 assigns `cached?.suggested_qty` unformatted). POS has **no QuantityInput atom**. The product sync payload + sqlite `products` schema carry **no UoM/precision field at all** (`apps/pos/src/types/product.ts:50-110`, `migrations.ts`, `productRepository.ts:195`) — compliance is structurally impossible today. `no-hardcoded-step` ESLint is not wired into `apps/pos/eslint.config.js`.
- **Web**: `ReplenishmentQueuePage.tsx:145,192` render raw wire strings; `AddToPoDialog.tsx:139`, `CreateTransferDialog.tsx:140`, `ReplenishmentCapturePage.tsx:77`, `SupplierInvoiceCreatePage.tsx:538,626` hardcode `decimalPlaces={4}`. Correct pattern exists and is used by `ReceiveGoodsDialog`, `DocumentLineEditor`, `CreateStockTransferPage`, `StockLevelsPage`, `ExpiryWriteOffPage` via `getQuantityDecimals()` (`apps/web/src/lib/quantityScale.ts:7-14`).
- **Data**: seeded UoMs are CORRECT (`units.pc` decimal_places=0 linked to 840/1212 demo products; `ParapharmacySeeder.php:949-977,1004-1013`). But **100% of seeded `stock_levels` rows (4789 shop + 889 warehouse) have NULL min/max** — the order-up-to path has never executed on demo data; every suggestion is the floor.

## 2. Goals

1. Quantity values shown to humans (inputs, tables, prefills) render at the product's unit precision; piece products show whole numbers with step 1.
2. The POS device knows each product's quantity precision (sync + schema).
3. **Guards fail CI on regression** — the class of bug becomes impossible to merge silently, on all three layers.
4. Demo data exercises the real order-up-to path (seeded min/max) with an assertion test pinning it.
5. Docs + reviewer checklists updated so review gates check the display half.

**Non-goals:** unit *conversion* UX changes; per-location UoM; changing canonical storage scale (stays decimal(N,4)); renaming the legacy `products.unit` string column; retrofitting *validation* regexes to per-unit scale on existing FormRequests (storage-side, non-breaking, separately ticketable); mobile app.

## 3. Design

### 3.1 Backend — unit-aware emission (Wave 1)

- **`QuantityScale::formatForUnit(string $value, ?Unit $unit): string`** in `app/Shared/Domain/QuantityScale.php`: rounds via existing `QuantityScale::round($value, $unit?->decimal_places ?? self::SCALE, $unit?->rounding_method ?? HALF_UP)` and emits a string with exactly that many decimals (0 places → no decimal point). Null unit → current scale-4 behavior (backward compatible).
- **`ReplenishmentSuggestionService`**: still *computes* at scale 4 (intermediates rule unchanged) but callers receive unit-rounded output. Add a batched `units` lookup keyed by product (single query via `products.unit_id`, mirrors the existing batched stock query). Floor becomes `'1'` rendered at unit precision (piece → `1`, kg(3) → `1.000`). The floor VALUE stays 1 whole unit — semantics unchanged.
- **`ReplenishmentQueryService`** enrichment and **`ReplenishmentRequestResource`** emit `suggested_qty` at unit precision AND add `quantity_decimals` (int) to the row payload so clients get both the value and the precision.
- **POS product sync payload** (the endpoint feeding `productRepository` — locate in POS sync controller): add `quantity_decimals` per product via `ProductData::quantityDecimals()` pattern (eager-load `unitOfMeasure`; N+1 guard).
- Wire contract stays **snake_case** on POS endpoints (locked convention).
- No server DB migration anywhere in this feature.

### 3.2 POS — schema, atom, sheet (Wave 2)

- **sqlite v62**: `ALTER TABLE products ADD COLUMN quantity_decimals INTEGER` (nullable; null → treat as 4). Idempotent, fresh-install ≡ upgrade (v61 pattern). Sync mapping writes it; pull tolerance: missing field → null (older server).
- **POS `QuantityInput` atom** (`apps/pos/src/components/atoms/QuantityInput.tsx`): mirrors web atom semantics — props `value` (string), `onChange(string)`, `decimalPlaces`; derives `step`; validates with a per-precision regex; emits strings only (rule 19).
- **`RequestRefillSheet`**: use the atom; prefill formats `cached.suggested_qty` through a new `formatQuantity(value, decimalPlaces)` helper (`apps/pos/src/lib/quantity.ts`, bcmath-string based, no parseFloat); submit payload still the raw string the user entered, validated at the product's precision (regex `^\d+(\.\d{1,N})?$` derived, N=decimals; N=0 → integers only).
- The device reads `quantity_decimals` from the products row joined at sheet-open (extend `getOpenRequestForProduct` or a parallel lookup — implementer's choice, cited in plan).

### 3.3 Web — align to existing pattern (Wave 3)

- Replenishment surfaces switch to `getQuantityDecimals(product)`: `AddToPoDialog`, `CreateTransferDialog`, `ReplenishmentCapturePage`; queue/matrix (`ReplenishmentQueuePage.tsx:145,192`) format via a `formatQuantity(value, decimals)` helper (add to `lib/quantityScale.ts` if absent). `SupplierInvoiceCreatePage:538,626` same treatment.
- Requires `quantity_decimals` present on the replenishment feed rows (§3.1) and on product payloads these dialogs already hold (verify each dialog's product source actually carries `quantity_decimals`; where the API resource omits it, add it to that resource — enumerate in plan).

### 3.4 Guards — the "never again" layer (Wave 4, scanner-driven audit)

Each guard ships with a **ratchet baseline**: scanner runs in CI, fails on NEW violations; existing violations are either fixed in Wave 3 or land in a committed baseline file with a ticket. The scanners' first full run IS the application-wide audit; findings beyond replenishment/supplier-invoice get fixed if mechanical or baselined+ticketed if not.

1. **PHPStan `ForbidFixedScaleQuantityEmission`** (new rule alongside `ForbidHardcodedBcmathScale`): in `app/Modules/*/Presentation/**` (Resources/Controllers), flag (a) string literals matching `/^\d+\.0{4}$/` and (b) calls to `QuantityScale::round(..., QuantityScale::SCALE, ...)`. Escape hatch: none in Presentation — use `formatForUnit` or move to Application layer.
2. **Web ESLint `no-literal-decimal-places`**: JSX attribute `decimalPlaces={<numeric literal>}` on `QuantityInput` (component name match) is an error; must be an expression. Autofixable: no.
3. **POS ESLint**: register existing `no-hardcoded-step` in `apps/pos/eslint.config.js`; new rule `no-raw-quantity-input`: `<input>` with `inputMode="decimal"` outside the `QuantityInput` atom source file is an error.
4. **AST scanner `tools/audit-quantity-display.mjs`** (web + pos, wired into lint/preflight/CI like `audit-tanstack-keys.mjs`): flags JSX text/expression rendering of identifiers matching `/(_qty|_quantity|requested_qty|suggested_qty)$/` that are not wrapped in `formatQuantity(...)`/`QuantityInput`. Baseline file + ratchet.
5. **Seeder assertion test**: PHPUnit test asserting DemoPharmacySeeder yields ≥1 shop `stock_levels` row with non-null min & max AND that the replenishment suggestion service returns a non-floor value for one seeded grain (pins the real code path into demo data forever).

### 3.5 Seeders (Wave 4)

`ParapharmacySeeder::seedStockLevels` + `DemoPharmacySeeder::seedTunisiaStock`: set `min_quantity`/`max_quantity` on shop rows (deterministic values derived from seeded quantity, e.g. min = 20% of initial qty rounded to unit precision, max = 150%; exact formula in plan — must produce whole numbers for piece products). RE-RUN branch must backfill NULLs on existing rows (updateOrCreate covers it). Staging demo tenant reseed is already an owed deploy step — this rides along, no new obligation type.

### 3.6 Docs + review loop (Wave 4)

- `docs/architecture/precision-contract.md`: new "Emission & display" section — storage scale-4 / display per-unit split, `formatForUnit`, the guards, the POS data contract.
- Root `CLAUDE.md` rule 19: add one bullet — "Display/emission: quantities surfaced to humans use `units.decimal_places` (backend `QuantityScale::formatForUnit`, web `getQuantityDecimals` + `QuantityInput`, POS `QuantityInput` + `formatQuantity`). Guards: `ForbidFixedScaleQuantityEmission`, `no-literal-decimal-places`, `no-raw-quantity-input`, `audit-quantity-display.mjs`."
- Reviewer agents (`.claude/agents/inventory-costing-reviewer.md`, `fiscal-pos-reviewer.md`, `frontend-conventions-reviewer.md`): add the display-precision invariant to their checklists.

## 4. Compatibility & deploy

- No server migrations. API additive only (`quantity_decimals` new field; `suggested_qty` narrows decimals — POS clients treat it as an opaque string; v61 cache column is TEXT, unaffected).
- Old device + new server: device shows unit-rounded string (e.g. `1`) — regex `^\d+(\.\d{1,4})?$` accepts it; `bccomp` handles it. Safe.
- New device + old server: `quantity_decimals` absent → null → scale-4 fallback = today's behavior. Safe.
- POS device update: sqlite v62 (stacks on owed v60/v61 — single update ships all).
- Staging demo tenant: reseed (already owed) picks up min/max.

## 5. Test strategy (TDD per task)

Backend: unit tests for `formatForUnit` (0/2/3 places, rounding methods, null unit); suggestion service tests extended with piece + kg products asserting `1` vs `1.000` and a real order-up-to value from min/max; resource test asserting `quantity_decimals` in payload; N+1 test on the batched unit lookup. POS: v62 migration idempotence (fresh ≡ upgrade); atom component tests (step, integer-only at 0 places); sheet prefill formatting test (mock cache row `1.0000` + decimals 0 → input shows `1`); sync-mapping tolerance test (missing field). Web: dialog tests asserting derived decimals; scanner self-tests (fixture violations detected, baseline respected). PHPUnit suites run BY PATH only (never full suite — standing rule).

## 6. Risks / open questions for review

- R1: emitting `suggested_qty` at 0 decimals changes strings existing tests pin (`PosReplenishmentControllerTest` asserts `'1.0000'` etc.) — tests must be updated to seed units and assert per-unit strings; verify no OTHER consumer string-compares suggested_qty.
- R2: `units.rounding_method` values map cleanly onto `QuantityScale::round` methods? Verify enum parity before use.
- R3: scanner heuristic false-positive rate on `_qty` identifiers (e.g. keys in payload builders, not display) — scanner must scope to JSX render positions only.
- R4: dialogs' product payloads may lack `quantity_decimals` today — enumerate exact API resources needing the field (plan task).
- R5: POS `TransactionCart` quantity editing is OUT of scope here (cart steps) — confirm owner agrees it's a follow-up ticket, not silent scope creep.
