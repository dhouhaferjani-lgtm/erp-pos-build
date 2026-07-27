# Wave 2 Frontend-Conventions Re-Review (Round 2) — multi-location

> Controller-run 2026-07-20 (post Codex fix commits, tip `399cf4df6`). Reviewer: frontend-conventions-reviewer (Opus). Round 1: `.gates/wave2-fe-conventions-verdict.md` (REJECT).

Branch: `feat/multi-location` (tip `399cf4df6`), worktree `apps/erp.multiloc`
Scope: `git diff origin/dev...HEAD -- apps/web/src/features/inventory apps/web/src/features/stock-transfers apps/web/src/components/organisms/ProductLocationMatrix`
Fix commits reviewed: `f70c86352`, `c36464b64`, `4afba44f8`.

## Guardrails (re-run, not trusted from reports)
- `pnpm lint` — **GREEN**: 0 errors, 6461 warnings (all pre-existing). `audit:keys` 0 new / 0 stale. `audit:design-system` 746 acknowledged / **0 new / 0 stale**. eslint-rules RuleTester passes.
- `pnpm typecheck` — **PASS** (0 errors).
- Baseline honesty: change is **removals-only** (`audit-design-system-baseline.json`: 1×C2 + 4×C3 `OwnerDashboardFilters.tsx` entries removed, 0 additions). No Wave 2 file was absorbed into the baseline; `--write-baseline` was NOT used to hide the diff.

## Round-1 finding disposition
| # | Round-1 finding | Status | Evidence |
|---|---|---|---|
| BLOCKER 1 | Design-system CI red (5 new + 2 stale) | **FIXED** | Raw `<table>`→`DataTable` (`ProductStockLevels.tsx:129,222`); raw search `<input>`→`Input` atom (`StockByLocationPage.tsx:5,32`); `OwnerDashboardFilters.tsx` raw `<button>`→`Button` atom + checkbox removed (genuine refactor, matches the 5 removed baseline lines — not evasion); no raw `<input>/<table>/<select>` remain in any Wave 2 component |
| BLOCKER 2 | `t('common.*')` renders literal on loading/confirm | **FIXED** | Now namespace-prefixed `common:` — `StockByLocationPage.tsx:32`, `RebalancingView.tsx:20`, `TransferSourceSuggestion.tsx:37-38`; `common` is registered in `i18n.ts:429` `ns[]` + `defaultNS:'common'`, so `common:` keys resolve |
| MAJOR 3 | RebalancingView renders raw UUIDs | **FIXED** | `RebalancingView.tsx:15,21` builds `locationNames` from `useScopedLocations()` and renders `locationNames.get(id) ?? id` for both endpoints + `move.row.name` |
| MINOR 4 | ar missing `stock.minQuantity/maxQuantity` | **PARTIALLY FIXED** | ar `stock.*` block added (`ar/inventory.json`), but the key actually consumed is `stockByLocation.minQuantity/maxQuantity` — see MAJOR below (en/fr gap) |
| MINOR 5 | CreateStockTransferPage misleading scoped key | **FIXED** | Relabeled `tenantScopedKey(['locations','all'])` to match `fetchLocations()` (`CreateStockTransferPage.tsx:484`) |
| MINOR 6 | ThresholdEditCell invalidation gap | **FIXED** | Now also invalidates `['product-stock', row.product_id]` (`ThresholdEditCell.tsx:14`) |

## Remaining findings

### MAJOR
1. **`src/features/inventory/components/ThresholdEditCell.tsx:22,23`** — aria-labels `t('stockByLocation.minQuantity')` and `t('stockByLocation.maxQuantity')` (ns `inventory`) resolve in **ar only**; `en/fr/inventory.json` have no `stockByLocation.minQuantity`/`maxQuantity` (the `minQuantity`/`maxQuantity` at `en/fr` line 264-265 live under the sibling `stock` block, not `stockByLocation`). In EN and FR the two `QuantityInput` aria-labels render the literal strings `stockByLocation.minQuantity` / `stockByLocation.maxQuantity` to assistive tech — a CLAUDE.md rule-11 raw-key defect in the two primary locales. Round 1 misdiagnosed this as ar-only MINOR; the fix commit added the ar keys but left en/fr broken (the `stockByLocation` key path was in the code since Wave 2 introduced this new file). Fix (one-line): add `stockByLocation.minQuantity`/`maxQuantity` to `en/inventory.json` + `fr/inventory.json` (or point the aria-labels at the existing `stock.minQuantity`/`stock.maxQuantity`, which exist in all three locales).

### Notes (out of Wave 2 scope — not regressions)
- `InventoryHubPage.tsx` `hub.title`/`hub.description` unresolved in ar — file is **unchanged** on this branch (pre-existing), not a Wave 2 defect.

## Clean (verified)
- Design tokens only in new components (`tokens.card.base`, `textColors.*`, `borderColors.*`); no hardcoded hex/Tailwind color literals; logical-RTL props (`end-0`, `ps-*`). No token interpolation (`hover:${token}` / `${token}/x`).
- Canonical components: `DataTable`, `Input`, `Button`, `PageHeader`, `EmptyState`, `Modal/ModalFooter`, `QuantityInput`, `OffsetPagination` — no raw controls/tables.
- `tenantScopedKey`/`locationScopedKey` on every tenant-data query key (audit:keys 0 new/stale).
- No double-unwrap: `getStockMatrix`/`getRebalance` use `api.get` + `response.data` for `{data,meta}`; single-unwrap `apiGet` elsewhere.
- Money/quantity: `QuantityInput`(decimalPlaces=4)/`formatQuantity`; no `parseFloat`/`Number` on money/qty in new code.
- All other new `stock.*`, `stockByLocation.*`, `create.suggestion.*` keys resolve in en/fr/ar (validated programmatically against the actual `i18n.ts` merge logic).
- Permission gating intact (`inventory.adjust`, `inventory.transfers.create`).

## Rationale
Both round-1 BLOCKERS and the MAJOR are genuinely fixed, with no detector evasion (baseline shrank via real component refactors; lint/typecheck green on re-run). One genuine rule-11 missing-key defect remains — raw i18n keys rendered as `QuantityInput` aria-labels in the primary en/fr locales — which is exactly the raw-key-to-users class this gate rejects. It is trivially fixable but must not be waved through.

VERDICT: REJECT
