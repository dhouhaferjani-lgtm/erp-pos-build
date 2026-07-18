# TICKET — Counting must preserve precise placements inside the counted subtree

> Fast-follow to `feat/location-placement-phase2` (merged dev `49186b528`). Owner decision 2026-07-13: **preserve precise placements**. Priority: land BEFORE anyone counts on staging with coarse scopes.

## Problem

Assign-as-you-count (`InventoryCountingService.php:772-798`, pre-existing) re-homes every counted product's placement to the **selected scope node**. With subtree seeding (Phase 2), counting a coarse ancestor (Aisle `A1`) now seeds all products in bins under it — and finalize silently coarsens each product's placement (`A1/R2/B7` → `A1`). Stock math is unaffected (placements are labels), but precise bin placements are destroyed by an aisle-level count. Demonstrated by the shipped E2E itself: product moved to `binTo`/B2 mid-test ends at `aisle`/A1 after finalize (`ZoneScopedCountingTest.php:374-377` vs `:438-443`).

## Fix (owner-ruled semantics)

On finalize, re-home a counted product ONLY when its current live placement is **outside the counted subtree** (or it has no placement). Products already placed anywhere **within** the counted node's subtree keep their precise placement untouched.

- Subtree membership check must reuse the canonical collision-safe form (`path = :p OR path LIKE :p || '/%' ESCAPE '\'` — or `LocationNode::scopeSubtreeOf`), NOT a bare prefix LIKE (`A1` vs `A10`).
- No stock/quantity/movement logic may change — placement label writes only.

## Tests (TDD — red first)

1. Product placed at `A1/R2/B7`, counted under scope `A1` → placement REMAINS `A1/R2/B7` after finalize (fails on current behavior).
2. Product placed at `B9/...` (outside subtree), counted under `A1` → re-homed to `A1` (current behavior preserved for out-of-scope).
3. Unplaced product counted under `A1` → placed at `A1` (unchanged).
4. `A1` vs `A10` collision: product at `A10/R1`, counted under `A1` → treated as OUTSIDE → re-homed to `A1`.
5. Regression: existing `ZoneScopedCountingTest` E2E updated to assert the new preserve semantics; stock rows still byte-unchanged.

## Review

Opus gate (inventory-costing-reviewer lane at merge). No migrations expected. Standard preflight; tests by path only.
