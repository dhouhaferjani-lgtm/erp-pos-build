# Codex Self-Adversarial Review — Phase 4 Spec v1/v2

**Date:** 2026-05-22
**Reviewed spec:** `docs/superpowers/specs/2026-05-22-pos-phase4-account-status-overrides-approval-spec-v1.md`
**Revised spec:** `docs/superpowers/specs/2026-05-22-pos-phase4-account-status-overrides-approval-spec-v2.md`
**Superseded spec:** `docs/superpowers/specs/2026-05-22-pos-phase4-account-status-overrides-approval-spec-v3.md`
**Verdict after v2:** APPROVE-WITH-MINOR-EDITS from self-review only; the independent second-pass review blocked v2 and v3 resolves those findings.

## Scope

Review axes from the handover and Phase 1 standing patterns:

- device-authority / server-authoring carve-out safety;
- D16 bounded-module seam;
- cross-tenant FK and PIN lookup safety;
- fail-loud override lifecycle;
- discriminated-union test matrix completeness;
- cash drawer ownership conflict with the upcoming Z-report D-Q1;
- placeholder / ambiguity scan.

## Findings Against v1

### F1 — BLOCKER — `ACCOUNT_STATUS_CHANGED` was described but not implemented

v1 §3 and §4 described account-status transitions as fiscal facts, but §5.2 omitted `ACCOUNT_STATUS_CHANGED` from the event types to implement. That would let the plan add account-status columns and UI while never sealing the status transition as fiscal evidence.

**Resolution in v2:** §5.2 now includes `ACCOUNT_STATUS_CHANGED` and requires PHP/TS registries, DB CHECK, parser, DTO, and drift tests in the same task.

### F2 — BLOCKER — Web-admin status changes lacked an authoring model

Account-status lifecycle changes are normally web-admin operations, not Tauri checkout operations. v1 said the change creates a fiscal event but did not define who authors it under SoT D1. That is the same defect class Phase 1 reviews repeatedly caught around missed server-authoring paths.

**Resolution in v2:** §4.1.1 adds a narrow virtual admin terminal for administrative `ACCOUNT_STATUS_CHANGED` events only, with a grep gate proving it cannot author `SALE_RECEIPT`, `ACCOUNT_PAYMENT`, or `ACCOUNT_CHARGE`.

### F3 — P1 — Cash drawer event ownership was still ambiguous

The handover has a deliberate Phase 4/Z-report tension. v1 deferred the question but still used wording that could let the plan implement movement fiscal events in Phase 4. That would preempt the Z-report D-Q1 review.

**Resolution in v2:** §7 states Phase 4 must not mark `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, or `CASH_CORRECTION` implemented. Phase 4 only ships controls and transitional audit evidence.

## Clean Checks

- D-Q1 supervisor PIN identity model is surfaced and locked as per-supervisor PIN.
- `PinVerifier` bare-user-id lookup risk is named; v2 requires tenant-scoped lookup before `Hash::check`.
- D16 guard is required for new Phase 4 directories.
- The test matrix covers denied, expired, rate-limited, unsigned, and cross-tenant approval states in round 1.
- The spec has no `TBD` or unowned placeholders after v2.

## Minor Edits Before Plan

- In the implementation plan, split virtual-admin-terminal work into its own early task so later account-status tasks can rely on it.
- Keep cash drawer implementation limited to approval policy columns/evidence until the Z-report spec locks D-Q1.

## Follow-Up After Independent Review

The independent second-pass review found that the virtual admin terminal needed Phase-1-style server-authoring mechanics, PIN approval needed company/terminal/scope eligibility before hash checks, and cash drawer work needed a tighter legacy `DEPOSIT`/`PAYOUT` boundary. Those changes are incorporated in v3 and tracked in the Opus-style review file.
