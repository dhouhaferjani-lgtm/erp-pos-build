# Opus-Style Second-Pass Adversarial Review — Phase 4 Spec v2/v3

**Date:** 2026-05-22
**Reviewed spec:** `docs/superpowers/specs/2026-05-22-pos-phase4-account-status-overrides-approval-spec-v2.md`
**Revised spec:** `docs/superpowers/specs/2026-05-22-pos-phase4-account-status-overrides-approval-spec-v3.md`
**Reviewer:** independent Codex subagent, Opus-style adversarial pass. Actual Opus model was not available in this tool context.
**Verdict after v2:** BLOCKED
**Verdict after v3:** APPROVE FOR PLAN WRITING, with the v3 gates treated as non-negotiable.

## Findings Against v2

### F1 — BLOCKER — `ACCOUNT_STATUS_CHANGED` created a server-authoring carve-out without Phase 1 mechanics

v2 introduced a virtual admin terminal that server-authors `ACCOUNT_STATUS_CHANGED`, but the source-of-truth default is device authoring with server verification only. Phase 1 allows bounded server authoring only for explicitly classified company-integrity events and requires server-only classification, device append rejection, payload validation before persist, and row-level chain locking.

**Resolution in v3:** §4.1.1 now classifies `ACCOUNT_STATUS_CHANGED` as a server-only administrative carve-out and requires PHP/TS registry classification, device append rejection, pre-persist validation, row-level locking, idempotency, and one transaction that ties the `Partner` mutation to fiscal-event append.

### F2 — P1 — PIN approval scoping allowed cross-company supervisor approval inside a tenant

v2 required tenant-scoped lookup before `Hash::check`, but not company/terminal/scope eligibility. Current code has the same risk shape: online PIN verification resolves a bare user id, and offline PIN sync lacks tenant/company columns.

**Resolution in v3:** §5.3 requires tenant/company/terminal/scope eligibility before `Hash::check`, records `scope_mismatch`, and requires equivalent scoping fields and rejection behavior in the offline `operator_pins` mirror.

### F3 — P1 — Override lifecycle was not fail-loud across all override types

v2 named failure states in scope but omitted them from `operator_approvals.decision`, and the fail-loud transaction rule was explicit only for over-credit-limit charges.

**Resolution in v3:** §5.1 closes the decision union, §5.1 closes `approval_scope`, and §6.1.1 requires the approval -> override -> constrained-action lifecycle, local atomicity where device-authored, orphan handling, and fail-loud tests for every Phase 4 scope.

### F4 — P1 — Cash drawer wording still drifted into undecided movement-event territory

v2 deferred fiscal movement ownership but still named safe-drop/correction controls. Current code only has legacy mutable `DEPOSIT`/`PAYOUT` offline paths and server operation types.

**Resolution in v3:** §3 and §7 limit Phase 4 cash drawer work to legacy `DEPOSIT`/`PAYOUT` controls and explicitly forbid mutable-only `SAFE_DROP`, `CASH_CORRECTION`, or generic correction semantics before the Z-report/session-chain decision.

### F5 — P1 — Missing high-risk regression gates

v2 lacked explicit gates for device-side server-only rejection, server-authoring concurrency, cash-drawer movement drift, and the account-charge chokepoint script.

**Resolution in v3:** §10 names each gate and makes the existing `apps/api/scripts/check-accountCharge-chokepoints.sh` part of Phase 4 closure.

## D-Q Assessment

- **Phase 4 D-Q1, approval identity model:** surfaced and locked as per-supervisor PIN. This matches the handover recommendation unless the owner explicitly overrides it.
- **Z-report D-Q1, cash drawer event ownership:** properly deferred to the Z-report spec. Phase 4 v3 no longer decides movement ownership by implication.
