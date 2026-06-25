# M-7 Codex Review — Both Partner Net Balance

Date: 2026-06-22
Scope:
- `Partner::getNetBalanceAttribute()`
- partner index `net_balance` SQL sort expression
- web partner-list net-balance helper
- backend and frontend regression tests

## Findings

No BLOCKER, HIGH, or MEDIUM findings.

## Checks

- Backend model semantics are explicit: customer position is `receivable_balance - credit_balance`; `both` partners subtract `payable_balance`; suppliers remain payable-normal.
- Backend list sorting now uses the same type-aware CASE expression, so `sort_by=net_balance` no longer ranks `both` partners as if payable exposure did not exist.
- Frontend display uses the same type-aware calculation through `partnerNetBalance.ts`, with the helper outside the component file to avoid React fast-refresh export warnings.
- Tests cover the mixed both-type case with receivable, credit, and payable balances, and the API sorting regression proves the SQL expression is aligned with the model/helper semantics.

## Residual Risk

- Existing frontend lint warnings remain in `PartnerListPage.tsx` and `partners.test.tsx` for hardcoded Tailwind classes and unsafe assertions. The M-7 change did not introduce new ESLint errors, and the new helper avoids the fast-refresh warning.
