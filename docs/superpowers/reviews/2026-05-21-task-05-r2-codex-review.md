# Task 05 R2 Codex Self-Adversarial Review — Pending Customer Alias Hardening

## Scope Reviewed

- R2 implementation commit: `3a27355a9 Phase 2.5.2: Harden pending customer aliases`
- Original implementation commit: `251d3b27b Phase 2.5.1: Reconcile pending POS customers`
- Opus-equivalent R1 review: `docs/superpowers/reviews/2026-05-21-task-05-opus-review.md`

## Verdict

APPROVE.

The R2 fix moves the cross-company client UUID invariant into the database schema, handles alias unique races with a scoped replay response, and hardens the durable alias lookup helper against stale or cross-company Partner targets.

## Finding Resolution

### R1 P1 — Concurrent cross-company submissions can create duplicate aliases

RESOLVED. `pos_customer_aliases` now has a tenant-wide unique constraint on `(tenant_id, client_customer_uuid)`, so two companies in the same tenant cannot persist the same device-created customer UUID. A regression test proves the database rejects the second row.

### R1 P1 — Concurrent same-company duplicate requests can return a DB error

RESOLVED. The controller catches alias-table unique violations from the transaction, reloads the tenant/client alias, and returns the same-company alias as the idempotent response. If the conflict belongs to another company, it returns the existing `POS_CUSTOMER_ALIAS_COMPANY_CONFLICT` response. The transaction rolls back the loser-created Partner before the catch path runs.

### R1 P2 — `resolveServerPartnerId()` can return stale/cross-company targets

RESOLVED. The helper now checks that the referenced Partner still exists in the same tenant/company and is customer-capable (`customer` or `both`) before returning `server_partner_id`. Regression tests cover cross-company, stale, and supplier-only targets.

## R2 Regression Attack Vectors

- Cross-tenant/company safety: PASS. Alias table uniqueness is tenant/client-wide, while local alias/outbox rows remain tenant/company-scoped for POS cache lookups.
- Fail-loud posture: PASS. Unresolvable unique conflicts return typed 409 responses; unexpected `QueryException`s are rethrown.
- D16 bounded-module guard: PASS. The fix remains in POS + Partner boundaries and introduces no Treasury/B2B/Accounting dependency.
- CLAUDE rule 13: PASS. Production code still uses constructor injection and has no `app()`, `App::make()`, or `resolve()` calls.
- R2-new-defect risk: PASS. The helper type issue surfaced by PHPStan was fixed by replacing relationship-closure inference with an explicit scoped Partner existence query.

## Verification Evidence

- Backend focused test: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/POS/PosPendingCustomerControllerTest.php` — 7 tests, 21 assertions.
- PHPStan L8 on touched backend files — no errors.
- Pint on touched backend files — pass.
- POS typecheck: `pnpm typecheck` — exit 0.
- POS lint: `pnpm lint` — exit 0 with existing 42 warnings outside Task 5.
- Full POS suite: `pnpm test` — 157 files, 1411 tests.
- Full backend Fiscal/POS suite: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — 1101 tests, 3689 assertions, 16 PHPUnit deprecations, 107 skipped, 2 incomplete.
- Chokepoint/pass2b/diff/D16+rule13 grep gate — pass; no forbidden production patterns found in touched Task 5 production files.

## Residual Risk

True simultaneous-request behavior is enforced at the database invariant and catch-path level rather than by a multi-process concurrency test. The route-level sequential idempotency and the database tenant/client uniqueness invariant are covered; the catch path is straightforward and limited to recognized alias-table unique violations.
