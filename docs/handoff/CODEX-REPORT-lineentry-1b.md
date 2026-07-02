# CODEX Report: Line Entry Phase 1B

Date: 2026-07-02
Branch: `feat/line-entry-transfers`

## Summary

Implemented Phase 1B transfer line-entry behavior in `CreateStockTransferPage`.

- Added shared `LineItemEntryBar` under `src/components/molecules/line-items/`.
- Wired the transfer page entry bar above `LineItemsTable` while keeping the existing manual per-row add flow.
- Added source-location-first guard for entry-bar search/scan adds; rejected adds show a clear toast and do not append/fill a product line.
- Added scan resolver handling for product, variant, required-variant chooser, and not-found outcomes.
- Added batch allocation reconciliation keyed by product, variant, source location, and quantity.
- Batch-tracked entry-bar adds set pending allocation, auto-run FEFO, and auto-expand the batch panel.
- FEFO under-coverage becomes a blocked `Needs allocation` line state and submit is blocked.
- Re-scanning the same product+variant increments quantity and re-derives FEFO for the new total.

Note: the handoff said `LineItemEntryBar` already existed on this base, but `rg` found no entry-bar/resolver symbols in the worktree. I added the component in the expected molecule folder and exported it from the local barrel.

## Tests

Passed:

- `pnpm --filter @autoerp/web test -- src/components/molecules/line-items/LineItemEntryBar.test.tsx src/components/molecules/line-items/LineItemsTable.test.tsx src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx src/features/stock-transfers/__tests__/CreateStockTransferPage.batchAllocations.test.tsx src/features/stock-transfers/__tests__/CreateStockTransferPage.variants.test.tsx src/features/stock-transfers/__tests__/CreateStockTransferPage.lineItemsTable.test.tsx src/features/stock-transfers/__tests__/CreateStockTransferPage.quantityScale.test.tsx src/features/stock-transfers/__tests__/CreateStockTransferPage.availability.test.tsx`
- `pnpm --filter @autoerp/web typecheck`
- `pnpm --filter @autoerp/web exec eslint src/components/molecules/line-items/LineItemEntryBar.tsx src/components/molecules/line-items/LineItemEntryBar.test.tsx src/components/molecules/line-items/index.ts src/features/stock-transfers/pages/CreateStockTransferPage.tsx src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx src/features/stock-transfers/__tests__/CreateStockTransferPage.batchAllocations.test.tsx`
- `node tools/audit-tanstack-keys.mjs`

Audit output: baseline 14 acknowledged, 0 new, 0 stale baseline entries.
