# H-6 Partner Money Precision — Codex Review

Date: 2026-06-22
Reviewer: Codex
Scope: Partner balance and credit-limit scale alignment to the currency money contract.

## Verdict

No BLOCKER/HIGH findings.

## Findings

- None.

## Checks Performed

- Confirmed `partners.credit_limit`, `receivable_balance`, `credit_balance`, and `payable_balance` all have a tenant migration to `NUMERIC(15, 3)` on PostgreSQL.
- Confirmed Eloquent casts now return 3-decimal strings for those four fields.
- Confirmed create/update partner credit-limit validation rejects 4 decimals and accepts 3 decimals.
- Confirmed cached balance calculations and liability magnitude clamp return scale-3 strings, with precision-rule comments on intentional hardcoded scale calls.
- Confirmed adjacent API/test expectations were updated for partner-money response shape while unrelated reconciliation/running-balance scale-4 assertions were left unchanged.

## Residual Notes

- Existing rows with a fourth decimal will be rounded by PostgreSQL during `ALTER COLUMN ... TYPE NUMERIC(15, 3)`. That is the intended consequence of choosing scale 3 instead of documenting a 4-decimal cache exception.
- True Opus review was not available in this runtime; fallback review is recorded separately and the progress log marks `opus-review: PENDING`.
