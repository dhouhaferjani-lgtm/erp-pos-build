# Task 06 R2 Opus-Equivalent Second-Pass Review — Customer Balance Summary

**Commits reviewed:** `dc1b5819b Phase 2.6.1: Add POS customer attach flow` + `5c96192ed Phase 2.6.2: Complete customer balance summary`  
**Prior reviews read:**  
- `docs/superpowers/reviews/2026-05-21-task-06-codex-review.md`
- `docs/superpowers/reviews/2026-05-21-task-06-opus-review.md`
- `docs/superpowers/reviews/2026-05-21-task-06-r2-codex-review.md`

**Reviewer:** Codex acting as Opus-equivalent second-pass adversarial reviewer  
**Verdict:** APPROVE

## Primary Question

R2 fully fixes the prior Opus REQUEST-CHANGES finding. `CustomerBalanceBadge` now visibly renders:

- receivable balance;
- credit balance;
- net due;
- visible `balance_updated_at` with `Never synced` fallback;
- fresh/stale marker.

The R2 fix does not introduce a new blocking defect that I found.

## Findings

None.

## Evidence Checked

### Prior REQUEST-CHANGES Disposition

- `apps/pos/src/components/customers/CustomerBalanceBadge.tsx:18-21` computes net due with `bcsub`, `bccomp`, and `bcformat`, using the active currency decimal scale from `useCurrency()`.
- `apps/pos/src/components/customers/CustomerBalanceBadge.tsx:32-34` visibly renders `Net due`.
- `apps/pos/src/components/customers/CustomerBalanceBadge.tsx:47-49` visibly renders `Balance updated ...`, including the null fallback from line 21.
- `apps/pos/src/components/customers/CustomerBalanceBadge.test.tsx:22-27` covers visible due, credit, net due, timestamp text, and fresh state.
- `apps/pos/src/components/customers/CustomerBalanceBadge.test.tsx:40-41` covers stale state and the stale aria label.
- `apps/pos/src/components/customers/CustomerBalanceBadge.test.tsx:54-55` covers negative-net clamp and `Never synced`.

### Adversarial Axes

- **Cross-tenant/company safety:** No R2 regression. Search remains scoped by tenant/company in `apps/pos/src/components/customers/CustomerSearchInput.tsx:39-45`; attach still rejects mismatched tenant/company rows in `apps/pos/src/components/customers/CustomerAttachPanel.tsx:51-60`; pending create writes explicit tenant/company in `apps/pos/src/components/customers/CustomerAttachPanel.tsx:93-116`.
- **Fail-loud vs silent downgrade:** No R2 regression. Missing scope remains a visible error in `CustomerAttachPanel.tsx:69-72`; store-level corrupt customer snapshots still throw through `apps/pos/src/stores/paymentStore.ts:983-985` and its assertion helper.
- **Dead-path/live-caller wiring:** Still live. `CustomerAttachPanel` is mounted in `apps/pos/src/pages/HomePage.tsx:1066-1071`, and it renders `CustomerBalanceBadge` at `apps/pos/src/components/customers/CustomerAttachPanel.tsx:173-178`.
- **Task 6 test matrix:** Now covers visible net due, visible timestamp/null fallback, stale/fresh, and negative-net clamp in `CustomerBalanceBadge.test.tsx:12-56`; attach/search/store paths remain covered by the Task 6 component/store tests.
- **Decimal/money arithmetic:** Net due arithmetic is not JS floating point; it uses decimal helpers in `CustomerBalanceBadge.tsx:18-20`. TND scale is represented by the active currency decimals.
- **Contract drift:** The visible balance summary now matches the Phase 2 spec requirement at `docs/superpowers/specs/2026-05-21-pos-customer-accounts-phase2-spec-v1.md:107-110`.
- **D16 bounded-modules guard:** The reviewed commit range adds only POS TS/TSX/tests and no Treasury/Accounting/B2B hard dependency.
- **CLAUDE.md rule 13:** No `app()`, `App::make`, or `resolve()` additions in the reviewed diff.
- **Per-method skip rule / skip citations:** No skipped tests were added in the reviewed diff.
- **R2-fix-defect pattern:** The fix is localized to display and tests. It avoids the common R2 money-arithmetic trap by using existing decimal helpers, and the negative-net clamp is tested.

## Verification Run

- `pnpm test -- CustomerBalanceBadge` — PASS, 1 file, 3 tests.
- `pnpm test -- CustomerBalanceBadge CustomerAttachPanel CustomerSearchInput paymentStore.customerAttach` — PASS, 4 files, 13 tests.
- `pnpm typecheck` from `apps/pos` — PASS.
- `git diff --check dc1b5819b^..5c96192ed` — PASS.
