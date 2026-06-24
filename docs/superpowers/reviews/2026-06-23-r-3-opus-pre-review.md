# R-3 Opus Pre-Review — Tenant Context for Subledger Reconciliation

Date: 2026-06-24
Reviewer: Opus via `claude --safe-mode --model opus -p`
Scope:
- `App\Console\TenantScopedCommand::forEachTenant()`
- `accounting:check-subledger-reconciliation`

## Verdict

Proceed with caution: the diagnosis is correct, but the shared-helper blast radius must be audited before implementation.

## Findings

- `forEachTenant()` is shared, so changing it affects every per-tenant batch command. Audit consumers before patching.
- If consumers are mixed between tenant-DB and central-context work, prefer an opt-in helper or localize the accounting fix.
- Current tenant migrations keep `tenant_id` on `companies` and the other relevant tenant-owned tables, so existing tenant filters can remain after tenancy initialization.
- Tenancy must be ended in a per-iteration `finally` to avoid leaking context if a callback throws.
- Keep shared-DB mode behavior unchanged by gating initialization on `tenancy_resolver.db_per_tenant`.

## Recommended Tests

- In `db_per_tenant=true`, expose `forEachTenant()` via a test-only command, create two central tenants, and assert the closure sees `tenant('id')` for each tenant and that tenancy is ended afterward.
- In `db_per_tenant=false`, assert the closure still runs for each tenant but tenancy remains uninitialized.
- Keep the existing accounting command test green.

## Scope Boundary

Invoice media attachment wiring is unrelated and remains deferred for the unified media management transition.
