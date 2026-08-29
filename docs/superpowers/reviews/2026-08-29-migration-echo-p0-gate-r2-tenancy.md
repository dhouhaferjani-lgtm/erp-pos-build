# migration-echo-p0 — adversarial gate r2 (tenancy lens)

Date: 2026-08-29
Reviewed range: `cdce47570..4192ca191`
Lens: scoped re-check of r1 blockers B1–B4 only

## Repository state

`git status --short --branch` reported a clean `fix/migration-echo-p0` worktree. The last three commits are `4192ca191` (r1 fixes), `fd0f0cbe2` (r1 review), and `93fc6fc3a` (P0 migration-output implementation). The current head is `4192ca1915e21dd6b081da581203fe1e3a87d158`; the reviewed base and merge-base remain `cdce47570ecd465faa495391640176bdbc416808`.

## Blocking findings

### B1 — The HTTP arm is real, but teardown does not confirm that its tenant database was dropped

The substantive HTTP-path fix is present. The PostgreSQL-only test enables DB-per-tenant at `tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php:102-106`, then issues the real `POST /api/v1/auth/register` at `:108-110`. `AuthController::register()` selects `TenantProvisioningService` when that config is true at `app/Modules/Identity/Presentation/Controllers/AuthController.php:350-361`; the service synchronously dispatches `CreateDatabase` and `MigrateDatabase` at `app/Modules/Tenant/Application/Services/TenantProvisioningService.php:117-120`. No bus fake replaces that path. The mandated PostgreSQL run on `autoerp_test_j` passed the HTTP test, and the SQLite `ob_start`/real `MigrateDatabase` arm remains at `RegistrationResponseIsPureJsonTest.php:137-165`.

The cleanup acceptance point is not established, however. Teardown calls `deleteDatabase()` at `RegistrationResponseIsPureJsonTest.php:62-65`, but ignores its boolean result and catches every throwable at `:63-69`. It then deletes the tenant's central directory rows at `:71-77`, removing the pointer needed to identify an orphan database. Stancl's PostgreSQL manager returns the `DROP DATABASE` statement result (`vendor/stancl/tenancy/src/TenantDatabaseManagers/PostgreSQLDatabaseManager.php:37-40`), so a false result or exception is observable but currently discarded. A green test therefore proves provisioning/migration, not successful physical cleanup. Teardown must retain central cleanup while failing on an unsuccessful DROP (or explicitly assert `databaseExists($name) === false`) before B1 can be confirmed closed.

### B2 — The token guard misses qualified forms of every named function writer

The guard correctly handles `T_ECHO`, `T_PRINT`, and `T_INLINE_HTML` at `tests/Unit/Migrations/NoBareEchoInMigrationsTest.php:103-122`, and its fixtures prove that inline echo/print/HTML and unqualified `printf`/`dump`/`dd`/`var_dump`/standard-stream writes are found while comments and strings are ignored (`:15-67`). The migration scan is recursive at `:69-98`.

Function classification is entered only for `T_STRING` at `:120-122`. PHP tokenizes `\printf(...)`, `\dump(...)`, `\dd(...)`, `\var_dump(...)`, and `\fwrite(\STDOUT, ...)` as `T_NAME_FULLY_QUALIFIED`; `namespace\printf(...)` and peers are `T_NAME_RELATIVE`. A direct reflection probe against this test's own `prohibitedWrites()` returned:

```text
fully-qualified: []
relative: []
```

Thus all explicitly scoped function-writer families can still be reintroduced through valid qualified syntax while the guard stays green, and the self-test has no qualified fixture. Normalize and classify `T_NAME_FULLY_QUALIFIED` and `T_NAME_RELATIVE` (as well as `T_STRING`), then add qualified positive fixtures for the named functions and `STDOUT`/`STDERR` forms.

## Closed r1 blockers

### B3 — Closed: UOM structured failure context is restored without duplicating the grep line

The inner failure again logs `units.seed_failed` with the original throwable and tenant value at `database/migrations/tenant/2026_08_30_100300_ensure_units_visible_per_company.php:78-82`; its grep-facing census/error line is emitted once through `MigrationOutput::error()` at `:83`. The outer failure similarly logs `units.visibility_migration_failed` with throwable and tenant at `:92-96`, then emits the operator line once through `MigrationOutput::error()` at `:97`. The structured and grep-facing records have distinct messages, so there is no duplicate census line.

### B4 — Closed: base negative and exact-context coverage is restored and expanded

Relative to `git show cdce47570:apps/api/tests/Feature/Uom/UnitsInvariantTest.php`, the three negative `units-seeded` expectations are restored at current lines `70`, `87`, and `108`. The seed-failure expectation matches the exact throwable and tenant at `:123-130`; a new outer-failure test pins the same context for `units.visibility_migration_failed` at `:134-151`. Counting assertion/Mockery expectation sites with `self::fail` included gives **41 current versus 34 at the base**.

## Regression checks

The current local `dev` is `d4b3e8b5d`; the lane still merges from `cdce47570`. A diff of all eight named migrations against `dev` shows unchanged census/message construction and only the intended sink changes. `MigrationOutput::writeToConsole()` appends the same single `PHP_EOL` at `app/Shared/Database/MigrationOutput.php:25-29`, so the console bytes remain identical for W4-1, I-1, payment-method scope/collision, default-location collision, product SKU scope/collision, variant SKU scope/collision, partner VAT scope/collision, and units census/seed/error lines.

`php tools/feature-lane-manifest-check.php` exited 0: **1472 Feature classes across 74 groups**, with **1213** classes parked behind the execution gate and the debt ceiling intact. The new Identity class is accounted for.

## Requested path-level runs

From `apps/api`, SQLite guard/regression paths:

```text
php artisan test \
  tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php \
  tests/Unit/Shared/Database/MigrationOutputTest.php \
  tests/Unit/Migrations/NoBareEchoInMigrationsTest.php
```

Exit 0: **6 passed, 1 skipped, 637 assertions**. The skip is the PostgreSQL-only HTTP arm; the SQLite buffered `MigrateDatabase` arm ran.

Mandated PostgreSQL registration path:

```text
DB_DATABASE=autoerp_test_j DB_CENTRAL_DATABASE=autoerp_test_j php artisan test -c phpunit-pgsql.xml tests/Feature/Identity/RegistrationResponseIsPureJsonTest.php
```

Exit 0: **1 passed, 1 skipped, 7 assertions**. The HTTP/db-per-tenant arm ran; the deterministic SQLite arm skipped.

Full UOM feature directory on SQLite:

```text
php artisan test tests/Feature/Uom
```

Exit 0: **54 passed, 266 assertions**.

GATEVERDICT: CHANGES
B1: OPEN — the real PostgreSQL POST synchronously migrates, but teardown suppresses the DROP result/errors and cannot confirm that the created database was removed.
B2: OPEN — qualified and namespace-relative forms of the explicitly prohibited function writers bypass the token guard and its self-test.
B3: CLOSED — both structured failure logs retain throwable and tenant context, with each grep-facing line routed once through MigrationOutput.
B4: CLOSED — negative `units-seeded` checks and exact failure contexts are restored; the current assertion/expectation set exceeds the base.
