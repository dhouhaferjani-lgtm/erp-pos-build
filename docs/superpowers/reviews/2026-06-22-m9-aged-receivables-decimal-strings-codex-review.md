# M-9 Codex Review — Aged Receivables Decimal Strings

Date: 2026-06-22
Scope:
- `AgedReceivablesService::generateCustomerStatement()`
- `AgedReceivablesScalingTest`

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Checks

- The final customer-statement running-balance recalculation no longer casts debit or credit money strings through `float`.
- The recalculation stays on `numeric-string` values and bcmath using the injected currency scale.
- Existing aged-receivables bucket coverage still proves TND scale-3 accumulation for the report path.
- New statement coverage asserts TND scale-3 balances are preserved in customer statements.
- The source guard catches reintroduction of `(float)` casts in this service, directly covering the audited failure mode.

## Residual Risk

- The source guard is intentionally narrow and does not replace broader precision architecture checks across other services.
