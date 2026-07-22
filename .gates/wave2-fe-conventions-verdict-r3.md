# Wave 2 Frontend-Conventions Re-Review (Round 3) — multi-location

> Controller-run 2026-07-20. Reviewer: frontend-conventions-reviewer (Opus). Rounds 1–2: `.gates/wave2-fe-conventions-verdict.md`, `.gates/wave2-fe-conventions-verdict-r2.md` (both REJECT).

Branch `feat/multi-location`, worktree `apps/erp.multiloc`, tip `c1541a288`, fix commit `65ef90bb6`.

## 1. R2 MAJOR (ThresholdEditCell aria-labels) — FIXED

The fix repoints both `QuantityInput` aria-labels from the `stockByLocation.*` path (which resolved in ar only) to the sibling `stock.*` path that exists in all three locales:
- `apps/web/src/features/inventory/components/ThresholdEditCell.tsx:20` — `t('stock.minQuantity')`
- `apps/web/src/features/inventory/components/ThresholdEditCell.tsx:21` — `t('stock.maxQuantity')`

Keys verified as direct children of the `stock` block in every locale:
- en: `stock` opens at `apps/web/src/locales/en/inventory.json:241`; `minQuantity`/`maxQuantity` at `:264`/`:265`
- fr: `stock` opens at `apps/web/src/locales/fr/inventory.json:241`; `minQuantity`/`maxQuantity` at `:264`/`:265`
- ar: `stock` opens at `apps/web/src/locales/ar/inventory.json:243`; `minQuantity`/`maxQuantity` at `:262`/`:263`

Namespace wiring intact: component uses `useTranslation('inventory')` (`ThresholdEditCell.tsx:12`), and `inventory` is a registered namespace. No raw i18n key now reaches assistive technology in any of en/fr/ar.

## 2. Stale/dangling key check — CLEAN (with one cosmetic note)

`grep` for `stockByLocation.minQuantity`/`stockByLocation.maxQuantity` across `src/` returns zero consumers. `stock.minQuantity`/`stock.maxQuantity` are consumed only by `ThresholdEditCell.tsx:20-21` (and an unrelated `stock.minQuantityLabel` at `StockLevelsPage.tsx:314`). The lint pipeline's `audit:keys` is the TanStack query-key auditor (`tools/audit-tanstack-keys.mjs`), not an i18n-parity gate, and it is 0/0/0 — no stale key trip.

MINOR (cosmetic, not gated, pre-existing from the r1 fix commit): ar retains orphaned `stockByLocation.minQuantity`/`maxQuantity` at `apps/web/src/locales/ar/inventory.json:270-271` that en/fr do not have and nothing consumes (en/fr `stockByLocation` block at `en:1073` has no such keys). Dead translation entries only — no functional or accessibility impact, no audit failure. Optional cleanup.

## 3. Guardrails (re-run, not trusted)

- `pnpm lint` — GREEN, exit 0: 0 errors, 6461 warnings (all pre-existing; the two `precision/no-parsefloat-on-money` warnings are in `src/types/treasury.ts:183-184`, non-Wave-2). `audit:keys` 0 new/0 stale. `audit:design-system` 746 acknowledged / 0 new / 0 stale. RuleTester `no-dead-tailwind-token-interpolation` passed (5 valid, 5 invalid).
- `pnpm typecheck` — PASS, exit 0.

## 4. Baseline honesty & fix-commit regression scan — CLEAN

- Fix commit `65ef90bb6` does NOT touch `audit-design-system-baseline.json`. Branch-level baseline diff vs `origin/dev` is removals-only (5 `OwnerDashboardFilters.tsx` C2/C3 entries, matching genuine refactors); zero additions; no inventory/stock-transfer/ProductLocationMatrix/ThresholdEditCell file absorbed into the baseline. No `--write-baseline` evasion.
- The apps/web portion of `65ef90bb6` is exactly two string-literal edits (the aria-label key paths). No new raw controls, no hardcoded colors, no unscoped query keys, no `parseFloat` on money/qty introduced. The rest of the commit is CI-config and PHP test gating (out of frontend scope).

All previously-approved Wave 2 conventions remain intact (canonical `DataTable`/`Input`/`Button`/`QuantityInput`, `common:` namespace resolution, location-name rendering, `tenantScopedKey`/`locationScopedKey`, quantity-string payloads).

## Findings
- BLOCKER: none
- MAJOR: none — the sole R2 MAJOR is genuinely fixed, not evaded.
- MINOR: orphaned ar-only `stockByLocation.minQuantity/maxQuantity` dead keys (`apps/web/src/locales/ar/inventory.json:270-271`) — optional cleanup; delete to keep locale files parity-clean.

VERDICT: APPROVE
