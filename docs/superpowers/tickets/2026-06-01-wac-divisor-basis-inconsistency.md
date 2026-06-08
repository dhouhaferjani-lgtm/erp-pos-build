# Ticket — WAC divisor-basis inconsistency (per-location vs company-wide)

**Opened:** 2026-06-01 (flagged during the WAC Serialization Foundation; deliberately out of scope there)
**Module:** Inventory / costing
**Severity:** Medium — costing-semantics correctness (not a crash; produces a different-but-plausible average)
**Status:** Open

## Problem
The three WAC writers do not agree on the denominator they recompute the moving average against:

- `WeightedAverageCostService::recordPurchase` blends against the **single receiving-location** stock level:
  - `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:140` — `$currentQty = CurrencyScale::bcformat($stockLevel->quantity, 4)` (one `stock_level` row)
  - `:142–153` — `currentValue = currentQty × currentCostPrice`; `newQty = currentQty + qty`; `newAvgCost = newValue / newQty`.
- `WeightedAverageCostService::recordReturn` — same single-location basis (mirrors recordPurchase, ~`:411`).
- `WeightedAverageCostService::recordCostAdjustment` blends against the **company-wide** sum `(Σ stock_level.quantity + in_transit)` (`:567–590`).

Because cost is **company-wide per product** (one company-level accounting cost — see `project_inventory_costing` memory), recomputing a purchase's blended average using only the *receiving location's* quantity yields a location-weighted average that differs from the company-wide blend a cost adjustment would produce for the same product. Over a sequence of purchases at different locations the running `cost_price` drifts from the true company-wide weighted average.

## Why it matters
- COGS and margin are derived from `product.cost_price`; a location-weighted blend mis-states both.
- The inconsistency is *internal*: an inter-branch transfer cost (company-wide) and a purchase receipt (single-location) move the same `cost_price` field on different bases, so the value depends on the path, not just the facts.

## Proposed fix (needs an owner decision first)
1. **Decide the canonical basis.** The costing model is company-wide, so the intended basis is almost certainly **company-wide on-hand** (matching `recordCostAdjustment`). Confirm with the accountant / owner.
2. If company-wide: change `recordPurchase`/`recordReturn` to recompute `currentQty`/`currentValue` against the **company-wide** quantity sum for the product (row-locked, same pattern as `recordCostAdjustment`'s per-row `FOR UPDATE` sum), not the single `$stockLevel->quantity`. The seam lock added by the foundation already serializes these writers, so the company-wide read is safe.
3. Add a test mirroring `test_cost_adjustment_capitalizes_against_company_wide_on_hand` but for `recordPurchase` (e.g. 60@A + 40@B, purchase 100 @ cost X into A → assert blended against 100+100, not 60+100).

## Scope / risk
- Touches the hot purchase/return cost path; changes recorded `cost_price` values → regenerate/adjust any fixtures asserting the old single-location average. Coordinate with the precision contract (carry at `costScale=6`, no currency truncation).
- Independent of the concurrency seam (already shipped) — this is purely the basis of the divisor.

## References
- WAC foundation plan: `docs/superpowers/plans/2026-05-29-wac-serialization-foundation.md` ("Out of scope" §).
- Memory: `project_inventory_costing` (company-wide cost), `project_precision_drift_remediation` (6-dp carry / bcround boundary).
