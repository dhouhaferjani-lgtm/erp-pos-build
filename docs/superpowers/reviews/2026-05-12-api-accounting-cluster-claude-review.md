# api.accounting Cluster — Claude Review

Cluster: `api.accounting`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Journal entries, ledger reads, expense + expense-category requests, partner-balance service. Pre-sweep, the GetLedgerRequest validator and CreateJournalEntryRequest expense-row validators referenced GL accounts without consistent tenant predicates.

Inventory callsite total: **13 / 13 fixed**.

## Implementation summary

Top fix commits: `a1963bbd` (7 callsites), `c6f8a4b1` (6).

Top files: `GetLedgerRequest`, `CreateJournalEntryRequest`, `ExpenseRequest`, `ExpenseCategoryRequest`, `PartnerBalanceService`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Accounting/AccountingTenantIsolationTest.php`.

## Per-round review trail

- `2026-05-04-api-accounting-cluster-codex-review.md`
- `2026-05-04-api-accounting-reassigned-cluster-codex-round2-review.md`

## Gates evaluated

1. **Journal entry validators**: GL account + expense-category references scoped with `ScopedExists::tenantAndCompany`.
2. **Ledger reads**: partner-balance and aged-receivables paths scoped at service layer.

## Non-blocking follow-ups

None.

## Disposition

APPROVE for master PR.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/Accounting/AccountingTenantIsolationTest.php`
