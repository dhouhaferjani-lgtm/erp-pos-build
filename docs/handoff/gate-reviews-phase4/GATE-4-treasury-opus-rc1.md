# Treasury Phase 4 — GATE 4 treasury Opus lane (rc1)

**Scope:** W2 recurrence engine and W3 analytics/export/frontend reporting over `origin/dev..HEAD`.

## Verified

- TreasuryMovementService, fiscal perimeter, settle/linked-cost capitalization, and tax-detail schema surfaces are untouched; analytics/export routes precede `expenses/{id}`.
- Expense create/update and recurring generation are console-safe; actors and Spatie team IDs are tenant-correct.
- Analytics and export preserve string money/no-float contracts, zero guards, raw decimal cells, and FE bignum handling.
- Recurrence cursor math is origin-anchored/no-overflow, atomic and replay-safe, uses the shared lead-day bound, resumes without backfill, and respects ended lifecycle transitions.
- Forecast projection/materialized/posted partitions are disjoint and deletion does not regenerate.
- Analytics/export/partner joins are tenant/company scoped; shared `ExpenseIndexQuery` prevents filter drift and leak tests are green.
- Permission grants, FE map, nav/route gates, canonical atoms/tokens, tenantScopedKey, invalidation, authenticated blob export, notification interpolation, i18n, and RTL matrix all pass review.

## Findings (LOW/INFO only)

1. Generation loops isolate per-company rather than per-template; validation bounds malformed templates.
2. `upcoming-payments` invalidation is a broad prefix and may over-refetch.
3. Actor lookup does not require Active status and one controller `next_due_date` display uses server timezone rather than company timezone; neither affects the money path.

The lane could not rerun PHP/node commands in its review harness; it corroborated the recorded green runs and prior Gate 1–3 approvals.

**VERDICT: APPROVE**
