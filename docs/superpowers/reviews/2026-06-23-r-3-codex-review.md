# R-3 Codex Review — Tenant Context for Per-Tenant Commands

Date: 2026-06-24
Reviewer: Codex (adversarial self-review)
Scope:
- `apps/api/app/Console/TenantScopedCommand.php`
- `apps/api/tests/Feature/Accounting/SubledgerReconciliationCommandTest.php`

## Verdict

APPROVE.

No blocking issues found. `TenantScopedCommand::forEachTenant()` now initializes Stancl tenancy for each tenant only when `tenancy_resolver.db_per_tenant` is enabled, runs the callback, and ends tenancy in a per-iteration `finally`. Shared-DB mode remains unchanged.

## Review Notes

- Current `forEachTenant()` consumers were audited: accounting subledger reconciliation, workshop expiring certifications, scheduling appointment reminders, and POS held-order expiry all operate on tenant-owned tables and do not self-initialize tenancy.
- The `companies` tenant migration includes `tenant_id`; the other iterating-command tables also keep tenant predicates, so existing filters remain valid under tenant DBs.
- Existing exception semantics are preserved: callback exceptions still abort the command, but `finally` prevents tenant context leakage.
- The new tests cover both modes with real tenancy state: `tenant('id')` is bound inside callbacks in db-per-tenant mode and remains null in shared-DB mode; tenancy is ended after iteration.
- Invoice media attachment wiring was not touched.

## Verification

- RED before implementation: db-per-tenant mode test saw `tenant('id') === null`.
- GREEN `SubledgerReconciliationCommandTest.php`: 4 tests, 17 assertions.
- GREEN related shared-helper blast-radius tests: 16 tests, 52 assertions.
- PHPStan level 8 passed on touched files.
- Pint scoped check passed.
