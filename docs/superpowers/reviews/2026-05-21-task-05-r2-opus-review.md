# Task 05 R2 Opus Second-Pass Review — Pending Customer Alias Hardening

## Verdict

APPROVE.

R2 closes the R1 concurrency/idempotency holes at the database boundary, adds a scoped replay response for alias unique races, and hardens the durable alias resolver so stale, cross-company, and non-customer Partner targets fail closed.

## Scope Reviewed

- R2 implementation commit: `3a27355a9 Phase 2.5.2: Harden pending customer aliases`
- Prior Opus review: `docs/superpowers/reviews/2026-05-21-task-05-opus-review.md`
- R2 Codex self-review: `docs/superpowers/reviews/2026-05-21-task-05-r2-codex-review.md`
- Touched R2 files:
  - `apps/api/app/Modules/POS/Domain/PosCustomerAlias.php`
  - `apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php`
  - `apps/api/database/migrations/2026_05_21_120000_create_pos_customer_aliases_table.php`
  - `apps/api/tests/Feature/POS/PosPendingCustomerControllerTest.php`

## Findings

No blocking or requested-change findings.

## Prior Finding Resolution

### R1 P1 — Concurrent cross-company submissions can create duplicate aliases

RESOLVED. The alias table now enforces tenant-wide uniqueness on `(tenant_id, client_customer_uuid)` at `apps/api/database/migrations/2026_05_21_120000_create_pos_customer_aliases_table.php:22-23`. This is the right invariant for the Task 5 contract: same device-created customer UUIDs can no longer be persisted in two companies under the same tenant, regardless of request interleaving.

The focused regression test at `apps/api/tests/Feature/POS/PosPendingCustomerControllerTest.php:145-177` proves the database rejects a second row for the same tenant/client UUID across companies.

### R1 P1 — Concurrent same-company duplicate requests can return a DB error

RESOLVED. `PosPendingCustomerController::store()` now wraps Partner + alias creation in a transaction and catches only alias-table integrity collisions before reloading the winner alias (`apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:74-103`). Because the Partner create is inside the transaction, a losing duplicate request rolls back the loser-created Partner before the catch path returns.

The replay path distinguishes same-company idempotency from cross-company conflict at `apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:110-151`: same-company aliases return the existing resource, cross-company aliases return `POS_CUSTOMER_ALIAS_COMPANY_CONFLICT`, stale aliases return `POS_CUSTOMER_ALIAS_STALE`, and unresolved races return a typed 409 instead of leaking a raw DB exception.

### R1 P2 — `resolveServerPartnerId()` can return stale/cross-company targets

RESOLVED. `PosCustomerAlias::resolveServerPartnerId()` still scopes the alias row by tenant/company/client UUID, then verifies the target Partner exists in the same tenant/company and has a customer-capable type before returning the ID (`apps/api/app/Modules/POS/Domain/PosCustomerAlias.php:67-89`). The controller's existing-alias response path uses the same customer-capable Partner filter through `findScopedPartner()` (`apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:172-180`).

Regression coverage at `apps/api/tests/Feature/POS/PosPendingCustomerControllerTest.php:203-256` covers cross-company, stale/missing, and supplier-only targets.

## Regression Review Notes

- Tenant-wide unique: PASS. `(tenant_id, client_customer_uuid)` fully closes cross-company duplicate alias persistence while still allowing the same client UUID in different tenants.
- Unique-violation handling: PASS. Unexpected `QueryException`s are rethrown unless the SQL state matches an integrity/unique family and the exception text identifies `pos_customer_aliases` (`apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:185-193`). This could be tightened later to constraint-name checks, but I do not see a current leak or incorrect success path in this endpoint.
- Cross-tenant FK safety: PASS for this surface. The database FK to `partners(id)` remains single-column on PostgreSQL, but creation uses same-scope values and both response/resolver paths fail closed on cross-tenant/company or non-customer targets.
- Schema portability: PASS. R2 changes a portable Laravel index to a portable Laravel unique constraint; it does not add new driver-specific DDL beyond the migration's pre-existing PostgreSQL FK block.
- Fail-loud posture: PASS. Stale/unresolved conflicts produce typed 409s, while non-alias DB exceptions still bubble.
- D16 bounded-module guard: PASS. The touched production files stay within POS plus the existing Partner dependency; no Treasury, Accounting, B2B, or Fiscal production dependency was introduced.
- CLAUDE rule 13: PASS. No `app()`, `App::make()`, or service-locator `resolve()` usage was introduced in the touched production files.

## Verification

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/POS/PosPendingCustomerControllerTest.php` — 7 tests, 21 assertions, pass.
- `./vendor/bin/phpstan analyse app/Modules/POS/Domain/PosCustomerAlias.php app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php --memory-limit=1G` — no errors.
- `git diff --check 3a27355a9^ 3a27355a9` — pass.
- Fixed-string grep over touched production files found no service locator calls and no new Treasury/Accounting/Fiscal module references.
