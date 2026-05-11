# PR #112 — T1.6 TND currency precision smoke — Codex review trail

**Branch:** `test/pos-tnd-smoke`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)
**Final state:** 1 round, APPROVE.

This PR adds a focused POS regression guard for TND display scale and exact decimal arithmetic. No production code changed; the existing currency/decimal helpers already satisfy the T1.6 contract.

## Round 1 — APPROVE

> The change only adds focused regression tests for TND currency precision, and the new test file passes in isolation. I did not find any actionable bug introduced by this patch.

No findings. PR ready for merge.

## Final shape

- **1 source test file added:** `apps/pos/src/lib/__tests__/currencyPrecision.test.ts`.
- **+4 tests** covering TND scale, add, subtract, and quantity times unit price at 3-decimal scale.
- POS gates:
  - `pnpm test src/lib/__tests__/currencyPrecision.test.ts` — 4/4 pass.
  - `pnpm typecheck` — 0 errors.
  - `pnpm lint` — 0 errors / 41 warnings.
  - `pnpm test` — 1200/1200 pass across 133 files.

## Manual smoke note

The browser/cashier TN-tenant checkout smoke was not run in this local pass because no seeded TN tenant checkout session was available in the worktree. The arithmetic requested by the smoke (`3 * 0.999 TND = 2.997`) is pinned by the new regression guard and remains a Phase 6 manual-smoke item.

## Pre-flight audit

- **L9 ingress audit:** test-only guard; no production ingress sites, runtime data shape invariants, state-machine writes, cache reads, or wire boundaries changed.
- **L1 cross-tenant audit:** no tenant runtime paths touched. Menu, standard-retail, hybrid, and non-Menu tenants are unaffected; the guard only pins TND scale/arithmetic helper behavior.
- **L8 ownership audit:** no screen or state-transition ownership changes.
