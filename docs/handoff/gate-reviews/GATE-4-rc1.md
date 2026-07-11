# GATE 4 (Treasury Phase 2) — Adversarial Review, rc1

Scope: `git diff phase2-gate-3..HEAD` (Tasks 19–28).  
Reviewer: `claude-opus-4-8`; primary review plus independent Treasury-backend and frontend-conventions passes.  
Candidate: `phase2-gate-4-rc1` (`fd248300d1bd5d35ce220de23994c25723d7ef07`).

## Money-path attestation

This gate adds no new GL postings and no new movements. Tasks 19–22 are read-only reporting/reconcile, Tasks 23–25 are frontend, and Tasks 26–28 are CI/docs; the only write endpoint (`cancel`) delegates to the already-reviewed lifecycle service. No float on money, no no-argument `getScale()` in console paths, reconcile check #4 never freezes (it returns failure and writes an audit event only), the forecast double-count guard holds, and bucket/alert/migration logic is test-pinned.

The findings are in reporting coherence, authorization tiering, and i18n—not posting shapes. No BLOCKER/HIGH money-path finding was raised, so the brief §3 Fable escalation rule is not triggered.

## Findings

1. **MED — at-sight rows disappear under date filters.** `MaturingInstrumentsController` applies `from`/`to` predicates that SQL-exclude null-maturity rows. Treasury Overview always supplies `from=today`, so POS/at-sight paper is hidden, `total_in` is understated, and the controller's at-sight branch is dead. This violates spec §10 / plan Task 19 and lacks a regression.
2. **MED — invalid select-option i18n keys.** `RemittanceCreatePage` uses `common:selectOption` and `InstrumentDetailPage` uses `common:fields.selectOption`; neither exists under the configured common namespace. The declared key is `common:common.selectOption`. The remittance test currently asserts a raw key rather than translated output.
3. **MED — cancellation uses the detail-edit authorization tier.** The cancellation route is gated by `can:instruments.update`, although cancellation creates a contre-passation reversal through the lifecycle service. A details-only role can therefore reverse a receipt. There is no `instruments.cancel` permission and the tier choice is not recorded.
4. **MED — cutover watermark can create permanent false portfolio drift.** Reconcile applies the watermark independently to instrument and JE `created_at`, while circuit removal covers only unlinked paper. A linked instrument created pre-cutover but remitted post-cutover can leave a post-cutover lifecycle line without the excluded receipt-side leg, producing permanent false `portfolio_drift` on brownfield tenants.
5. **LOW — remittance/bordereau tables use DataTable's legacy passthrough instead of the columns API.**
6. **LOW — maturity alerts use application `today()` rather than the company timezone, allowing a boundary-day mismatch with the controller.**
7. **LOW — échéancier/forecast totals sum foreign-currency instruments without FX conversion.**

RC2 must fix Findings 1–4 test-first and address or explicitly record the disposition of Findings 5–7.

VERDICT: CHANGES-REQUIRED
