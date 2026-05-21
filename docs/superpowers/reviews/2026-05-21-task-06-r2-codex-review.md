# Task 06 R2 Codex Self-Adversarial Review — Customer Balance Summary

**Commits reviewed:** `dc1b5819b Phase 2.6.1: Add POS customer attach flow` + `5c96192ed Phase 2.6.2: Complete customer balance summary`  
**Prior second-pass review:** `docs/superpowers/reviews/2026-05-21-task-06-opus-review.md`  
**Reviewer:** Codex first-pass adversarial R2 review  
**Verdict:** APPROVE

## Scope Reviewed

- `apps/pos/src/components/customers/CustomerBalanceBadge.tsx`
- `apps/pos/src/components/customers/CustomerBalanceBadge.test.tsx`
- Prior Task 6 attach/search/store implementation as the caller context.

## R2 Finding Disposition

1. **REQUEST-CHANGES 1 — visible net due and `balance_updated_at`: FIXED.**
   - `CustomerBalanceBadge` now renders visible `Net due ...` and `Balance updated ...` rows.
   - `CustomerBalanceBadge.test.tsx` asserts receivable, credit, net due, visible update timestamp, stale/fresh state, and the `Never synced` null-timestamp fallback.
   - Net due is calculated with the repo's `bcsub` / `bccomp` / `bcformat` decimal helpers and the active currency decimal scale, not JS floating-point arithmetic.

2. **P3 — stale rows after non-null scope change: ACCEPTED AS NON-BLOCKING FOR TASK 6.**
   - The R1 implementation already has a store/caller guard: `CustomerAttachPanel` rejects any selected row whose tenant/company differs from active checkout context before mutating `paymentStore`.
   - The repository search path remains tenant/company scoped. The stale visual window is a UX polish risk, not a fiscal/accounting safety defect.

## Attack Vectors Checked

1. **R2-introduces-new-defect pattern:** APPROVE.
   - The R2 fix originally risked JS `Number()` arithmetic for money display; this was corrected before commit to use existing decimal helpers.
   - Negative net due is clamped to formatted zero and covered by a new test where credit exceeds receivables.

2. **Contract drift:** APPROVE.
   - The visible fields now match the Phase 2 spec's required balance summary: receivable, credit, net due, `balance_updated_at`, and stale marker.
   - The component remains display-only; it does not author `ACCOUNT_PAYMENT` or mutate SALE_RECEIPT contracts.

3. **Cross-tenant/company safety:** APPROVE.
   - R2 touched display-only balance rendering and did not weaken Task 6's search scope, attach guard, or pending-customer outbox tenant/company writes.

4. **Fail-loud vs silent downgrade:** APPROVE.
   - Missing scope and corrupt attach snapshots still fail via the R1 code paths.
   - The null timestamp fallback is explicit (`Never synced`) rather than hiding the field.

5. **Dead-path rebuild:** APPROVE.
   - `CustomerBalanceBadge` remains live through `CustomerAttachPanel`, mounted in `HomePage`.

6. **Discriminated-union matrix completeness:** APPROVE for Task 6.
   - No discriminated-union DTO was introduced in R2. The display matrix now covers positive net due, negative-net clamp, fresh, stale, and null timestamp.

7. **D16 bounded-modules guard:** APPROVE.
   - R2 added no Treasury/Accounting/B2B dependency and no backend projector dependency.

8. **CLAUDE.md rule 13 / constructor injection:** APPROVE.
   - R2 is TS-only and adds no PHP service-location calls.

9. **Per-method skip rule / skip-citation accuracy:** APPROVE.
   - No skipped tests were added.

## Verification Evidence

- `pnpm test -- CustomerBalanceBadge CustomerAttachPanel CustomerSearchInput paymentStore.customerAttach` — PASS, 4 files, 13 tests.
- `pnpm typecheck` — PASS.
- `pnpm test` — PASS, 161 files, 1424 tests.
- `pnpm lint` — PASS exit code 0; 42 warnings remain pre-existing and outside the R2 customer files.
- `git diff --check` — PASS.

## Residual Risks

- The timestamp is rendered as the stored ISO string for deterministic POS/operator visibility. A later UX polish pass can localize the timestamp display, but it must keep a visible unambiguous balance-updated value.
