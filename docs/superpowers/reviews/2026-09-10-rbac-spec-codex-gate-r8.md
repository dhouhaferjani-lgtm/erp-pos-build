# Codex spec gate r8 — roles & permissions catalogue design rev 8 (gpt-5.6-sol, high, read-only, 2026-09-10)

## Rev-1 base SHA

Read at **`03d36a8b9`**.

The spec’s declared application-code base remains `971528977`. `git diff --quiet 971528977..03d36a8b9 -- apps/api apps/web apps/pos packages/shared` returned zero, so the application paths are byte-identical. `apps/api/vendor/` is absent, so Artisan could not boot; route arithmetic was recomputed from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv`.

Lane tips read:

- `dev`: `5dbb7e1ee`
- `lane/w-lot-a-1a`: `2fa724c1d`
- `lane/t1-transfers-edge`: `86273346a`
- `lane/t2-receipt-spine`: `93b106461`

## Rev-7 closure table

| Rev-7 finding | Rev-8 disposition |
|---|---|
| B-1 — fleet success after a live tenant migration failure | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1263-1273,2068,2291`. A start-snapshot tenant failing either readiness predicate is `FAILED`, carries `reason=tenant_not_ready`, is named, and forces non-zero exit. `skipped_incomplete` is gone; `provisioned_during_run` is population-only. The underlying ordering is confirmed at `apps/api/docker/entrypoint.sh:148-154` and `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:86-107,145-150,222-224`. |
| M-1 — ensure lacked team boundary and executable fleet contract | **CLOSED** at spec `:1212-1254,1351-1352,2188,2192-2196`. Both fleet commands inject one `TenantFleetRunner`; ensure forwards arrays explicitly, sets/restores the Spatie team, and uses the lane’s NULL-or-tenant predicate. The model is faithful to `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-56,96-100,111-128`. |
| M-2 — `ResetTenantCommand` did not satisfy INITIALIZATION-ONLY | **CLOSED** at spec `:1081,1098-1104,2188`. Reset is LOCKED, and the final assignment is placed under the lock after schema recreation. Current destructive flow is confirmed at `apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:21-35,59-95`. The false rationale about the advisory lock living in the schema remains a minor below. |
| minor 1 — `RoleController::store` cannot satisfy pre-insert row locking | **CLOSED** at spec `:1059-1061,1069`: transaction → advisory lock → insert → initial grants, without ceremonial `FOR UPDATE`. Current create/grant calls are `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:191,197`. |
| minor 2 — EC-20c had the delta-first outcome backwards | **CLOSED** at spec `:2067`. Both acquisition orders now produce the full eligible 0b grant set. The lane is additive except for the one manager recall revocation at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:163-181`. |
| Rev-7 citation audit | **CLOSED at the rev-8 anchors.** The new citations listed at spec `:2495` resolve correctly. Rev 8 still carries one older unqualified path that is stale at HEAD; see citation audit. |

The change log at spec `:2484-2501` accurately describes the five substantive rev-7 closures. Nothing was incorrectly rejected.

## BLOCKER

None. The normal, non-dry-run, all-tenant boot path now fails closed on migrations-behind and database-absent tenants.

## MAJOR

### M-1 — Dry-run explicitly turns a blocked fleet into success

The runner contract says any `BLOCKED` or `FAILED` tenant produces a non-zero exit, then immediately says `--dry-run` “always exits 0 unless a tenant is unreachable” (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1273-1274`).

That contradicts the per-tenant command contract—collision is `BLOCKED`, exit 2 (`:982`)—and EC-7, where a rename collision is a decided `BLOCKED` state (`:2046`). A dry-run is the supported deploy preview (`:1163`); reporting a detected collision while returning green makes it unsafe as a CI/preflight gate.

Required correction: dry-run must remain write-free but retain the ordinary aggregate exit rule: non-zero for any `BLOCKED` or `FAILED` result. Add an edge test for a dry-run detecting `rename_target_exists`.

### M-2 — Explicit tenant selectors have no resolution or failure contract

`TenantFleetRunner::$onlyTenants` and both fleet commands accept IDs or slugs (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1224,1241,1246`), but the mechanical algorithm snapshots only IDs and never says how the selector is applied, deduplicated, or validated (`:1265-1270`).

Copying the closest existing iterator is unsafe: `RollingTenantMigrationCommand` accepts only one UUID (`apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48-50,138-153`) and returns success when it matches zero tenants (`:64-70`). Thus a typo in the remediation command prescribed at spec `:1263,2196,2204` can plausibly attempt zero tenants and still write an `ok` status.

Required contract:

- resolve every supplied ID/slug against the start snapshot;
- each selector must resolve exactly one tenant;
- define duplicate ID/slug resolution;
- any unmatched or ambiguous selector is `FAILED`, named in the aggregate, and exits non-zero;
- test IDs, slugs, duplicates, and unmatched selectors for both commands.

Compared with `RollingTenantMigrationCommand`, the proposed runner shares one directory read, per-tenant isolation and aggregate failure reporting (`RollingTenantMigrationCommand.php:80-103,158-175`). It deliberately adds a physical database check through `TenancyResolver::initializeIfProvisioned()` (`apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php:81-105`). Its selector and zero-target semantics are not presently equivalent or specified.

### M-3 — Wave 2 deploy regresses from fleet sync to the single-tenant command

Wave 1 correctly deploys with `permissions:sync-fleet` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2204`), but wave 2 prescribes `permissions:sync` (`:2217`).

The latter accepts one `--tenant`, or otherwise requires an already-current tenant context (`:967-969`). Wave 2 adds manifest permissions and a tenant `users` migration across the fleet (`:2212-2213`); one context-free invocation cannot synchronise all tenants.

Replace the wave-2 deployment command with `permissions:sync-fleet`. The same correction should be guarded by a runbook/command-sequence test so later waves cannot silently regress to the per-tenant entry point.

### M-4 — Wave 0a’s “verified file list” cannot implement 0a-3

0a-3 says `authz.self` is applied to seven routes, but its Files cell lists only the middleware and alias map (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2163`). The verified list at `:2147-2151` likewise omits:

- `apps/api/app/Modules/Notification/Presentation/routes.php`, needed for the two notification mutations currently at `:26-30`;
- `apps/api/app/Modules/SupportAccess/Presentation/routes.php`, needed for the exit route currently at `:49-58`;
- `apps/api/tests/Architecture/SelfServiceRouteAllowListTest.php`;
- `apps/api/tests/Architecture/SelfServiceRouteShapeTest.php`, both required by spec `:1432-1436`.

The liveness fixture in 0a-2 is also left without an exact filename (`:1462,2162`).

I checked the two omitted production route files against all three lane diffs; both are disjoint. Consequently all six 0a tasks remain technically dispatchable today, but the document’s claimed exhaustive path list is not executable as written. Add the omitted paths and name the liveness fixture before dispatch.

Corrected 0a touch map:

| Item | Exact known files |
|---|---|
| 0a-1 | `ScopePermissionCacheToTenant.php`; `RestoreCentralPermissionCache.php`; `TenancyServiceProvider.php`; `ProvisionsTenantDatabases.php`; `PermissionCacheTenantScopingTest.php`; `apps/api/tests/feature-lane-manifest.json` |
| 0a-2 | `RoutePermissionCoverageRatchetTest.php`; `tests/Architecture/baselines/route-permission-coverage-baseline.json`; one liveness file whose exact path must be named |
| 0a-3 | `AllowSelfService.php`; `bootstrap/app.php`; Identity `routes.php`; Notification `Presentation/routes.php`; SupportAccess `Presentation/routes.php`; `SelfServiceRouteAllowListTest.php`; `SelfServiceRouteShapeTest.php` |
| 0a-4 | Identity `routes.php`; route-coverage baseline |
| 0a-5 | Promotion, Uom, Menu and Coupon `Presentation/routes.php`; route-coverage baseline |
| 0a-8 | `docs/conventions/03-AUTHORIZATION.md`; `.claude/commands/add-permissions.md` |

## MINOR

### m-1 — The consolidated lock invariant still describes twelve writers and erases the store exception

The exhaustive table contains thirteen LOCKED rows, including Reset (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1067-1081`). The consolidated invariant still says “its twelve rows,” omits Reset from the enumeration, and says every listed writer row-locks and re-reads (`:1165`). That also contradicts the settled store exception at `:1061`.

Change the restatement to thirteen rows and explicitly retain the store-path exception.

### m-2 — PostgreSQL advisory locks do not live in the dropped schema

The required Reset timing is settled and should remain unchanged, but the explanation says both “the advisory lock and the roles rows it protects live in the schema being dropped” (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1100`; repeated at `:2492`).

The lane actually obtains a database/session-level PostgreSQL advisory transaction lock with `pg_advisory_xact_lock(hashtextextended(...))` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:131-135`). Only the role rows are schema objects. Replace the rationale with the real operational reason the lock is limited to the post-recreation assignment transaction.

### m-3 — Provisioning-time marker ownership is inconsistent

The command contract owns marker output (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:982-991`), and the service contract exposes only a result-returning `sync()` (`:1008-1024`). Provisioning calls that service directly (`:1286`), yet the fleet discussion says `PermissionSyncService::sync()` itself emits the durable `PERMISSIONS-SYNC` marker (`:1270`).

Name the component that logs a direct provisioning result—either the service or the provisioning caller—and add the completed-provisioning marker assertion. Do not add another sync trigger.

### m-4 — One historical file citation is not valid at HEAD

Spec `:2352,2359` cites `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json:1` without a branch qualifier. That path is absent at HEAD. The real citation is:

`lane/t2-receipt-spine:apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json:1`.

The historical branch conclusion is correct; only the path qualification is stale.

## Citation audit

I checked 295 unique fully qualified local `path:line` claims, plus the contextual shorthand ranges and all rev-8 additions. All resolve to the claimed code except the single unqualified lane fixture above.

| Claim | Result |
|---|---|
| Application tree remains the declared base | **VERIFIED.** `971528977..03d36a8b9` is empty across application/shared paths; spec records the base at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:7-21`. |
| Boot migration can fail while boot continues | **VERIFIED** at `apps/api/docker/entrypoint.sh:148-154`. |
| Provisioning writes directory first, database/migration/init later | **VERIFIED** at `apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:86-107,145-150,222-224`. |
| Reset production guard, force path, schema recreation and assignment | **VERIFIED** at `apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:21-35,59-95`. |
| Store creates then grants | **VERIFIED** at `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:191,197`. |
| W-LOT team set/restore and NULL-or-tenant resolution | **VERIFIED** at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-56,96-100,111-128`. |
| W-LOT advisory key and row order | **VERIFIED** at the same lane file `:111-116,131-135`. |
| W-LOT delta is additive except manager recall | **VERIFIED** at the same lane file `:163-181`. |
| `provisioning_source` type/CHECK/partial unique | **VERIFIED** at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:25-75`. |
| Current token overrides | **VERIFIED** at `apps/api/app/Modules/Identity/Domain/User.php:56-59,185-241`. |
| Spatie permission Gate registration enabled | **VERIFIED** at `apps/api/config/permission.php:103-107`; pinned package is 6.25.0 at `apps/api/composer.lock:7747-7758`. |
| AuthUserData and POS use `getAllPermissions()` | **VERIFIED** at `apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php:35-55` and `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100,205-225`. |
| Eight backend role-name authorization sites | **VERIFIED** at `CreateDraftCountingRequest.php:35`, `InventoryCountingController.php:782,866,905,975,1297,1494`, and `DiscountPermissionResolver.php:39`. |
| Ratchet `326 / 177 / 149` | **VERIFIED** from the CSV: 142 `AUTH_ONLY` + 130 `CONTROLLER` + 46 `FORMREQUEST` + 8 `POLICY`; writes are rows whose method contains POST/PUT/PATCH/DELETE. |
| PHPStan level 8 and nine rule registrations | **VERIFIED** at `apps/api/phpstan.neon:5-8,33-47`; AST precedent at `apps/api/app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php:40-90`. |
| Token abilities, `expires_at`, central storage and TTL precedence | **VERIFIED** at `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`, `CentralPersonalAccessToken.php:11-41`, `AppServiceProvider.php:196-219`, and `config/sanctum.php:43-53`. |
| Central DB default is product-neutral | **VERIFIED**: `autoerp_central`, not `synerivia_central`, at `apps/api/config/database.php:124-135`. |
| Existing last-admin removal paths | **VERIFIED** at `RoleController.php:228-298,350-410` and `UserController.php:357-391,470-484,630-642,988-998`. |
| Lane overlap figures | **VERIFIED**: W-LOT 81 files/4390 additions/646 deletions; T1 empty; T2 86 files/5979 additions/423 deletions. |
| Unqualified transfer-reader fixture at HEAD | **WRONG** at spec `:2352,2359`; real location is `lane/t2-receipt-spine:apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json:1`. |

## Rejected false positives

### Token narrowing and bypass matrix

The principal-grant ∩ token-scope claim is correct for permission-based checks, not for role-name checks:

| Idiom | Result |
|---|---|
| `can:` route middleware | **Narrowed.** Laravel authorization reaches the enabled Spatie Gate callback, whose `checkPermissionTo()` dynamically reaches the model override. Registration is enabled at `apps/api/config/permission.php:103-107`; override at `User.php:185-198`. |
| `$user->can()` / `cant()` | **Narrowed** through the same Gate path for permission abilities. |
| `Gate::authorize()` / `allows()` / `denies()` | **Narrowed when the ability is a dotted permission.** Policy ability names fall through to the policy mechanism; policies are registered at `apps/api/app/Providers/AppServiceProvider.php:270-276`. |
| `hasPermissionTo()` | **Narrowed directly** at `User.php:185-198`. |
| `hasAnyPermission()` | **Narrowed** because Spatie checks each candidate through `checkPermissionTo()`. |
| `getAllPermissions()` | **Narrowed only for the authenticated subject carrying `currentAccessToken()`**, at `User.php:201-241`. `/auth/me` uses that subject (`AuthUserData.php:35-55`). A freshly loaded target has no current token; POS PIN payloads are intentionally target-grant payloads and remain unnarrowed (`PosAuthController.php:87-100,205-225`). |
| `hasRole()` family | **Bypasses narrowing.** Eight backend sites remain and conversion is correctly an issuance prerequisite. |
| Spatie `role:` middleware | **Absent.** The audit records zero uses at `01-backend-permission-model.md:81-84`. `central_admin_role` is a separate central guard registered at `apps/api/bootstrap/app.php:114-123`. |

### W-LOT coexistence

No collision was found:

- `provisioning_source` remains a distinct lane marker with its own CHECK and partial unique.
- `template_key`, `template_version`, `is_system`, and `customised_at` describe catalogue adoption and do not overwrite that marker.
- The NULL-team ruling is respected: existing NULL-team roles remain NULL; `general_manager` remains tenant-team-scoped.
- Both sync and ensure use the lane’s NULL-or-tenant lookup and collision refusals.
- `admin = PermissionRegistry::activeKeys()` intentionally supersedes the old `Permission::all()` grant and remains consistent with A-1a’s requirement that admin hold the deployed catalogue.
- The marker-aware seeder can coexist with sync because wave 0b uses ensure and wave 1 turns the seeder into a registry adapter.

### Ratchet

Recomputed result:

- uncovered: **326**
- writes: **177**
- reads: **149**
- after four tombstone writes, six self-service writes and `/auth/me`: **167 / 148**
- after 0a’s fifteen writes and six reads: **152 / 142**

The classification is mechanically checkable in PHPUnit by booting the live router. Merely attaching `authz.self` cannot whitelist a route: classification consults an exact `(method, uri)` allow-list and the shape test (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1432-1438`). A developer could deliberately edit the protected allow-list, but cannot accidentally escape by adding middleware alone.

### Principals, Sanctum and data model

No unmentioned existing `users` writer remains. The census covers:

- registration and tenant provisioning;
- all UserController create/update/status/delete/restore/bulk/PIN paths;
- login metadata;
- POS self-PIN and raw PIN sync;
- password reset and email verification;
- central email verification;
- the discount migration;
- every Database/Demo/CoffeeShop/Parapharmacy/DemoPharmacy seeder create/update path.

The critical reuse constraints are covered: nullable tenant-unique email (`make_user_email_nullable.php:14-23`), nullable service password, four-value `UserStatus`, login/PIN ordering, `pinHolders()` exclusion, notification selectors, six seat counters, membership/location grants, `users.manage_location_access`, form requests, and the fact that `TenantInitializationService` creates no user (`TenantInitializationService.php:183-216`).

Sanctum’s abilities are nullable TEXT and `expires_at` is indexed (`create_personal_access_tokens_table.php:14-22`). The global 30-day TTL and per-row override are correctly described (`config/sanctum.php:43-53`; `AppServiceProvider.php:201-219`). Tokens are central, via `CentralPersonalAccessToken`, and membership-removal revocation is feasibly best-effort across the tenant/central connection boundary. `EnforceTokenScope` is correctly ordered after `EnforceTokenTenantClaim`; the existing order is visible at `apps/api/bootstrap/app.php:174-188`.

The two proposed tenant migrations are additive. Every new column has type, nullability, default, index, FK and enum ownership stated at spec `:1943-1983`. The PG CHECKs are valid; `status` is a varchar, not a native enum (`create_users_table.php:22-23`), so no enum-typed ALTER is required. There is no data backfill beyond defaults and sync.

### Static guards, hardening and conventions

The permission-literal PHPStan rule is implementable at level 8 through the existing AST-rule pattern, but **not without a mass shrink-only baseline**. Rev 8 says so explicitly at spec `:1508`; the earlier claim was withdrawn. Enum↔manifest parity, three-locale label coverage (`:1514-1518`) and generated TypeScript union (`:1520-1530`) are coherent with rule 7.

Last-admin coverage is complete: role removal, user-role replacement, deactivate, destroy, membership removal, system-role deletion, admin permission replacement, impersonated calls, and sync are all covered at spec `:1562-1576`. Effective-permission reads are self-or-`roles.view`, with token ownership returning 404 (`:1582-1600,1715-1718`). Role mutation and denial events use the audit chain; `RoleSyncedV1` has a versioned persisted name (`:1644-1676`).

Convention 09’s second-company, second-location, idempotency and tenant-only-unique obligations are stated per wave at spec `:2006-2029`. Convention 10’s G1–G16 matrix carries MATCH/DEFER/DIVERGE/ALREADY dispositions and real code anchors. Convention 11 uses one table, one write path and one operator surface; the General-manager row preserves the lane wording at `lane/w-lot-a-1a:docs/glossary.md:21,91`.

The 55 existing edge rows have decided expected outcomes, and PG-only concurrency/CHECK/Redis tests are not assigned to SQLite. Three missing tests follow from this register:

1. dry-run discovers `BLOCKED` and exits non-zero;
2. explicit tenant selector is unmatched/ambiguous and exits non-zero;
3. completed direct provisioning emits its named durable sync marker.

### Waves and entrypoint

The no-overlap conclusion remains valid after checking the omitted 0a route paths. Its sole accepted overlap remains `apps/api/tests/feature-lane-manifest.json`.

0b-6 ordering is correct: `credit-notes.cancel` is declared first, then `PermissionSeeder.php` and `ProductionSeeder.php:75` are removed. Current caller ordering is confirmed at `apps/api/database/seeders/ProductionSeeder.php:68-75`.

The staging auto-deploy entrypoint is safe for the normal all-tenant wave-1 invocation once the runner exists: migrations remain allowed to fail without taking down the container, but sync records a failed status and the authenticated admin health endpoint becomes unhealthy. The dry-run and selector defects do not affect that default no-options boot command, but they do affect operator remediation and CI previews. Wave 2’s singular command is independently wrong as recorded in M-3.

## Preserve

The fix round must not change:

- Direction B and settled D1–D8.
- Tenant-scoped roles and membership-carried company/location scope.
- Legacy NULL-team roles: no re-homing.
- A-1a’s `provisioning_source` marker, CHECK, default-off preservation branch, advisory key and role lock order.
- Separate `matchesPreWave0b()` and `matchesVersion0()` predicates.
- Exact template deltas for pristine system roles; no automatic template writes to custom or customised roles.
- `admin = PermissionRegistry::activeKeys()`.
- Exactly two automatic sync triggers: provisioning and the flag-controlled fleet deploy; no migration listener.
- Readiness failures as `FAILED`, `reason=tenant_not_ready`, non-zero exit.
- `provisioned_during_run` as a pure population gauge.
- One constructor-injected `TenantFleetRunner` shared by sync-fleet and ensure-fleet.
- Ensure’s team boundary, NULL-or-tenant predicate and explicit array option forwarding.
- Reset’s assignment under the lock after schema recreation; schema-drop concurrency remains out of scope.
- Store ordering: advisory lock → insert → grants, without `FOR UPDATE`.
- EC-20c’s two correct acquisition-order outcomes.
- Ratchet figures `326 / 177 / 149`, generated ceilings `167 / 148`, and post-0a ceilings `152 / 142`.
- First-class human/service principals and effective permissions = owner grants ∩ token scope.
- Subject/target permission resolution and the intentionally unnarrowed POS PIN-holder payload.
- Human-only last-admin floor pending OQ-1.
- Central Sanctum token storage and per-row `expires_at` precedence.
- `roles.view` read shaping and self-or-`roles.view` effective-permission access.
- Immutable/versioned audit-chain events.
- Two additive tenant migrations, generated TypeScript union and en/fr/ar label coverage.
- The six start-now wave-0a items and the one accepted manifest overlap.

## Owner decisions required

Only the four permitted questions remain:

| Question | Status |
|---|---|
| OQ-1 — do service accounts count toward the last-admin floor? | Genuinely open product/recovery policy. The current design consistently applies the recommended human-only answer. |
| OQ-2 — default service-token TTL and related human-token policy | Genuinely open. Current code has a 30-day global TTL and explicit POS one-year expiry. |
| OQ-3 — retire any baselined SoD combinations? | Genuinely open role-policy decision. Code establishes the current combinations but cannot choose the desired default. |
| OQ-4 — default `SYNC_PERMISSIONS_ON_BOOT=true` after soak? | Genuinely open deployment policy. The live all-tenant preconditions are now specified, but the fleet dry-run/selector and wave-2 runbook defects above must be corrected before the operational contract is considered complete. |

No additional owner question is required. Every new finding is an engineering/spec-consistency correction under settled rulings.

VERDICT: CHANGES-REQUIRED