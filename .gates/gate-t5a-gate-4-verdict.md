# Gate t5a-gate-4 — final verdict

GATE VERDICT: APPROVE

- Treasury lane: R1 REJECT findings fixed in `e99d78ffd`; Opus R2 APPROVE in `gate-t5a-gate-4-verdict-treasury-r2.md`.
- Frontend lane: R1 and R2 REJECT findings fixed in `e99d78ffd` and `3b45df13e`; mandatory two-REJECT escalation to Fable APPROVE in `gate-t5a-gate-4-verdict-frontend-conventions-fable.md`.
- Fresh correction evidence: Expense instrument path 9 tests / 71 assertions on SQLite and PostgreSQL; focused frontend 16/16; PHPStan L8 and Pint pass; typecheck pass; full web lint exits 0 with zero errors and zero new/stale query-key or design-system audit entries.

Non-blocking exit notes:

- Document the after-commit Expense listener reconciliation/recovery path in the ⑤a deploy checklist.
- `Expired` is currently unreachable for outbound paper; any future expiry transition must reverse the issue liability before replacement is allowed, or the settlement allowlist must be narrowed to `Cancelled`.
- Bare instrument invalidation prefixes over-invalidate tenant-suffixed caches but do not leak data.

VERDICT: spec ✅ + quality APPROVED
