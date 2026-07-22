# TICKET: Quantity-display baseline burn-down + SyncController::pull retirement

**Created:** 2026-07-21 (follow-up from the UoM display precision feature, merged to dev `e96026976`).
**Context:** `docs/superpowers/specs/2026-07-20-uom-display-precision-design.md` + gate records in `docs/superpowers/specs/reviews/2026-07-20-uom-display-precision-spec-review.md`. The guards are live and ratcheted (shrink-only); this ticket burns the pre-existing debt they baselined.

## Part A — 42 frontend raw-quantity renders

Source of truth: `apps/web/tools/quantity-display-baseline.json` (42 entries, repo-root-relative `file:identifier` keys spanning `apps/web/src` + `apps/pos/src`). Every entry is a JSX site rendering a quantity without the canonical formatter.

Remedy per site: wrap in the canonical `formatQuantity` (web: `@/lib/decimal`; POS: `@/lib/quantity`) with `getQuantityDecimals(<line/product>)` — which requires the row's payload to carry `quantity_decimals` (add to the serving resource where absent, mirroring `ReplenishmentRequestResource.php:27` / `PurchaseOrderController.php:931`). Delete the baseline entry in the same commit — the scanner FAILS on stale entries, so each fix must remove its line (`pnpm audit:quantity` green = proof).

Suggested batches (by payload source, so resource changes amortize):
1. Documents detail pages + line editors (~14 sites, one `DocumentLine` payload change likely covers most).
2. Inventory/stock/movement views (~7 sites).
3. Stock transfers + goods receipt (~4 sites).
4. POS cart/sale/customer-display + web POS organisms (~9 sites — POS lines may need `quantity_decimals` threaded from the products table).
5. Workshop bundles + work orders + dashboards + misc (~8 sites).

⚠️ `DocumentLines.tsx` + `StockMovementsPage.tsx` already call `formatQuantity` — from the DEPRECATED `lib/format` trim variant. Migrating those two is import-swap + decimals arg only.

## Part B — 9 backend Presentation scale-4 literals

Baselined in `apps/api/phpstan-baseline.neon` under `# uom-display-precision ratchet`:
- `POS/Presentation/Controllers/PosPendingCustomerController.php:84,85,86` — `'0.0000'` balance defaults (MONEY, not qty — correct fix is likely CurrencyScale-resolved defaults, or move defaulting to Application layer)
- `POS/Presentation/Controllers/ReceiptController.php:564,597,612` — `returned_quantity` defaults `'0.0000'`
- `POS/Presentation/Controllers/ZReportSyncController.php:424` — `'0.0000'`
- `Pricing/Presentation/Controllers/DiscountPolicyController.php:52` — `quantity` default `'1.0000'`
- `Procurement/Presentation/Controllers/StandaloneReceiptController.php:83` — `free_qty` default `'0.0000'`

Remedy: quantity defaults → `QuantityScale::formatForUnit()` or hoist to Application layer; money defaults → per-currency scale (rule 19). Remove each baseline entry with its fix; `./vendor/bin/phpstan analyse app/Modules --level 8` green = proof.

## Part C — retire dead `SyncController::pull`

`GET /pos/sync/pull` (`apps/api/app/Modules/POS/Presentation/routes.php:103`, `SyncController::pull`) has ZERO client callers (verified 2026-07-20 — POS pulls products via `/products`/`pullProductsCore`). Before deleting:
1. Re-verify zero callers at execution time (grep web/pos/mobile clients for `sync/pull`).
2. Check the SIBLING route `GET /pos/sync/menu` (`routes.php:104`, `SyncController::menu`) separately — POS uses `/active-menu`, but verify `sync/menu` has no callers before including it; retire only what is provably dead.
3. Delete route(s) + dead controller methods + their tests; if the whole controller empties, delete it. Route-manifest guard may need regeneration (there is a known route-manifest drift backlog — 3 routes — coordinate).

## Part D (bundled minor) — `no-hardcoded-step` RuleTester test

Gate 4 MINOR: `apps/pos/eslint-rules/no-hardcoded-step.js` (and the web original) have no adjacent `.test.mjs`. Add one, chain into BOTH apps' `test:eslint-rules` scripts.

## Acceptance
- `quantity-display-baseline.json` = `[]` and phpstan ratchet comment block gone; all guards still registered and green.
- `pnpm lint` (web+pos), preflight, CI green; PHPUnit by path for touched backend files.
- Dead route(s) removed with proof-of-zero-callers noted in the PR/commit message.

## Addendum 2026-07-22 (multi-location merge)
- `apps/web/src/features/inventory/components/RebalancingView.tsx:21` — `move.quantity` via legacy `@/lib/format` `formatQuantity` (fixed scale). Added to baseline at the feat/multi-location merge because the stock-matrix `RebalanceRow` API does not emit unit `decimal_places`; burning it down requires the backend endpoint to include unit metadata, then switch to `getQuantityDecimals` + canonical `lib/decimal` `formatQuantity`. (Sibling debt: ProductStockLevels.tsx same pattern; its stale `loc.quantity` baseline entry was removed in the same merge.)
