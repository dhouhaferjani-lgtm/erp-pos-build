# Task 05 Codex Self-Adversarial Review — Pending Customer Create And Alias Reconciliation

## Scope Reviewed

- Implementation commit: `251d3b27b Phase 2.5.1: Reconcile pending POS customers`
- Plan anchor: `docs/superpowers/plans/2026-05-21-pos-customer-accounts-phase2.md` Task 5
- Files reviewed:
  - `apps/pos/src/lib/db/migrations.ts`
  - `apps/pos/src/lib/db/repositories/pendingCustomerRepository.ts`
  - `apps/pos/src/lib/customer/pendingCustomerSyncService.ts`
  - `apps/api/app/Modules/POS/Domain/PosCustomerAlias.php`
  - `apps/api/database/migrations/2026_05_21_120000_create_pos_customer_aliases_table.php`
  - `apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php`
  - `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerAliasResource.php`
  - `apps/api/app/Modules/POS/routes.php`
  - focused Task 5 tests

## Verdict

APPROVE.

Task 5 adds a tenant/company-scoped local pending-customer outbox, a local customer alias mirror, a POS-owned server alias table, and a pending customer create endpoint that is idempotent by client UUID. The POS push service validates response scope before writing aliases or marking outbox rows resolved.

## Attack Vectors Reviewed

### Cross-tenant/company safety

PASS. Local tables key aliases and outbox rows by `(tenant_id, company_id, client_customer_uuid)`. Server alias creation uses `CompanyContext::requireTenantId()` and `requireCompanyId()`, creates the Partner and alias in that scope, verifies existing aliases still point to a Partner in the same tenant/company, and rejects same-tenant cross-company client UUID reuse with `POS_CUSTOMER_ALIAS_COMPANY_CONFLICT`.

### Fail-loud vs silent-downgrade

PASS. The POS sync service rejects mismatched `client_customer_uuid`, missing response fields, and tenant/company drift before `storeCustomerAlias()` or `markPendingCustomerResolved()`. A stale local alias conflict throws `StaleCustomerAliasConflictError` and blocks future authoring checks through `assertCustomerAliasMatches()`.

### Dead-path rebuild

PASS for Task 5 scope. The new server route is wired in `apps/api/app/Modules/POS/routes.php`, and the POS push service is covered directly. Scheduler/UI invocation is expected in later tasks; this commit establishes the repository and sync primitive.

### D16 bounded-module guard

PASS. Production code stays in POS plus the existing Partner domain for customer creation/reference data. It introduces no Treasury/B2B/Accounting dependency. The alias lookup helper is POS-owned and can be used by later Treasury bridge code without making the fiscal engine depend on Treasury.

### CLAUDE rule 13 / service locator

PASS. Production code uses constructor injection for `CompanyContext` and does not introduce `app()`, `App::make()`, or `resolve()`. The new backend tests use `Gate::before()` rather than Spatie permission registrar service-location setup.

### Contract drift

PASS. `PosCustomerAliasResource` emits the exact fields consumed by `pendingCustomerSyncService.ts`: `client_customer_uuid`, `server_partner_id`, `tenant_id`, `company_id`, and `resolved_at`. The POS parser requires all of them.

### Test matrix

PASS. Tests cover local outbox+alias happy path, stale alias conflict for authoring, tenant/company scoping, server create happy path, idempotency, cross-company conflict, alias lookup for later Treasury replay, contact-key validation, POS push happy path, POS response scope drift, and POS mismatched client UUID.

## Verification Evidence

- Backend focused test: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/POS/PosPendingCustomerControllerTest.php` — 5 tests, 17 assertions.
- POS focused tests: `pnpm test -- pendingCustomerRepository.test.ts pendingCustomerSyncService.test.ts` — 2 files, 6 tests.
- PHPStan L8 on touched backend files — no errors.
- Pint on touched backend files — pass.
- POS typecheck: `pnpm typecheck` — exit 0.
- POS lint: `pnpm lint` — exit 0 with the existing 42 warnings outside Task 5.
- Full POS suite: `pnpm test` — 157 files, 1411 tests.
- Full backend Fiscal/POS suite: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — 1099 tests, 3685 assertions, 16 PHPUnit deprecations, 107 skipped, 2 incomplete.
- Chokepoint/pass2b/diff/D16+rule13 grep gate — pass; no forbidden production patterns found in touched Task 5 production files.

## Residual Risk

The pending customer push service is not yet wired into the scheduler, and account-payment authoring is not yet consuming `assertCustomerAliasMatches()`. Those are expected later-task integrations, not claimed as complete in Task 5.
