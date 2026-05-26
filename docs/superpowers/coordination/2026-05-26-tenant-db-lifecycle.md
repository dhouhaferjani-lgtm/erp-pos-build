# T6 Phase 0b — Tenant database lifecycle teardown (deprovision + suspend)

**Date:** 2026-05-26
**Branch:** `feat/t6-tenant-db-lifecycle` (base: `feat/t6-phase0b-db-per-tenant`)
**Spec:** [`2026-05-24-t6-tenant-provisioning.md`](../specs/2026-05-24-t6-tenant-provisioning.md)
**Topology contract:** [`2026-05-24-migration-topology-contract.md`](2026-05-24-migration-topology-contract.md)

The provisioning counterpart. Tenant *creation* wires Stancl `CreateDatabase` +
`MigrateDatabase` (driven explicitly, not via the `TenantCreated` model event —
see `TenancyServiceProvider`). This adds the reverse: dropping the physical
per-tenant database and cleaning the central directory when a tenant is deleted
or archived, plus a reversible suspend that preserves the database.

## What was wired

| Artifact | Path | Role |
|---|---|---|
| `TenantDeprovisioningService` | `app/Modules/Tenant/Application/Services/TenantDeprovisioningService.php` | `deprovision()` (delete/archive) + `suspend()` |
| `DeprovisionTenantCommand` | `app/Modules/Tenant/Application/Commands/DeprovisionTenantCommand.php` | `tenant:deprovision {slug} [--suspend] [--force]` |
| Command registration | `app/Modules/Tenant/Infrastructure/Providers/TenantServiceProvider.php` | adds the command to `$this->commands([...])` |

Tests (all green):
- `tests/Feature/Tenant/TenantDeprovisioningTest.php` — PG-only, `db_per_tenant=true`, no `RefreshDatabase`; drops tenant DBs in `tearDown`.
- `tests/Feature/Tenant/TenantDeprovisioningCompatTest.php` — shared-DB compat, `db_per_tenant=false`, `RefreshDatabase`.
- `tests/Feature/Tenant/DeprovisionTenantCommandTest.php` — CLI surface (compat).

## Gating contract (CRITICAL)

A physical `DROP DATABASE` is attempted **only** when
`config('tenancy_resolver.db_per_tenant') === true`. In shared-DB row-level
compat mode (dev/tests, pre-flip prod) there is no per-tenant database to drop —
dropping would target a non-existent or the shared database — so the
physical-drop path is skipped entirely and only the central rows are cleaned.
This mirrors `TenancyServiceProvider` (gates the Stancl bootstrap on the same
flag) and `TenancyResolver`.

The drop is driven by **explicit dispatch** of Stancl's `DeleteDatabase` job
(`Bus::dispatchSync`), NOT by wiring it to the Eloquent `deleted`/`TenantDeleted`
model event. Rationale (same as why provisioning does not wire `TenantCreated ->
CreateDatabase`): an event listener would fire `DROP DATABASE` for *every*
tenant-row delete, including compat-mode tests that delete tenant rows inside a
transaction, which PostgreSQL forbids. Explicit dispatch keeps teardown
symmetric with provisioning and under the flag's control.

## Central-cleanup contract

On `deprovision()`, central directory rows are removed via the central
connection (`config('tenancy.database.central_connection')`, falling back to
`central`) in **children-before-tenant** order — the same ordering the
provisioning compensation path uses:

1. `domains` (FK to `tenants`, cascade — deleted explicitly anyway for determinism)
2. `central_identities` (NO FK — must be deleted explicitly; topology §9.1)
3. `tenant_subscriptions` (FK to `tenants`, cascade — deleted explicitly anyway)
4. `tenants` (the directory row) last

Each delete is scoped to the tenant id and is naturally idempotent: a second
deprovision deletes zero rows and does not error. `central_identities` is the
one row with no DB-level cascade, so explicit cleanup is mandatory, not just
defensive.

## Suspend vs delete semantics

| | Physical DB | Central rows | Status |
|---|---|---|---|
| `deprovision()` (delete/archive) | dropped (DB mode only) | removed | row gone |
| `suspend()` | **preserved** (reversible) | **untouched** | → `Suspended` |

`suspend()` only flips `TenantStatus::Active → Suspended` and is idempotent
(re-suspending a suspended tenant is a no-op). Access revocation is **already
enforced** at request time by
`App\Modules\Tenant\Presentation\Middleware\EnsureTenantIsActive`, which 403s any
tenant whose status is not `Active` — so suspend needs no separate revocation
step. (Eager invalidation of already-issued Sanctum bearer tokens on suspend is
NOT done here: the central `personal_access_tokens` rows are keyed to tenant-side
`User` rows, so cutting them requires entering tenant context; the request-time
middleware gate is the documented mechanism. Flagged as optional hardening.)

## Idempotency / safety

- `deprovision()` on a tenant whose DB was never provisioned (provisioning
  failed mid-flight, or an unclaimed pre-warm row) does not error — the existence
  probe (`databaseExists`) short-circuits the drop, and a null/empty resolved DB
  name short-circuits before the probe.
- A failing existence probe (transient connection fault) is logged and skipped;
  it does NOT abort the central cleanup.
- Calling `deprovision()` twice is safe.

## Console-command classification — RECONCILIATION FLAG

`DeprovisionTenantCommand` is tenant-classified via a class-level
`@cross-tenant-by-design <justification>` PHPDoc tag (same pattern as
`CreateTenantCommand` / `ResetTenantCommand`), so `ConsoleCommandTenantContextTest`
recognises it. It is NOT added to the deferrals fixture
(`tests/Architecture/fixtures/console-command-deferrals.json`), so there is no
edit-conflict with the concurrent Codex fiscal session that is editing that
allowlist. **No allowlist edit is required for this work.**

**Pre-existing, out-of-scope:** `ConsoleCommandTenantContextTest` already fails
on the base branch because `ReconcileIdentitiesCommand` (added in T6-PHASE0A,
commit `b75cb0930`, owned by the signup-provisioning / identity lane) is
unclassified — no `@cross-tenant-by-design` tag, not in the deferrals fixture.
This is not introduced by this work and is left untouched per scope discipline.

## Out of scope noticed

- `ReconcileIdentitiesCommand` unclassified (above) — needs a
  `@cross-tenant-by-design` tag or a deferrals entry by the identity lane.
- `TenantProvisioningService` referenced in the task brief does **not** exist on
  this base branch (it lives on the signup-provisioning lane / PR #142); patterns
  were taken from `CreateTenantCommand`, the flip/isolation tests, and the
  topology contract's compensation note instead.
- Eager Sanctum token revocation on suspend (cross-DB) — optional hardening.
