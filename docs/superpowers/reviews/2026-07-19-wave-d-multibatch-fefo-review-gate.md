# Wave D review gate — Multi-batch FEFO demo + browser exercise

**Date:** 2026-07-19  
**Branch:** `feat/replenishment-multibatch-fefo-demo`  
**Base:** local `dev` `684e5a198`  
**Status:** HARD STOP — ready for owner/external review; not merged or pushed

## Delivered

- `DemoPharmacySeeder` deterministically selects four safe batch-tracked warehouse products and reconciles three sellable FEFO batches per product.
- The fixture lots use 90/180/365-day expiries and quantities `3.0000`, `4.0000`, and the positive remainder.
- The selected product's warehouse `DEFAULT` lot is zeroed while shop lots remain untouched; warehouse fixture quantities equal aggregate `StockLevel.quantity` at scale 4.
- Products with positive organic non-default warehouse batch stock are excluded.
- A second full seed reuses the same 12 batches and 12 warehouse batch-stock rows.
- No FEFO service, transfer renderer, migration, permission, fiscal, or other backend behavior changed.

## TDD evidence

The new test first failed at `actual size 0 matches expected size 12`, then passed after the seeder implementation.

- `php artisan test tests/Feature/Seeders/DemoPharmacyBatchSeedingTest.php`
  - 2 tests passed
  - 1,755 assertions
- Pint on the seeder and focused test: pass
- PHPStan on the seeder and focused test: no errors

Prerequisite/baseline checks before implementation:

- `php artisan test tests/Feature/Inventory/StockTransferShowBatchAllocationsTest.php` — 2 passed, 12 assertions
- `pnpm vitest run src/features/stock-transfers/__tests__/StockTransferDetailPage.batchAllocations.test.tsx` — 2 passed
- Batch-allocation rendering merge `e324330ba` / implementation `e77a9436b` is an ancestor of this branch.

## Real browser proof

Local stack: API `127.0.0.1:8020`, Vite `127.0.0.1:5174`, real db-per-tenant demo login `owner@pharmabio.tn`.

1. Reran `DemoPharmacySeeder` for `demo-pharmacy-tn`.
2. In the web UI, created a `4.0000` request for **Sérum Hydratant Bio** from **PharmaBio Tunis — Centre** with note `Wave D FEFO browser proof`.
3. In the replenishment queue, selected the request and created a transfer from **PharmaBio Entrepôt Central**.
4. Opened the transfer detail in an isolated Playwright browser and asserted the rendered allocation row.

Durable records:

- Replenishment request: `019f7b74-1f06-7126-b188-76877becf097` (`fulfilled`, requested `4.0000`)
- Transfer: `TR-2026-00008` / `019f7b77-3183-702f-80cb-2cb41b985f90` (`in_transit`)
- Product: `Sérum Hydratant Bio` / `019f2910-ef67-7350-9b1e-8037986124c4`
- Allocation 1: `DEMO-FEFO-01-A`, expiry `2026-10-17`, quantity `3.0000`
- Allocation 2: `DEMO-FEFO-01-B`, expiry `2027-01-15`, quantity `1.0000`
- Render assertion: `A` appears before `B`; both quantities are visible; authenticated detail page reported zero console errors.

Screenshot: [two-batch FEFO transfer allocation](screenshots/2026-07-19-wave-d/replenishment-fefo-two-batch-transfer.png)

## Environment notes and deviations

- The real demo tenant was behind current `dev`. The first seeder run completed the Wave D batch fixture, then stopped in the existing expense fixture because `expense_metadata.vat_rate` was absent. A scoped `tenants:migrate --tenants=019f2313-4ff7-73aa-99fd-fc6fbbedcce4 --force` applied the five already-merged pending tenant migrations; the seeder then completed. No migration file was added or changed by Wave D.
- The bundled in-app browser runtime failed before creating a tab with `Cannot redefine property: process`. The installed Playwright MCP completed request creation and transfer creation. Its page handle became unstable across full navigations, so the final transfer-detail assertion and screenshot used a fresh isolated Playwright 1.57 browser process against the same local stack and persisted transfer. This is a tooling-only deviation from the preferred in-app browser surface, not a product-flow shortcut.

## Deploy and review notes

- Demo/staging only: rerun `php artisan db:seed --class=DemoPharmacySeeder --force` after deploy.
- Wave D adds no server migration, permission, cache-reset, queue, or device-update obligation.
- The seeder intentionally fails clearly if four safe products with at least `9.0000` warehouse stock cannot be found.
- External review should focus on additive/idempotent selection, preservation of organic batch data, scale-4 reconciliation, and whether the screenshot proves the requested FEFO order.

No merge, push, squash, or cleanup of the linked worktree has been performed.

## Round 11 — adversarial review (2026-07-20)

Reviewer: inventory-costing-reviewer (Opus). Initial verdict: **APPROVE-WITH-FIXES**.

- **Important**: fixture batch numbers were positional (`DEMO-FEFO-%02d` = selection index) while selection was volatile (stock threshold + organic exclusion). Reseeding after real demo usage (the browser-proof transfer itself consumed 4.0000) re-keys lots onto different products, orphans the old lots, and — because `ensureDefaultBatch` tops the DEFAULT lot back up to full `StockLevel.quantity` — inflates aggregate warehouse batch stock past StockLevel, letting FEFO over-allocate.
- Minors: DEFAULT-lot `reserved_quantity` force-zeroed without reconciling StockLevel reservations; idempotency test only pinned clean back-to-back reseed and post-reseed row counts; browser-proof product not pinned to the selection.

Fix applied in `070d88328` (TDD: mutation-then-reseed test failed red against the original seeder — stale lot kept `2.0000`):

- Batch numbers product-anchored: `DEMO-FEFO-{SKU}-{A..C}`.
- Selection stickiness: previously fixtured products sort first (stable `sortBy`).
- In-transaction stale-lot cleanup: `DEMO-FEFO-%` lots not in the written set are zeroed at the warehouse; batch rows kept (historical allocation FKs); lots with a live reservation left untouched (documented self-healing tradeoff).
- Products with any reserved warehouse batch stock are ineligible.
- Tests: post-reseed assertions recheck the full 3/4/remainder reconciliation + batch-id stability; new `test_reseed_after_demo_usage_reconciles_and_degrades_consumed_fixture` pins the failure mode and asserts every ever-fixtured product reconciles aggregate warehouse batch stock == StockLevel at scale 4.

Evidence: seeder test file 3 passed / 1,984 assertions; `StockTransferShowBatchAllocationsTest` 2 passed / 12 assertions; Pint pass; PHPStan no errors.

Reviewer re-verification of `070d88328`: **APPROVE** — "Clean to merge." Residual non-blocking notes: SQLite-vs-PG decimal comparison on the `>= 9.0000` filter (pre-existing), batch_number length safe for demo SKUs.
