# Handover — Investigate & fix tenant deprovisioning token revocation (properly)

**Date:** 2026-06-14
**Branch off:** `dev` (create a fresh worktree, e.g. `apps/erp.tenant-deprovision-fix` on `feat/tenant-deprovision-token-revocation-v2`)
**Severity:** Low (security hygiene) — not a launch blocker, but a real correctness gap.
**Do NOT use** the existing `feat/tenant-deprovision-token-revocation` branch — it is **242 commits behind dev** and built on a different design (`tenant_id`-stamped tokens + single central delete). dev's `personal_access_tokens` table has **no `tenant_id` column**; revocation is by `(tokenable_type, tokenable_id)`. Adopting that branch means a schema migration + re-architecting the observer. Build the fix fresh on dev instead, then prune the stale branch + its worktree (`apps/erp.tenant-deprovision`).

## The gap (verified 2026-06-13/14)

dev revokes a tenant's central bearer tokens via `App\Observers\TenantObserver`:
- `updated()` → on status change to `Suspended` → `revokeAllUserTokens()`
- `deleting()` / `forceDeleted()` → `revokeAllUserTokens()`
- `revokeAllUserTokens()` enumerates the tenant's user ids inside `$tenant->run()` (tenant DB), with a `central_identities` fallback if the tenant DB is unreachable, then deletes central `personal_access_tokens` by `(tokenable_type, tokenable_id)` in chunks of 200.

**But two paths delete the tenant via the query builder, which fires NO Eloquent model events, so the observer never runs and tokens are left dangling:**

1. **Deprovision** — `TenantDeprovisioningService::deprovision()` → `$central->table('tenants')->where('id', $tenant->id)->delete()` (`apps/api/app/Modules/Tenant/Application/Services/TenantDeprovisioningService.php:161`). The query-builder delete is **deliberate** (see the class comment ~lines 44-49): an Eloquent `deleted` listener would fire `DROP DATABASE` for every tenant-row delete, including compat-mode tests that delete tenant rows inside a transaction. So we cannot simply move revocation onto a model event — the deprovision service must revoke **explicitly**. It currently does NOT (no token delete anywhere in the service).

2. **Failed-provisioning / registration rollback** — `TenantProvisioningService` compensation deletes the central tenant row via query builder (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:235`). If a token was already minted for the half-created user before the failure, it is orphaned and not revoked.

**Why severity is low (confirm, don't assume):** a deprovisioned tenant's `central_identities`/`domains`/`tenants` rows are deleted, and `EnsureTenantIsActive` 403s any non-active tenant, so a lingering token should fail to resolve a live tenant anyway. The fix is defence-in-depth + hygiene (no stale credential rows). Quantify the actual residual risk during investigation — if a lingering token CAN still authenticate against anything, severity rises.

## Recommended fix (TDD)

1. **Extract** the revocation logic out of `TenantObserver::revokeAllUserTokens()` into a shared, injectable service method (e.g. `IdentityIndexService::revokeTenantTokens(Tenant)` or a dedicated `TenantTokenRevoker`) — constructor-injected, no `app()`. The observer keeps calling it (suspend/delete/forceDelete); the deprovision service gains a call to it.
2. **Call it from `TenantDeprovisioningService::deprovision()`** — BEFORE the central directory rows are deleted (the revoker needs the tenant + its `central_identities`/user pointers to still exist). Mind the ordering against the `central_identities` fallback path.
3. **Failed-registration rollback** — verify whether `TenantProvisioningService` / `AuthController::register` already revokes a minted token on rollback (AuthController does `$user->tokens()->delete()` in logout/password flows at lines ~566/591/809, but NOT confirmed on the register-failure compensation). If missing, revoke the orphaned token in the rollback path.
4. **Audit for other observer-bypassing tenant deletes.** Known query-builder sites: `TenantDeprovisioningService:161`, `TenantProvisioningService:235`. Confirm no others (grep `table('tenants')->...->delete`, `->forceDelete()`).

## Tests to write first (red → green)

- Deprovision a tenant that has active central tokens → assert 0 rows remain in `personal_access_tokens` for that tenant's users. (PG-backed; db-per-tenant mode.)
- Failed provisioning/registration that minted a token → after rollback, assert the token is gone.
- Suspend still revokes (regression guard for the extracted service).
- Compat-mode / shared-DB: deprovision still works and does not error when no per-tenant DB exists (the gating contract — physical DROP only when `tenancy_resolver.db_per_tenant === true`).

## Gotchas

- **Do NOT** wire revocation to an Eloquent `deleted` event — it re-introduces the `DROP DATABASE`-in-tests problem the query-builder delete exists to avoid.
- `revokeAllUserTokens` uses `$tenant->run()` then falls back to `central_identities`. On the deprovision path the tenant DB may already be dropped (`DeleteDatabase` dispatched) — make sure revocation runs BEFORE the DB drop, or rely on the `central_identities` fallback. Verify the ordering in `deprovision()`.
- **NEVER run the full PHPUnit suite** (crashes the laptop) — scope with `--filter`. PG-backed token/identity tests run at the dev→main gate / `workflow_dispatch`, not on every PR→dev.
- Token revocation under db-per-tenant: tokens live in CENTRAL `personal_access_tokens`; users live in the tenant DB. Keep the revoker pinned to the central connection (see `CentralPersonalAccessToken`).
- After landing: **prune** `feat/tenant-deprovision-token-revocation` + `apps/erp.tenant-deprovision`.

## Provenance

Surfaced during the 2026-06-13 dev consolidation (see `project_dev_consolidation_2026_06_13` memory). The original stale branch's intent ("revoke on suspend/delete/deprovision" + "revoke orphaned token on failed-registration rollback") is correct; only its design/age is wrong.
