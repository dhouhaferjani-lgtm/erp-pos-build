# Gate 1 Verdict — Wave 1 Scope Foundation (multi-location §1)

Reviewer: tenancy-authz-reviewer (adversarial). Scope diff: `git diff origin/dev...HEAD` over the
Company/Identity/Http modules, tenant migrations, and the FE locations/auth/store primitives.
Branch `feat/multi-location` @ `4c29e831b`. This is a FRESH full re-review after the fix commits
`d44f43422..4c29e831b`. Every claim below was verified against the code at the cited `file:line`.

## Verification of the six hardening commits (all HOLD)

1. `clamp persisted location scopes` — `apps/web/src/features/locations/hooks/useViewScope.ts:16-24`
   clamps a persisted subset to the currently-allowed ids (and collapses to `'all'` when the
   subset is empty or equals the full set) before any `location_ids[]` request can send a stale id
   and eat a resolver 403. HOLDS.
2. `preserve legacy admin permission grants` —
   `apps/api/database/migrations/tenant/2026_07_16_100100_register_manage_location_access_permission.php:19-47`
   looks up the tenant-scoped `admin` role by team column, falls back to any `admin` row when a
   legacy seed created it pre-team-context, logs `multiloc.permission_migration_legacy_admin_role`,
   and grants idempotently (`hasPermissionTo` guard). HOLDS.
3. `preserve inactive location management` — `LocationController::managementIndex` +
   `pickerPayload` (`.../Company/Presentation/Controllers/LocationController.php:42-53,100-120`)
   apply NO `is_active` filter, so admins can still assign access to inactive locations. The
   `transactionIndex` picker (destinations) correctly filters `is_active = true` (`:60-71`). HOLDS.
4. `harden location list authorization` — the `/company/locations/transaction-destinations` route
   is gated by `require.any.permission:...` (`Company/routes.php:49-51`; alias registered
   `bootstrap/app.php:53`); the resolver intersects a restricted subset with the real company set
   (`LocationScopeResolver.php:63-66`) so stale/cross-company ids are dropped fail-closed. HOLDS.
5. `preserve fail-closed location membership semantics` —
   `LocationContext::getAllowedLocationIds` still returns `[]` (deny-all) for a memberless user
   (`Company/Services/LocationContext.php:198-200`); the resolver maps that to
   `array_intersect([], all) = []`, and `resolve()` throws `AuthorizationException` on any non-empty
   request against it (`LocationScopeResolver.php:41-43`). Deny-all is preserved. HOLDS.
6. `preserve legacy admin permission grants` / `close Wave 1 gate findings` — atomicity of the
   grant path in `UserController` verified below. HOLDS.

## Contract completeness (Wave 1)

- Migration ordering: backfill `2026_07_16_100000` (single-company backfill / multi-company skip+log,
  idempotent via `whereNotExists`, non-reversible, least-privilege `Viewer` role so backfilled staff
  are never treated as owners — `:63-90`) then permission registration `2026_07_16_100100`. Both
  self-guarding, safe under push=deploy.
- `LocationScopeResolver` (`.../Company/Services/LocationScopeResolver.php`): fail-closed, single-company
  (no parent expansion), `?string $bypassPermission` (only `replenishment.process` is ever passed),
  NULL-column = all, `[]` = deny-all, subset intersected with real company set. Constructor-injected,
  no `app()`. Always-resolve/always-apply invariant honored: `scopedIndex`/`index` always call
  `resolve()` and always `whereIn(...)` the result (`LocationController.php:36,82-86`).
- `UserController` grant path: request-level gate requires `users.manage_location_access` when the
  field is present (`CreateUserRequest.php:21-28,63-83`, `UpdateUserRequest.php:21-28,71-91`, error
  contract preserved via `failedAuthorization`). Controller re-authorizes BEFORE any write —
  store guard at `UserController.php:206-211` runs before the `DB::transaction` at `:216`; update
  guard at `:313-318` + self/membership checks at `:334-354` all run before the transaction at `:356`
  (Findings 3 & 7 atomicity: a denied grant leaves no/unchanged rows). Self-escalation blocked
  (`:880-886`), restricted granter cannot exceed own set nor grant explicit-null (`:903-913`),
  non-company ids rejected (`:888-897`), `writeLocationGrant` asserts exactly one active target
  membership (`:923-933`). User list exposes `allowed_location_ids` only to managers (`:118-132`).
- Route middleware: Company group carries `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]`
  (`Company/routes.php:23`); management endpoint gated `can:users.manage_location_access` (`:53-55`).
- A5 "every list honors the allowed set": duplicate `Inventory\...\LocationController` deleted; the
  wired `GET /locations` (`Inventory/Presentation/routes.php:32`, `can:inventory.view`) uses the
  Company controller whose `index()` is resolver-scoped (`LocationController.php:82-86`).
- Permission seeding: `users.manage_location_access` present in `RolesAndPermissionsSeeder.php:291`
  (guard `sanctum`, matching the migration `:17`); FE map `usePermissions.ts:111`.
- FE primitives: `viewScopeStore.ts` per-company+user keyed, reset only on real company/user change,
  malformed cross-tab payload PRESERVES scope (`:112-124`); `locationScopedKey.ts:11-17` keeps the
  resource literal leading with a sorted non-leading `{locScope}` segment.
- Test quality: deny paths and atomic-rollback are exercised with real error codes
  (`UserLocationAccessTest.php` `test_store_denied_grant_creates_no_user_membership_role_or_audit_row`,
  `test_update_denied_grant_leaves_profile_role_and_membership_unchanged`, self-escalation, restricted
  granter, associative-array rejection); resolver fail-closed / absent-row-deny-all / bypass covered
  (`LocationScopeResolverTest.php:53-112`).

## Findings

- [Minor] `apps/api/app/Modules/Company/routes.php:49-51` + `LocationController::transactionIndex`
  (`.../LocationController.php:60-71`) — the transaction-destinations picker returns every ACTIVE
  company location (name/code) to any holder of a transact permission (incl. `document-ingestions.create`),
  regardless of the caller's read subset. This is spec-sanctioned (cross-location transfer destinations
  may lie outside the read scope) and discloses only existence/name, not location data. No fix required;
  noted so a future reviewer does not mistake it for a scoped-read endpoint.
- [Minor] Deleted `Inventory\...\LocationController` still appears in the generated composer autoload
  maps (`apps/api/vendor/composer/autoload_classmap.php:977`, `autoload_static.php:2110`). No source or
  route references it. Deploy must regenerate autoload (`composer dump-autoload`, standard in
  `composer install`) so the stale class-map entry to a now-missing file is dropped. Low risk.
- [Minor/Observation] `UserController::store` (`.../UserController.php:198-204,251-257`): a caller who
  holds `users.create` but has NO active company membership (so `getAllowedLocationIds` returns `[]`)
  and omits the field will have `hasLocationGrant` become true (`[] !== null`) and write
  `allowed_location_ids = []` (deny-all) onto the new user rather than the Task-2 default of NULL=all.
  This is fail-closed (narrower, never an escalation) and an odd operator profile; acceptable, but a
  one-line guard treating `[]` caller-scope as "no inheritance" would make the intent explicit.

No Critical or Important findings. Physical tenant isolation is untouched (resolver/controllers are
HTTP-only and derive company from bound `CompanyContext`; migrations/command derive location directly
from tenant-connection data, never via the resolver). No cross-tenant read, no auth bypass, no privesc,
no silent 403 on a prod path, no UUID/PostgreSQL `MAX(uuid)`/unvalidated-uuid hazard in the new code.
The Wave 1 contract is genuinely complete and the hardening commits hold.

VERDICT: APPROVE
