# Codex Handover — UoM quantity-display baseline burn-down — 2026-07-22

> Autonomous Codex-desktop work. Source ticket: `docs/handoff/TICKET-uom-baseline-burndown-2026-07-21.md` (read it first — this brief adds the operating envelope + verified reuse contracts; the ticket owns the remedy detail). Same operating rules as the replenishment run (`docs/superpowers/plans/2026-07-10-replenishment-requests.md` Global Constraints, verbatim: TDD, targeted suites only, snake_case wire, design tokens, `claude -p --model opus` adjudication for routine plan-vs-reality stops, HARD-STOP gate at the end of each wave for external Opus review). **Commit at every TDD green** — WIP commits are fine, squash later (2026-07-13 worktree incident: uncommitted work is unrecoverable).

## Setup

- Fresh worktree `../erp.uom-burndown`, branch `chore/uom-baseline-burndown` off **origin/dev** (fetch first). Never touch other worktrees.
- ⚠️ **Coordination:** multi-location Wave 4 is in flight in `../erp.multiloc` and will merge to dev with inventory/stock/transfer file changes. Known-overlapping files (verified against the multiloc branch tip): `GoodsReceiptController.php`, `POS/.../ReportController.php`, `ZReportResource.php`, `AnalyticsController.php`, `Procurement/Application/StandaloneReceiptService.php`. If a baseline file you're editing has conflicting churn at merge time, resolve by REBASING onto dev and re-running the scanner — never by widening scope. If a conflict is non-mechanical, STOP for adjudication.
- The ratchet scanner **fails on stale entries**: every fix commit must delete its `apps/web/tools/quantity-display-baseline.json` entry (Part A) or its `apps/api/phpstan-baseline.neon` ratchet entry (Part B) in the SAME commit. `pnpm --filter @autoerp/web audit:quantity` (or `pnpm audit:quantity` from `apps/web/`) green + phpstan green = per-commit proof.

## Task 0 — route-manifest drift fix (fold-in, one commit)

`scripts/factory/check-manifest-drift.sh` currently FAILS preflight on 3 stale routes (`/expenses/analytics`, `/expenses/recurring`, `/inventory/placement` — added 2026-07-13 without regeneration). Run `node scripts/factory/gen-route-manifest.mjs`, verify the diff is exactly those 3 additions (all permission-gated: `expenses.view`, `expense-recurrences.view`, `inventory.view`), commit both manifests. This MUST land first — later waves run preflight and would red-gate on it.

## Verified reuse contracts (do not deviate; STOP if reality differs)

| Contract | Verified fact |
|---|---|
| Web formatter | `formatQuantity(amount: string \| number, scale = 4)` from `@/lib/decimal` (`apps/web/src/lib/decimal.ts:206`) |
| Web decimals | `getQuantityDecimals(product: QuantityScaleProduct \| null \| undefined): number` from `@/lib/quantityScale` (`apps/web/src/lib/quantityScale.ts:7`) |
| Gold-standard web call site | `ReplenishmentQueuePage.tsx:16-17,147` — `formatQuantity(line.requested_qty, getQuantityDecimals(line))` |
| DEPRECATED web formatter | `lib/format` `formatQuantity` (trim variant) — **~13 baselined files** already import it (DocumentLines, the 6 document detail pages, EntryExitNotesPage, StockLevelsPage, StockMovementsPage, ProductMovementsTab, ProductStockLevels). Fix = import-swap to `@/lib/decimal` + ADD `getQuantityDecimals(<line/product>)` as the scale arg — NEVER a bare swap (trim → fixed-4 silently changes rendering). ⚠️ Conservation: a swapped shared import changes EVERY call site in the file — thread decimals to all of them, incl. non-baselined ones (e.g. `StockMovementsPage.tsx` `:248` baselined + `:258`/`:265` `quantity_before`/`quantity_after` not baselined — all three get the decimals arg) |
| POS formatter | `formatQuantity(value: string, decimalPlaces: number \| null \| undefined)` from `@/lib/quantity` (`apps/pos/src/lib/quantity.ts:19`) |
| POS decimals source | `products.quantity_decimals INTEGER` already exists — sqlite migration **v62** `add_quantity_decimals_to_products`. **NO new POS migration expected.** If a POS payload (cart line, sale line, customer display) lacks decimals, thread it from the products row at read time. Only add a migration if a table genuinely cannot reach it — check current max version first and STOP to report why |
| Backend payload pattern | serve `quantity_decimals` on the resource where absent — mirror `ReplenishmentRequestResource.php:27` / `PurchaseOrderController.php:931` |
| Backend quantity emission | `QuantityScale::formatForUnit()` or hoist defaulting to Application layer |
| Money ≠ quantity | TWO of the 9 backend sites are **MONEY**, not quantity — fix per rule 19 (`CurrencyScale` + injected `CurrencyScaleResolverInterface`), NEVER QuantityScale: (1) `PosPendingCustomerController.php:84-86` balance defaults; (2) `ZReportSyncController.php:424` — the ticket misfiles this one as quantity; see Wave 3 |
| Part B per-site disposition | **Response-serialization defaults** (`ReceiptController.php:564,597,612` — computed `returned_quantity` convenience field): plain formatting fix. **Stored-write defaults** (`PosPendingCustomerController.php:84-86` — writes onto a fresh `Partner::create` in a transaction; `StandaloneReceiptController.php:83` — `free_qty` into a procurement goods-receipt DTO): fix must remain a ZERO-value write and must not change any DTO shape. **Event-payload default** (`ZReportSyncController.php:424`): see Wave 3 — hardest site, do it last |
| Scanner | `pnpm audit:quantity` from `apps/web/` → `tools/audit-quantity-display.mjs`; baseline = `apps/web/tools/quantity-display-baseline.json` (42 `file:identifier` entries); exemption (b) = `value` on `<QuantityInput>` only — see the editable-input section for the ONE authorized exemption extension (`QuantityCell`) |
| PHPStan ratchet | `apps/api/phpstan-baseline.neon` block at `# uom-display-precision ratchet` (~line 1077), identifier `precision.fixedScaleQuantityLiteral`, 9 sites |

## Editable-input baseline entries — do NOT wrap in formatQuantity (BLOCKER-class if violated)

Three baseline entries are **editable input `value=` props**, not display renders. Wrapping a controlled input's value in `formatQuantity` breaks editing:
- `DocumentLineEditor.tsx:575` and `CreateStockTransferPage.tsx:738` — both are `<QuantityCell … value={line.quantity}>`, ALREADY correct (QuantityCell → `LineItemsTable.tsx:178-195` provably threads `decimalPlaces` into the sanctioned `QuantityInput` atom; DocumentLineEditor already passes `getQuantityDecimals(line)` at `:572`). They're baselined only because the scanner doesn't recognize the wrapper. Remedy: **extend the scanner's exempt-wrapper set to include `QuantityCell`** (one-line detector change in `audit-quantity-display.mjs`, exemption (b) alongside `QuantityInput`) + delete both entries. The commit message MUST justify the exemption (QuantityCell threads decimalPlaces → QuantityInput) so the gate reviewer reads it as a legitimate detector fix, not evasion. This is the ONLY authorized scanner edit in this project.
- `BundleComponentFormModal.tsx:372` — `state.quantity` on a raw `<Input type="text" inputMode="decimal">`: genuine violation. Remedy: migrate the input to `QuantityInput`/`QuantityCell` with proper `decimalPlaces`, not a formatter wrap.

## Wave 1 — web FE, document + inventory + transfer surfaces (ticket batches 1–3, ~26 sites)

Documents detail pages/line editors (~14), inventory/stock/movement views (~7), stock transfers + goods receipt (~4). Group commits by payload source so backend `quantity_decimals` resource additions amortize — but expect SEVERAL resource touches, not one: the documents cluster spans 6 document types with distinct detail endpoints. Each display site: canonical `formatQuantity` + `getQuantityDecimals(<line/product>)`; add `quantity_decimals` to the serving resource where absent (backend change + PHPUnit by path). Delete each baseline entry with its fix.
**HARD-STOP Gate 1** after batches 1–3: structured report (sites fixed, resources touched, scanner count remaining, suites run).

## Wave 2 — POS + web-POS + workshop/misc surfaces (ticket batches 4–5, ~16 sites)

**Pre-authorized dead-end remedy — `DiscountBreakdownChart.tsx` (`p.quantity`):** the feed (`analyticsApi.ts:56` `top_discounted_products`) is a `product_name`-grained aggregate with pre-summed `quantity: number`, no `product_id`, served by an analytics controller (no JsonResource) — the standard "add to the serving resource" remedy CANNOT apply. Authorized fix: regroup the backend aggregate on `product_id` (keep `product_name` for display) and carry `quantity_decimals` via the UoM join; update the FE type (snake_case wire). If the regroup changes chart semantics beyond splitting same-named products, STOP for adjudication instead of shipping `formatQuantity(p.quantity)` at default scale-4 — that would clear the scanner while violating unit-precision intent (the scanner checks for the wrap, not decimal correctness).

POS cart/sale/customer-display (3 device sites — thread `quantity_decimals` from the products table; NO schema change, the column exists (v62) and is populated by product sync `productRepository.ts:201,207,227`; lines reach it via `item.product` / `line.product?`) + web POS organisms (~6) + workshop bundles/work orders/dashboards/misc (~8). POS specifics:
- `CartLineItem.tsx` / `CustomerDisplayPage.tsx` hold `item.quantity` as a JS **number** (increment logic) — converting for `formatQuantity(value: string, …)` means `String(item.quantity)`, NEVER `parseFloat`/`Number` (the `no-parsefloat-on-money` guard fires); do not touch the increment logic itself.
- `SaleDetailModal.tsx` renders historical receipt lines where `line.product?` may be absent → null decimals → `formatQuantity` falls back to scale 4 (`clampQuantityDecimals`, `lib/quantity.ts`). That fallback is ACCEPTED — never fabricate decimals onto an already-signed historical receipt.
POS tests: vitest by path in `apps/pos`.
**HARD-STOP Gate 2** after batches 4–5: same report shape; `quantity-display-baseline.json` must be `[]`.

## Wave 3 — backend literals + dead-route retirement + guard test (ticket Parts B, C, D)

1. **Part B** — 9 Presentation scale-4 literals per ticket, using the per-site disposition table above. Quantity defaults → `QuantityScale::formatForUnit()` or hoist to Application. Money defaults → per-currency scale (rule 19):
   - `PosPendingCustomerController.php:84-86` — stored writes onto a fresh Partner (NOT serialization). Use `getScaleSafe($company->currency, 3)`-style resolution (never a bare no-arg `getScale()`); result must remain a zero balance. Partner balances are outside the fiscal chain — safe.
   - `ZReportSyncController.php:424` — ⚠️ **the ticket misfiles this as quantity; it is MONEY** (`$varianceRaw ?? '0.0000'` → `new VarianceAmount(amount, $currencyCode)` → dispatched as event-sourced `CashCountRecorded` → persisted as fraud-alert `variance_amount`). Fix per rule 19 with `$currencyCode` — but `$currencyCode` is only computed at `:431`, AFTER `:424`, so hoist/reorder the currency resolution first. The fix must stay a **zero-value** default and must NOT alter the `VarianceAmount` value object or the event structure in any way (rule 8: events are immutable forever).
   Remove each phpstan-baseline entry with its fix; `./vendor/bin/phpstan analyse app/Modules --level 8` green.
2. **Part C** — retire `SyncController::pull` (route at `apps/api/app/Modules/POS/routes.php:103` — NOTE the ticket cites a `Presentation/routes.php` path that does not exist; the controller is `POS/Presentation/Controllers/SyncController.php`): re-verify zero callers at execution time — current-tree grep of web/pos/mobile for `sync/pull` AND `git log -S'sync/pull' -- apps/pos apps/web apps/mobile` to prove no supported shipped build ever called it. Check sibling `sync/menu` (`routes.php:104`) independently — retire only what is provably dead, note proof in the commit message. These are pull/reference endpoints: a stale field device hitting a deleted route gets a 404 soft-fail (stale cache), never data corruption. `SyncController::syncCloseShift` (route `:105`) STAYS — the controller does not empty. Delete route(s) + dead methods + their tests.
3. **Part D** — RuleTester `.test.mjs` for `no-hardcoded-step` (POS `apps/pos/eslint-rules/no-hardcoded-step.js` AND the web original), chained into both apps' `test:eslint-rules`.
**HARD-STOP Gate 3 (pre-merge)**: full report; acceptance = ticket's acceptance block (baseline `[]`, ratchet block gone, all guards still registered and green, lint/typecheck/preflight green, PHPUnit by path for touched backend files).

## Explicitly OUT of scope
- Any quantity site NOT in the two baselines (the ratchet already blocks new drift; don't sweep).
- `stock_levels` min/max seeding, per-location reorder policy — multi-location scope.
- Anything touching fiscal signing surfaces. The Part B sites are NOT all display literals — follow the per-site disposition table (response-serialization vs stored-write vs event-payload). Hard rule: stored-write/event money defaults stay zero-valued at per-currency scale with no DTO/event shape change; if any fix would alter signed bytes or a stored event's structure, STOP.
- Backend `services.*` permissions, tiered-reporting seeder (separate tickets).

## Verification (per commit / per wave)
- Web (from `apps/web/`): `pnpm lint` (includes `audit:quantity`), `pnpm typecheck`, vitest by path.
- POS (from `apps/pos/`): `pnpm lint`, vitest by path.
- API: phpstan `app/Modules` level 8; PHPUnit **by path only** (never the full suite).
- Preflight only at Gate 3 (it includes the manifest drift check — green after Task 0).

## Deploy notes for whatever merges
Display-only + dead-route deletion: NO tenant migrations, NO permission reseed, NO POS sqlite bump. POS devices pick up the rendering fixes with the next app build (which they already owe for v60–v62).
