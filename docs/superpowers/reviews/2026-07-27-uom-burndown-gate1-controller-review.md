# Gate 1 controller review — UoM baseline burn-down Wave 1 — APPROVED 2026-07-27

Controller gate on branch `chore/uom-baseline-burndown` @ `63e2f94d8` (19 commits over origin/dev `1201ba37d`). Codex gate report: `.superpowers/sdd/CODEX-uom-baseline-burndown-2026-07-22/gate-1-report.md`. Two independent Opus lanes (frontend-conventions + inventory-costing), run by the controller per the every-milestone rule — Codex's internal Opus pass was not relied upon.

## Verdict: APPROVE — Wave 2 may proceed

Controller mechanical checks: worktree clean; merge-base = origin/dev `1201ba37d` (honest rebase); baseline 42→19 with all 19 remaining entries in Wave 2 scope (no Wave 1 stragglers).

## inventory-costing lane — APPROVE
- Wire contracts additive (DocumentLineData +3 string fields, generated.d.ts mirrors); `quantity_decimals` sourced from `units.decimal_places` with `?? 4` fallback; every newly-consumed unit relation eager-loaded (no N+1 on movements/entry-exit/transfers/PO-index/product-stock; rebalance uses a units leftJoin).
- Rule 19 clean: no float/round/number_format on quantity paths; `ProductController::stockLevels` replaced `Collection::sum()` with bcadd/bcsub at scale 4 (genuine fix, test-pinned `2.2500`); GET→PATCH `0.1250` round-trip pinned; goods-receipt counters pinned (`1.2500`/`0.5000`).
- `63e2f94d8` receipt-progress fix verified: bcadd scale-4 accumulation, display decimals = max over included lines, format only at interpolation, regression pinned; mixed-precision renders sanely.
- RebalanceRow emits `quantity_decimals` (product grain — correct; variants carry no unit_id). phpstan-baseline.neon diff EMPTY (Part-B ratchet untouched). Re-ran 9 changed PHPUnit files by path (56/56) + PHPStan on changed files, clean.
- **MINOR-1 (fold into Wave 2 opening commit):** `StockTransferController.php:334` emits `quantity_decimals` null when unit chain absent — add `?? 4` for wire parity (FE already coerces null→4; no runtime bug).

## frontend-conventions lane — APPROVE
- Scanner edit is EXACTLY the sanctioned exemption: `QuantityCell` added to a value-attribute exempt set alongside `QuantityInput` (`audit-quantity-display.mjs:55,268-274`); null-tag and non-exempt paths preserved; regression tests 14/14 incl. new raw-input/raw-span still-violating cases. QuantityCell readonly path now formats canonically (`LineItemsTable.tsx:188`); editable path threads decimalPlaces → QuantityInput.
- All lib/format→lib/decimal swaps carry `getQuantityDecimals(...)`; conservation holds on every multi-site file (StockMovementsPage ×3, ProductMovementsTab ×3, ProductStockLevels ×8, PO detail progress). `rebalance.ts` de-formats the pure helper; RebalancingView owns the format.
- Editable inputs untouched (`DocumentLineEditor.tsx:571-579`, `CreateStockTransferPage.tsx:756-768` raw values, files not in diff). quantity_decimals threading REAL end-to-end (7+ sites sampled to backend emission). Ratchets all green (23/23 baselined, 0 new/stale; keys audit; drift check exit 0); no baseline/eslint-disable loosening anywhere in the diff.
- **MINOR-1 (pre-existing, NOT this wave):** 3 red tests in touched inventory dir (`StockByLocationPage.test.tsx` crash at `rebalance.ts:11` on undefined `surpluses`; `tenantScope.test.tsx:307,314` key-shape) — reproduced IDENTICALLY on origin/dev in a throwaway worktree: inherited multiloc debt. Optional hardening while the file is warm: `(row.surpluses ?? []).flatMap`. 🎫 track as multiloc follow-up.
- **MINOR-2 (no action):** ReceiveGoodsDialog structural refactor verified justified (consumes the new receipt counters for reopened-partial prefill/cap; covered by test; no false-green from the Modal mock).

## Conditions attached to approval (non-blocking, fold into Wave 2)
1. `StockTransferController.php:334` → `?? 4`.
2. Optional: `(row.surpluses ?? []).flatMap` hardening in `rebalance.ts:11`.
3. The 3 pre-existing red inventory tests are multiloc debt — ticketed, NOT owed by this branch; do not chase them in Wave 2.
