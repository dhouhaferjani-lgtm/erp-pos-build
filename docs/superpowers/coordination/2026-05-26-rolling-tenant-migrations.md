# Rolling Tenant-Migration Runner — 2026-05-26

**Track:** T6 Phase 0b follow-on (database-per-tenant).
**Branch:** `feat/t6-rolling-tenant-migrations` (base: `feat/t6-phase0b-db-per-tenant`).
**Status:** Implemented + PG-integration-tested. PR open against `feat/t6-phase0b-db-per-tenant` — DO NOT MERGE (orchestrator reconciles).

---

## 1. Problem

Phase 0b flipped Stancl to `PostgreSQLDatabaseManager` (`config/tenancy.php`), moved
tenant-scoped migrations to `database/migrations/tenant/`, and rewrote cross-DB FKs
to plain UUIDs. New tenant databases are migrated **at creation** via Stancl's
`MigrateDatabase` job (dispatched from the provisioning path), which itself just calls
`tenants:migrate` for the one new tenant.

What did NOT exist: a way to roll out a NEW migration to tenants that were **already
provisioned**. When a future migration lands in `database/migrations/tenant/`, every
existing per-tenant database needs it applied. That is what this command does.

## 2. The command — `tenants:migrate-rolling`

`App\Modules\Tenant\Application\Commands\RollingTenantMigrationCommand`
(registered in `App\Modules\Tenant\Infrastructure\Providers\TenantServiceProvider`).

```
php artisan tenants:migrate-rolling [--tenant=<uuid>] [--force] [--pretend]
```

- `--tenant=<uuid>` — restrict the rollout to one tenant (default: every tenant).
- `--force` — run migrations without the interactive confirm (required in production /
  non-interactive contexts; forwarded to the underlying `tenants:migrate`).
- `--pretend` — dump the SQL that would run, per tenant, without executing it.

## 3. Built-in vs custom — what we reuse

We **leverage Stancl's built-in `tenants:migrate`** rather than reinventing the
migrator. `tenants:migrate` is registered by the package's auto-discovered
`TenancyServiceProvider`, honours our `config('tenancy.migration_parameters')`
(`--path = database_path('migrations/tenant')`, `--realpath`, `--force`), and is the
exact same engine the at-creation `MigrateDatabase` job uses. Reusing it means the
rolling path and the creation path can never drift on which migrator / path runs.

**Why a wrapper is still needed — failure isolation.** Stancl's `tenants:migrate`
drives `tenancy()->runForMultiple()`, whose loop is **fail-fast**: an exception while
migrating one tenant propagates and aborts the whole pass, leaving the remaining
tenants un-migrated (and tenancy possibly still initialized). For a fleet-wide rollout
we want the opposite. So the wrapper drives `tenants:migrate` **one tenant at a time**
(`--tenants=[<key>]`), wraps each sub-call in try/catch, always reverts tenancy in a
`finally`, and aggregates the outcome.

## 4. Per-tenant iteration

1. Resolve target tenants (`Tenant::all()`, or the single `--tenant`).
2. For each tenant, in turn:
   - print the tenant label,
   - `Artisan::call('tenants:migrate', ['--tenants' => [$key], '--force' => ..., '--pretend' => ...])`,
   - echo the sub-command output (indented) so per-tenant progress is visible,
   - if the sub-call throws OR returns a non-zero exit code, collect the failure and continue,
   - `finally`: if tenancy is still initialized, `tenancy()->end()` before the next tenant.
3. Print a summary (`N migrated, M failed`) listing each failed tenant + error.

Stancl's `tenants:migrate` initializes tenancy for the targeted tenant; our gated
`App\Providers\TenancyServiceProvider` (which keys the `BootstrapTenancy` listener off
`tenancy_resolver.db_per_tenant`) swaps the default connection to that tenant's
database. So the migration runs **inside the tenant DB**, against the
`migrations/tenant/` path. The central database is never touched by a tenant migration.

## 5. Failure policy — continue-and-collect-errors (chosen)

**Decision: continue-and-collect-errors, exit non-zero if any tenant failed.**

Rationale: a fleet-wide rollout should not be hostage to one unhealthy tenant (e.g. a
dropped/unreachable DB, or a tenant-specific data state that trips a migration). We
attempt every tenant, isolate each failure, surface all of them in a final summary, and
return `FAILURE (1)` so CI / schedulers / operators notice — while every healthy tenant
has still been migrated. This is strictly safer than Stancl's default fail-fast for a
rolling operation. (An operator who wants strict fail-fast for a single tenant can use
`--tenant=<uuid>`, which still exits non-zero on that tenant's failure.)

## 6. Compat mode (`db_per_tenant = false`)

When `config('tenancy_resolver.db_per_tenant')` is false (the pre-flip, single
shared-database reality), there are no per-tenant databases to migrate — tenant
migrations already load into the shared schema (in tests via
`AppServiceProvider::loadTenantMigrationsInTestingEnvironment`). The command is a
**documented no-op**: it prints "Database-per-tenant mode is OFF … No-op", performs no
provisioning and no tenancy initialization, and returns `SUCCESS (0)`. It never crashes
and never creates a tenant database as a side effect. (Verified by
`test_compat_mode_is_a_documented_no_op`.)

## 7. Idempotency

Safe to re-run. Stancl's migrator records applied migrations in each tenant DB's
`migrations` table, so a re-run finds nothing pending for an up-to-date tenant
("Nothing to migrate") and is a no-op for it. Verified by
`test_is_idempotent_when_a_tenant_is_already_up_to_date` (asserts the staged migration
appears exactly once in the tenant `migrations` ledger after two runs).

## 8. Relationship to the at-creation `MigrateDatabase` path

| Path | When | Mechanism |
|---|---|---|
| `MigrateDatabase` job | Tenant **creation** (provisioning) | dispatches `tenants:migrate` for the one new tenant |
| `tenants:migrate-rolling` | A **new migration** lands for **already-provisioned** tenants | drives `tenants:migrate` per existing tenant, isolating failures |

Both ultimately run the same Stancl migrator against the same `migrations/tenant/` path,
so a tenant created at any point and a tenant rolled forward later converge on the same
schema. The rolling command is the "catch up the fleet" complement to the
"migrate-on-create" job — it does not replace it.

## 9. Tests (PG-only integration)

`tests/Feature/Tenant/RollingTenantMigrationTest.php` — PG-only (skips on SQLite), no
`RefreshDatabase` (PostgreSQL can't `CREATE DATABASE` inside a transaction); creates
real per-tenant databases and drops them in `tearDown`. Opts into
`tenancy_resolver.db_per_tenant = true` like the existing flip test.

- `test_rolls_a_pending_migration_out_to_all_provisioned_tenants` — provision TWO tenant
  DBs, stage a throwaway pending tenant migration (temp dir appended to the configured
  migration path), run the rolling migrate → BOTH tenant DBs receive the new table and
  the central DB is untouched.
- `test_is_idempotent_when_a_tenant_is_already_up_to_date` — second run is a no-op; the
  migration is recorded exactly once.
- `test_isolates_a_single_tenant_failure_and_still_migrates_the_rest` — a staged
  migration that throws ONLY for one tenant (keyed off the active tenant id) → the runner
  reports that tenant, exits `1`, and STILL migrates the healthy tenant (which gets the
  table; the failed tenant does not).
- `test_compat_mode_is_a_documented_no_op` — `db_per_tenant = false` → no-op, exit 0, no
  tenant DB created.

Run locally: `php artisan test --filter RollingTenantMigrationTest` against a PG
testing connection (CI: the `backend-test-pgsql` job in `ci.yml`).

## 10. Cross-tenant classification + allowlist reconciliation ⚠️

`tenants:migrate-rolling` is **cross-tenant by design** (it iterates every tenant). It
is classified per `tests/Architecture/ConsoleCommandTenantContextTest` via a class-level
`@cross-tenant-by-design <justification>` PHPDoc tag on the command — NOT by extending
`TenantScopedCommand` (which binds a single `--tenant`+`--company` CompanyContext; that
shape is wrong for a fleet migrator). No edit to the deferrals fixture
(`tests/Architecture/fixtures/console-command-deferrals.json`) was needed for this
command.

**⚠️ FLAG FOR ORCHESTRATOR:** `ConsoleCommandTenantContextTest` currently FAILS on the
base branch (`feat/t6-phase0b-db-per-tenant`) due to a **pre-existing, out-of-scope**
unclassified command — `App\Modules\Tenant\Application\Commands\ReconcileIdentitiesCommand`
(added by T6-PHASE0A `tenant:reconcile-identities`, unchanged on this branch, empty
deferrals fixture). This is unrelated to the rolling-migration command (which is
correctly self-classified). The fiscal Codex session is editing this test's allowlist
concurrently; the orchestrator should reconcile branches at merge so the
`ReconcileIdentitiesCommand` classification (and any allowlist entries) lands once.

## 11. Out of scope (noticed, not touched)

- `ReconcileIdentitiesCommand` lacking `@cross-tenant-by-design` (signup-provisioning lane).
- `ResetTenantCommand` still uses the pre-flip `DROP SCHEMA`/`CREATE SCHEMA` +
  `--path database/migrations` (non-tenant path) approach — a schema-era artifact that
  predates the database-per-tenant flip. Left as-is per scope discipline; worth a
  follow-up to align it with the DB-per-tenant model.
