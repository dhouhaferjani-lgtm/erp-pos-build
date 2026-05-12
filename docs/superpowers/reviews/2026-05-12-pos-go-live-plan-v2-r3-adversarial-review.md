# POS Go-Live Plan v2 — Round 3 Adversarial Review

Scope: `docs/superpowers/plans/2026-05-12-pos-go-live-plan-v2.md`, checked against round-2 review `docs/superpowers/reviews/2026-05-12-pos-go-live-plan-v2-adversarial-review.md` on 2026-05-12.

## Round-2 Closure Check

All round-2 P1, P2, and NIT findings are addressed in the amended v2 body, not only in the header note.

- r2 [P1] Path A test values: closed. PR #1 now uses `expected_amount: '100.000'` / `actual_amount: '100.000'` for the sync archive test, matching the same `170 - 70 = 100` cash formula used by Path B.
- r2 [P2] Cash discriminator: closed. PR #1 now requires `pos_receipt_payments.payment_method_code = 'CASH'` as the primary discriminator, with live `payment_methods.code = 'CASH'` only as fallback for missing snapshots, and explicitly forbids `is_physical = true`.
- r2 [NIT] Receipt empty guards: closed. PR #2 now specifies `trim().is_empty()` for Rust legal-field guards, including `Option<String>` values.
- r2 [NIT] Terminal-code source: closed. PR #3 names `useTerminalStore.getState().terminal?.code` at the call site and defines the fail-closed behavior when terminal context is absent.
- r2 [P2] Chain-break SQL: closed. PR #4 removes operator-side hash-chain mutation SQL and makes hash-chain mismatch backup/capture/escalate only, with Synerivia-only reconciliation.
- r2 [NIT] Restore row-count SQL: closed. PR #4 replaces the invalid dynamic SQLite query with a PowerShell loop that enumerates tables and runs one `SELECT COUNT(*) FROM <name>` per table.

## Remaining Findings

None.

The amended v2 plan is stable enough to hand to Codex for implementation. The remaining risk is implementation discipline, not plan ambiguity: PR #1 must keep Path A archival and Path B recompute semantics separate, and PR #4 must keep hash-chain repair out of the operator runbook.

APPROVE
