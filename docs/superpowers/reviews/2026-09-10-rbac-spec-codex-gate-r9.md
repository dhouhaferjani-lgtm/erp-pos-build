# Codex spec gate r9 — roles & permissions catalogue design rev 9 (gpt-5.6-sol, high, read-only, 2026-09-10)

## Rev-1 base SHA

Read at **`c08a3db21`**.

The spec’s declared application-code base remains `971528977`: `git diff --quiet 971528977..c08a3db21 -- apps/api apps/web apps/pos packages/shared` returned zero. `apps/api/vendor/` is absent, so Artisan could not boot; route arithmetic was recomputed from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv`.

Current refs:

| Ref | Current SHA | Current `dev...lane` diff |
|---|---:|---:|
| `dev` | `5dbb7e1ee` | — |
| `lane/w-lot-a-1a` | `e66ab5823` | 81 files, 4,578 insertions, 663 deletions |
| `lane/t1-transfers-edge` | `86273346a` | empty |
| `lane/t2-receipt-spine` | `ede6a990a` | 99 files, 6,314 insertions, 495 deletions |

## Rev-8 closure table

| Rev-8 finding | Rev-9 disposition |
|---|---|
| M-1 — dry-run made blocked fleets green | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1296-1297,2119`. Dry-run is write-free but retains the ordinary non-zero failure rule. |
| M-2 — tenant selector semantics absent | **CLOSED at the behavioural anchor**, `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1275-1289,2120`: start-snapshot resolution, id-or-slug matching, exactly-one result, duplicate collapse, and unmatched/ambiguous failure are settled. The proposed snapshot primitive and ambiguous fixture need the minor correction below. |
| M-3 — wave 2 used per-tenant sync | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2246-2250`. Wave 2 uses `permissions:sync-fleet`; the ordering invariant is guarded. |
| M-4 — incomplete 0a touch map | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2176-2192`: Notification and SupportAccess routes, both architecture tests, and the named liveness fixture are present. |
| m-1 — twelve/ thirteen LOCKED-writer contradiction | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1167`: thirteen rows, twelve row-locking writers, and the create-path exception are explicit. |
| m-2 — advisory lock described as living in the schema | **CLOSED**. The corrected rationale matches the database-level advisory lock at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:131-135`. |
| m-3 — provisioning marker ownership inconsistent | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1293,1309-1311,2121`: `TenantInitializationService` owns rendering; the service only returns a result. |
| m-4 / citation — transfer fixture incorrectly unqualified | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2387,2394,2553`. The fixture remains at `lane/t2-receipt-spine:apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json:1`. |
| Rev-8 change-log claims | **CLOSED except for subsequent moving-lane drift.** The substantive closures at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2545-2553` match the code. The lane-tip assertion at `:2555` is now stale; see minor m-2. |

## BLOCKER

None.

## MAJOR

None.

## MINOR

### m-1 — EC-39’s duplicate-slug fixture is impossible, and “two-column pluck” is not a usable snapshot

The selector outcome is decided correctly, but its proposed mechanical form conflicts with the schema:

- The spec calls the snapshot a “two-column `pluck`” and says slugs are not globally unique at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1281,1285`.
- `tenants.slug` is globally unique at `apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:16-20`.
- Laravel `pluck('id', 'slug')` would also collapse duplicate keys rather than preserve an ambiguous match set.
- EC-39 says “Four cases” but enumerates five, and case (e) requires two equal slugs at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2120`.

Plan-writing correction: snapshot with `get(['id', 'slug'])`. Retain ambiguous-selector handling, but test the reachable cross-column collision—a selector equal to tenant A’s UUID and tenant B’s unique slug—rather than violating the slug unique constraint. This does not reopen EC-39’s settled outcome.

### m-2 — Rev-9 lane pins and W-LOT seeder citations have already drifted

The spec pins W-LOT at `f8005243d` and T2 at `bd91f8de5` at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:23,27-34,2176,2201,2555`. Current tips are `e66ab5823` and `ede6a990a`.

The functional conclusions remain valid:

- T1 is still empty.
- Rechecking every 0a path against both active lanes found no overlap except the settled `apps/api/tests/feature-lane-manifest.json`.
- W-LOT’s later seeder change only made marker output null-safe; it did not change the delta, marker, role-grant, or provisioning-source semantics.

The document nevertheless claims its citations describe the current tips. W-LOT’s seeder moved and all its unpinned line coordinates must be refreshed; see the citation table. The historical W-LOT controller citation `:237-264` should be `:223-265`. The rev-9 change log also says “five line numbers” before listing six coordinates at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2555`.

### m-3 — Deploy-sequence enforcement should consume a machine-readable runbook

`PermissionDeploySequenceTest` is required to parse this spec’s Markdown wave prose and future deploy-runbook prose at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2250`.

The invariant is correct and should remain. Parsing prose is brittle: wording or Markdown layout can break the build without changing deployment, while semantically equivalent prose can evade simplistic parsing. During plan writing, put the authoritative sequence in a small versioned YAML/JSON runbook and have both the test and human-facing documentation consume or validate that source. This is a maintainability recommendation, not an acceptance blocker.

## Citation audit

All HEAD application citations were checked against `c08a3db21`; all lane-qualified citations were checked against current lane tips. These are the wrong or stale claims found:

| Claim | Result |
|---|---|
| Rev-9 lane table and “current tips” assertions at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:23,27-34,2176,2201,2555` | **WRONG/STale.** W-LOT is now `e66ab5823`, 81/4,578/663; T2 is now `ede6a990a`, 99/6,314/495. T1 remains `86273346a`, empty. |
| W-LOT seeder enforcement branch `:42-49`, call `:43` | **STALE.** Real lines are `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:43-50`, call at `:44`. |
| Preservation branch `:51-54` | **STALE.** Real lines are `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:52-55`. |
| Legacy branch `:55-58` | **STALE.** Real lines are `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:56-59`. |
| Marker predicate `:71-76` | **STALE.** Real lines are `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:72-77`. |
| `permissionNames()` `:86-90` | **STALE.** Real method is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:90-94`, with the returned list at `:93`. |
| `rolePermissionGrants()` `:93-104`; manager/general-manager `:97-98`; general-manager `:98` | **STALE.** Real method is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:97-108`; manager/general-manager are `:101-102`, general-manager `:102`. |
| `uom.edit` `:268` | **STALE.** Real line is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:272`. |
| `deliveries.edit` `:288` | **STALE.** Real line is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:292`. |
| `pos_held_orders.*` `:495-497` | **STALE.** Real lines are `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:499-501`. |
| `catalog_cart.*` `:556-561` | **STALE.** Real lines are `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:560-565`. |
| `createLegacyRoles()` `:640-648` | **STALE.** Real method begins at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:644`; role creation and destructive legacy sync are `:647-648`, with the method through `:652`. |
| W-LOT operator update `RoleController.php:237-264` at spec `:2503` | **STALE/INCOMPLETE.** The current update method is `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223-265`; `syncPermissions()` is at `:265`. |
| Re-pinned W-LOT role writes `:213,221,:265,:312,:385,:430` at spec `:1071,2217,2555` | **VERIFIED** at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:213,221,265,312,385,430`. That file did not move after `f8005243d`. |
| W-LOT provisioning-source schema | **VERIFIED** at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:25-75`: nullable `varchar(32)`, validation, PG CHECK, SQLite triggers, and partial unique. |
| W-LOT team setup, NULL-or-tenant lookup, restoration, lock and delta | **VERIFIED** at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-56,96-100,111-135,163-181`. |
| “Slug is not globally unique” at spec `:1285` | **WRONG.** `apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:19` declares `slug` unique. |
| T2 transfer fixture | **VERIFIED** at `lane/t2-receipt-spine:apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json:1`; it remains absent at HEAD. |
| ProductionSeeder deletion ordering | **VERIFIED.** `RolesAndPermissionsSeeder` runs at `apps/api/database/seeders/ProductionSeeder.php:68-71`; the legacy `PermissionSeeder` call is `:73-75`. Spec 0b-1 precedes 0b-6 at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2213`. |
| All remaining HEAD `path:line` claims | **VERIFIED.** The application tree is byte-identical to the declared base; no additional stale HEAD citation was found. |

## Rejected false positives

### Token narrowing and bypass matrix

The core claim is correct, with the role-name exception already acknowledged by the spec.

| Idiom | Result |
|---|---|
| `can:` route middleware | **NARROWED.** Laravel authorization reaches Gate; Spatie registers its permission `Gate::before` because `register_permission_check_method` is enabled at `apps/api/config/permission.php:103-107`. Spatie’s pinned 6.25 path calls `checkPermissionTo()`, which dynamically reaches the overridden `User::hasPermissionTo()` at `apps/api/app/Modules/Identity/Domain/User.php:185-198`. |
| `$user->can()` | **NARROWED** through the same Gate path. |
| `Gate::authorize()` | **NARROWED** for permission abilities through the same Gate path; a current example is `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:64-69`. Policy abilities remain ordinary policy decisions. |
| Direct `hasPermissionTo()` | **NARROWED** by `apps/api/app/Modules/Identity/Domain/User.php:185-198`. |
| `hasAnyPermission()` | **NARROWED.** Spatie 6.25 iterates candidates through `checkPermissionTo()`, reaching the override. The package pin is `apps/api/composer.lock:7747-7758`. |
| `getAllPermissions()` | **NARROWED only for the authenticated subject carrying the current token**, by `apps/api/app/Modules/Identity/Domain/User.php:201-241`. `/auth/me` therefore narrows at `apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php:43`. Freshly loaded targets intentionally do not inherit the caller’s token scope. |
| POS PIN payload | **INTENTIONALLY UNNARROWED TARGET resolution.** PIN holders are newly loaded at `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-99,206-225`; their permissions represent that operator, not the terminal credential. Service principals are excluded by the planned `pinHolders()` human predicate at spec `:1960-1962`. |
| `hasRole()` / role-family checks | **BYPASS narrowing.** Eight live authorization sites remain: `apps/api/app/Modules/Inventory/Presentation/Requests/CreateDraftCountingRequest.php:35`; `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:782,866,905,975,1297,1494`; `apps/api/app/Modules/POS/Domain/Services/DiscountPermissionResolver.php:39`. Wave 2b token issuance is correctly blocked until 2a converts them at spec `:2239-2242`. |
| Spatie `role:` middleware | **ABSENT.** No application route uses it. The `central_admin_role` middleware is a separate central-operator mechanism. |

Thus wiring generalized token-scope extraction into `User::hasPermissionTo()` and `getAllPermissions()` does make every permission idiom inherit narrowing. It does not and cannot narrow role-name authorization; the spec correctly treats those eight sites as a wave-2 entry condition.

### Sync versus W-LOT-A-1a

No collision was found.

- `provisioning_source` is an independent nullable marker with its own CHECK and partial unique at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:25-75`.
- `is_system`, `template_key`, `template_version`, and `customised_at` have separate meanings and do not overwrite it; the proposed schema and checks are explicit at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1967-2028`.
- The NULL-team ruling is preserved. W-LOT already resolves NULL-or-current-tenant roles without re-homing them at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:104-128`; sync adopts that predicate.
- The delta and sync do not coexist as competing catalogue engines: W-LOT deploys first, sync never invokes the delta, and wave 1 replaces the seeder body.
- `admin = PermissionRegistry::activeKeys()` intentionally replaces legacy `Permission::all()`. The old destructive behavior is at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:647-648`; A-1a does not require future orphan/deprecated rows to remain granted.

### Ratchet

From the CSV, under the exact rule that only route middleware counts as enforced and `AUTH_ONLY`, `CONTROLLER`, `FORMREQUEST`, and `POLICY` remain uncovered:

- `142 + 130 + 46 + 8 = 326` uncovered.
- Method classification gives **177 writes / 149 reads**.
- Removing four tombstone writes, six approved self-service writes, and `/auth/me` gives **167 / 148**.
- Wave 0a then closes fifteen writes and six reads, producing **152 / 142**, matching `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1477-1488,2205`.

This is mechanically checkable in PHPUnit by booting the live router and comparing normalized method/URI pairs.

`authz.self` is not self-certifying: adding the alias alone cannot whitelist a route. The exact allow-list and route-shape tests required at spec `:1458-1465,2191-2192` must also accept the method/URI and prove the route is truly self-only. A deliberate allow-list edit remains reviewable, as any allow-list edit must.

### Principals, Sanctum and data model

No omitted `users` writer was found beyond the spec’s census at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:244-269`. It includes registration, provisioning, all generic-user mutations, both POS PIN writers, login metadata, email verification, password reset, the central verification writer, the discount migration, and all seeders. `TenantInitializationService` is not itself a `users` writer; it assigns the already-created user’s role at `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:183-192`.

The reuse constraints are covered:

- Existing password is non-null at `apps/api/database/migrations/tenant/2025_11_30_000003_create_users_table.php:22`; the proposed migration explicitly makes it nullable.
- Email is already nullable with a tenant-scoped partial unique at `apps/api/database/migrations/tenant/2026_03_23_000001_make_user_email_nullable.php:14-23`.
- `UserStatus` has four values at `apps/api/app/Modules/Identity/Domain/Enums/UserStatus.php:10-15`; the proposed service CHECK restricts services to active/inactive.
- Login checks, PIN writers, `pinHolders()`, notifications, six seat counters, memberships/location access, `users.manage_location_access`, and dedicated FormRequests are all assigned concrete changes in §§4.1 and 5.
- The last-admin set is explicitly Human-only pending OQ-1 at spec `:1604`.

Sanctum claims are correct:

- `abilities` is nullable TEXT and `expires_at` is indexed at `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`.
- Default expiration is 43,200 minutes at `apps/api/config/sanctum.php:43-53`.
- Per-row `expires_at` takes precedence through `apps/api/app/Providers/AppServiceProvider.php:196-219`.
- Tokens are central through `apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:11-41`; the default database name is `autoerp_central`, not `synerivia_central`, at `apps/api/config/database.php:124-135`.
- Existing ordering places `EnforceTokenTenantClaim` after authentication/team selection at `apps/api/bootstrap/app.php:174-188`; inserting `EnforceTokenScope` after the tenant claim is feasible.
- Membership and token deletion cannot be atomic across tenant and central connections; the specified retry job plus request-time membership refusal is the feasible design.

Every proposed column has type, nullability, default, index/FK and enum ownership where applicable at spec `:1971-1978,2001-2008`. Both migrations are additive and tenant-scoped. The PG CHECK expressions are valid against varchar columns; no PostgreSQL enum-typed column is altered. Existing rows acquire `principal_kind='human'` through the column default, not an explicit backfill.

### Static guards, hardening, conventions and waves

- PHPStan already runs at level 8 and registers custom rules at `apps/api/phpstan.neon:5-8,33-47`; the AST precedent is `apps/api/app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php:40-90`. A literal-permission rule with a shrink-only exception baseline is implementable without mass suppression.
- Enum⊆manifest, generated TypeScript union, and en/fr/ar label coverage are coherent at spec `:1534-1556`.
- The last-admin writer census covers role removal, user update/destroy/deactivate, membership removal, system-role deletion, admin permission-floor edits, sync/template application and impersonation. Existing mutation sites include `RoleController.php:223-298,350-398`, `UserController.php:303-484,580-642`, and `ResetTenantCommand.php:89-95`; the complete nine-path design is at spec `:1588-1604`.
- Effective-permission reads are self-or-`roles.view`; `users.assign-roles` receives names but not permission matrices at spec `:1614-1624`.
- Role mutation and denial events use the existing immutable audit chain, with `DomainEvent` subclasses and versioned event names at spec `:1630-1712`.
- Convention 09’s second-company, second-location and rerun obligations are populated per wave; roles and permissions retain tenant-correct uniqueness at spec `:2032-2055`.
- Convention 10 rows carry code anchors and `MATCH`/`DEFER`/`DIVERGE`/`ALREADY` dispositions. Convention 11 uses one table, one write path and one operator surface per term. The General-manager row remains verbatim from `lane/w-lot-a-1a:docs/glossary.md:21,91`.
- The edge register contains decided outcomes and assigns PG-only behavior to PG tests. No new scenario is missing. EC-39’s ambiguous case should use the reachable id/slug cross-column collision described above.
- Deleting `PermissionSeeder` in 0b remains correctly ordered after `credit-notes.cancel` and includes its production caller plus test dependencies at spec `:1937-1951,2213`.
- Replacing `SYNC_PERMISSIONS_ON_BOOT` with `permissions:sync-fleet` is safe for staging auto-deploy after the lane’s five-push protocol: rolling migration remains first, sync failures leave the container up but write unhealthy status, and successful reruns clear it. Current ordering is visible at `apps/api/docker/entrypoint.sh:148-176`; the replacement contract is at spec `:1296-1315,2229-2233`.

Wave 0a is dispatchable today, with the one settled manifest overlap:

| Item | Dispatchable files |
|---|---|
| 0a-1 | `apps/api/app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php`; `RestoreCentralPermissionCache.php`; `apps/api/app/Providers/TenancyServiceProvider.php`; `apps/api/tests/Traits/ProvisionsTenantDatabases.php`; `apps/api/tests/Feature/Identity/PermissionCacheTenantScopingTest.php`; `apps/api/tests/feature-lane-manifest.json` |
| 0a-2 | `apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php`; `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json`; `apps/api/tests/Architecture/fixtures/route-permission-coverage-liveness.json` |
| 0a-3 | `apps/api/app/Http/Middleware/AllowSelfService.php`; `apps/api/bootstrap/app.php`; Identity, Notification and SupportAccess route files; `SelfServiceRouteAllowListTest.php`; `SelfServiceRouteShapeTest.php` |
| 0a-4 | `apps/api/app/Modules/Identity/routes.php`; route-coverage baseline |
| 0a-5 | Promotion, Uom, Menu and Coupon route files; route-coverage baseline |
| 0a-8 | `docs/conventions/03-AUTHORIZATION.md`; `.claude/commands/add-permissions.md` |

The authoritative list and sequencing are at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2176-2207`.

## Preserve

The plan-writing round must preserve:

- Direction B and settled D1–D8.
- Tenant-scoped roles and membership-carried company/location scope.
- Legacy NULL-team roles without re-homing.
- W-LOT’s `provisioning_source`, CHECK, default-off preservation path, advisory-lock key, and deterministic role-lock order.
- Separate `matchesPreWave0b()` and `matchesVersion0()` predicates.
- Exact template deltas only for pristine system roles; no automatic template grants to custom or customised roles.
- `admin = PermissionRegistry::activeKeys()`.
- Exactly two sync triggers: provisioning and flagged deploy fleet sync.
- Dry-run write-free but failure-preserving.
- The settled TenantFleetRunner selector behavior, correcting only its collection primitive and test fixture.
- Human/service first-class principals with effective permissions equal to owner grants intersected with token scope.
- Human-only last-admin default pending OQ-1.
- Central Sanctum storage and per-row TTL precedence.
- `roles.view` read shaping.
- Immutable, versioned audit events on `audit_events`.
- Two additive tenant migrations, generated TypeScript permissions, and en/fr/ar label coverage.
- All six start-now 0a tasks and the single accepted manifest overlap.

## Owner decisions required

Only the existing four questions remain genuinely open; none is answerable from code or the settled rulings:

1. **OQ-1:** Whether service accounts count toward the last-admin floor. The applied default is Human-only (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2307-2311`).
2. **OQ-2:** Default service-token TTL, against today’s actual 30-day global policy and per-row override (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2313-2317`).
3. **OQ-3:** Which, if any, of the eleven existing SoD baseline combinations should be retired (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2319-2322`).
4. **OQ-4:** Whether `SYNC_PERMISSIONS_ON_BOOT` becomes production-default true after the wave-1 soak (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2324-2327`).

No additional owner question is required. The three remaining findings are plan-writing/editorial corrections under settled behavior.

VERDICT: ACCEPT