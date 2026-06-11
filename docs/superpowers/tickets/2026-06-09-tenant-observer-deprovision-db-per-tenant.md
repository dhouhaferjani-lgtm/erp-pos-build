# Ticket — TenantObserver token-revocation breaks tenant deletion under DB-per-tenant

**Opened:** 2026-06-09 (surfaced during the parapharmacy multi-branch e2e verification)
**Module:** Identity / Tenant lifecycle (`app/Observers/TenantObserver.php`)
**Severity:** High — any tenant deletion 500s under the DB-per-tenant flip; blocks per-tenant deprovision (A4 ops) and idempotent re-seeding
**Status:** Open (NOT fixed — security-sensitive, needs design)

## Problem
`TenantObserver::deleting()` (and `forceDeleted()`) call `revokeAllUserTokens($tenant)`, which queries the tenant-side `users` table on the **current** connection:

```php
// app/Observers/TenantObserver.php:91
User::where('tenant_id', $tenant->id)->select(['id','tenant_id'])->chunkById(200, ...);
```

This was written for the old **row-level / single-DB** model (the docstring even says "fires BEFORE the `users.tenant_id` ON DELETE CASCADE"). Under DB-per-tenant (T6, the live model since 2026-05-28):

- `users` lives in the **tenant** database; `tenants` and `personal_access_tokens` live in **central**.
- When the central `tenants` row is deleted, the default connection is **central** → `users` does not exist there → `SQLSTATE[42P01]: relation "users" does not exist` → 500.

Reproduced deterministically: re-running `ParapharmacyMultiBranchSeeder` (which deletes the existing `pharmabio-france` tenant before recreating) throws at `ParapharmacySeeder::createParapharmacyTenant` → `$existingTenant->delete()` → `TenantObserver::deleting` → `revokeAllUserTokens`. The seeder's cleanup even drops the tenant DB **first**, so by the time the observer runs the users table is unreachable on any connection.

## Why it matters
- **Deprovision (A4 ops):** any code path that deletes a tenant (a `tenant:delete`/deprovision command, GDPR erasure, failed-signup rollback) will 500 under the flip. Per-tenant lifecycle ops are launch-blocking once real tenant data lands.
- **Token-revocation invariant (master plan §15 / Invariant A.2):** the revocation is a security control. Under DB-per-tenant it currently NEVER runs on delete (it crashes first), so the intended "revoke pre-deletion bearer tokens" guarantee is silently not met — central `personal_access_tokens` for the tenant's users may be orphaned rather than revoked.
- **Re-seeding:** blocks idempotent local re-seeds; forces a full central DB drop+recreate as a workaround.

## Proposed fix (needs an owner/security decision)
The correct behaviour under DB-per-tenant needs a deliberate design choice, because users (tenant DB) and tokens (central DB) live apart:

1. **Make `revokeAllUserTokens` mode-aware.** In DB-per-tenant mode, read the user ids **inside the tenant's DB context** (`tenancy()->initialize($tenant)`) BEFORE the tenant DB is dropped, collect their ids, then revoke the matching central `personal_access_tokens` (tokenable_type=User, tokenable_id IN ids) on the **central** connection. In single-DB mode, keep the current query.
2. **Fix the deprovision ordering** so token revocation runs while the tenant DB still exists (revoke → drop DB → delete central row), not after the DB is gone (as the seeder cleanup currently does).
3. Consider whether `personal_access_tokens` should carry a `tenant_id` so a tenant's tokens can be purged by a single central `where('tenant_id', …)->delete()` without needing the tenant DB at all — simplest and most robust for deprovision. (Schema change; evaluate.)
4. Add a DB-per-tenant feature test: provision a tenant, mint a token, delete the tenant, assert (a) no 500 and (b) the token is gone from central.

## Workaround (used by this verification session)
Seed against a **fresh** central DB (drop+recreate + `migrate`) so no existing tenant row triggers the deletion path. The multi-branch seeder is therefore NOT idempotent under DB-per-tenant today.

## References
- `app/Observers/TenantObserver.php:59-100`
- `database/seeders/ParapharmacySeeder.php:254-276` (the cleanup path that triggers it)
- Memory: `project_db_per_tenant_e2e_validation` (the 6 flip-blocker classes; this is a 7th, in the *deletion* direction), `project_current_priorities` (A4 pre-flip ops hardening).
