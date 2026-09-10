# Codex spec gate r6 — roles & permissions catalogue design rev 6 (gpt-5.6-sol, high, read-only, 2026-09-10)

## Rev-1 base SHA

Read at **`5309e1822`** on `docs/rbac-audit-2026-09-09`.

The declared application base remains reproducible: `git diff 971528977..5309e1822 -- apps/api apps/web apps/pos packages/shared` is empty. `apps/api/vendor/autoload.php` is absent, so Artisan could not boot; route arithmetic was recomputed from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv`.

Current refs:

| Ref | SHA | `git diff --shortstat dev...ref` |
|---|---:|---|
| `dev` | `aefaf6724` | — |
| `lane/w-lot-a-1a` | `2fa724c1d` | 81 files, 4,390 insertions, 646 deletions |
| `lane/t1-transfers-edge` | `86273346a` | empty |
| `lane/t2-receipt-spine` | `93b106461` | 86 files, 5,979 insertions, 423 deletions |

## Rev-5 closure table

| Rev-5 finding | Rev-6 disposition |
|---|---|
| B-1 `matchesVersion0()` made first wave-0b grant unreachable | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1257-1268`: `matchesPreWave0b()` is distinct, eligibility is snapshotted once per role, and additions are batched. EC-32c retains stale wording; see minor m-1. |
| B-2 exact template replacement raced operator edits | **NOT CLOSED.** The mechanism is correct at `:1028-1062`, but wave 0b ships only the lock/ensure side at `:2080-2084`; operator wiring is deferred to wave 1 at `:2096`. See B-1. |
| M-1 rename normalisation invariant unpinned | **CLOSED** at `:877-885`: `rename_targets_are_not_sources` proves source/target disjointness and single-pass canonicalisation. |
| M-2 read ceiling should be 142 | **CLOSED** at `:1354-1357,2058-2061,2072`. Recomputed result is 142. |
| M-3 G3 reintroduced migration-triggered sync | **CLOSED** at `:76`: provisioning plus flagged fleet sync; rolling migration is explicitly not a trigger. |
| m-1 `RoleSyncedV1` not versioned end to end | **CLOSED** at `:1544-1565,1987`: class and persisted name are `.v1`. |
| m-2 wave-0a prose contradicted accepted manifest overlap | **CLOSED** at `:2043-2047,2068-2072`. |
| m-3 `reports.view` ordinal | **CLOSED** at `:1777-1801`: entry 22 and last. |
| m-4 impossible Redis interleaving | **CLOSED** at `:1604-1608`. |
| m-5 convention-09 prescribed the rejected seeder path | **CLOSED** at `:1921`: only `permissions:ensure` is asserted. |
| m-6 “all 917 call sites” overclaim | **CLOSED** at `:2162`: restricted to the 917 counted permission-based Gate/`can` sites, with eight role-name sites excluded. |
| m-7 provisioning during fleet sync omitted | **NOT CLOSED.** EC-37 exists, but its directory-snapshot/gauge contract cannot observe all tenants it promises to count. See M-1. |
| Citation audit | **PARTIALLY CLOSED.** Rev-5 corrections are present, but `dev` moved and rev-6 adds two internally false claims; see Citation audit. |
| Rev-5 rejected false positives | **REJECTED-correctly.** The token matrix, ratchet mechanics, W-LOT coexistence fundamentals, principal model, Sanctum topology, static guards, hardening and convention findings remain rejected except where explicitly reopened below by new rev-6 text. |

## BLOCKER

### B-1 — Wave 0b does not share the lock with the operator or incoming W-LOT writer

Rev 6 says `permissions:ensure` must serialize against operator edits “from the day it ships” and therefore ships `PermissionWriteLock` in wave 0b (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2080`). But the wave-0b file list contains only the command, baseline, fixture and lock helper (`:2084`). Wiring `RoleController::update` into that lock is deferred to wave 1 (`:2096`), together with the `customised_at` column and writer.

The incoming controller still performs the grant replacement with neither transaction nor shared lock at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:237-264`. Therefore EC-20b’s asserted shared-lock outcome at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1964` is false during wave 0b:

1. `ensure` reads a pristine manager under its advisory lock.
2. The unlocked controller writes an operator-selected set.
3. `ensure` grants its snapshotted batch afterwards.
4. The operator’s selected set is widened, with no `customised_at` column available to record or repair it.

There is a second coexisting writer. Until wave 1 replaces the seeder, staging boot may still invoke it under `SYNC_PERMISSIONS_ON_BOOT` (`apps/api/docker/entrypoint.sh:156-170`). Its enforced branch calls `LotActionPermissionDelta` at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:42-49`. That service:

- locks a different advisory namespace, `wlota1a:<tenant>` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:131-135`), rather than rev 6’s `permissions:<tenant>` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1033-1044`);
- locks roles by `name,id` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:111-116`), while rev 6 mandates `id ASC` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1048`).

For `manager` and `general_manager`, those orders can be opposite, so the alleged single serialization domain does not exist and a deadlock is possible.

Required correction within the settled ruling: wave 0b must wire every coexisting operator/seeder/delta writer to the same lock and row order before `permissions:ensure` is deployable. Add a PG case for W-LOT seeder/delta versus ensure; EC-20b alone does not cover that boundary.

### B-2 — “Every production pivot writer—the seven above” is false

The lock census states that every production method writing `role_has_permissions` or `model_has_roles` is one of seven listed categories and must reach `PermissionWriteLock::acquire()` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1050-1060`). Current code contains additional direct writers:

| Omitted writer | Real line |
|---|---|
| Custom-role creation plus initial permissions | `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:187-198`; incoming lane `:207-220` |
| Human-user creation plus initial role | `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:217-235` |
| Registration’s default-admin assignment | `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:183-192` |
| Tenant reset’s admin assignments | `apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:89-94` |
| Development/bootstrap seed assignments | `apps/api/database/seeders/DatabaseSeeder.php:326,364`; `DemoTenantSeeder.php:589,2097`; `CoffeeShopSeeder.php:1273,1302`; `ParapharmacySeeder.php:1445,1480,1516`; `DemoPharmacySeeder.php:994,1061` |
| Current catalogue/legacy seed writes | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:568`; `PermissionSeeder.php:179-261` |

Role deletion also removes both permission and user pivots through the Spatie model lifecycle; the live route deletes at `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:272-299`.

`RoleController::store` is materially concurrent with sync: it creates the role before applying its grants. A sync can inspect the half-created role, complete, and then have the store attach a deprecated or replacement-bearing grant after sync’s report. The claimed coverage test either fails immediately or silently exempts writers the prose says it covers.

The writer inventory must be exhaustive, with any initialization-only exemption explicitly bounded. “An eighth writer fails” cannot remain while the starting set is already larger than seven.

## MAJOR

### M-1 — EC-37’s one-time directory snapshot cannot produce its promised gauge

Rev 6 requires the fleet runner to snapshot the tenant directory once, skip databases created after the run began, and report `gauges.provisioned_during_run` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1168-1175,1965`).

Actual provisioning creates the central `Tenant` row first (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:86-107`), creates/migrates the database later (`:122-150`), and performs tenant initialization later still (`:222-224`). The `Tenant` model has only ordinary `created_at`/`updated_at`; it has no durable database-created or provisioning-complete timestamp (`apps/api/app/Modules/Tenant/Domain/Tenant.php:29-60,85-118`).

Consequences:

- A tenant whose central row exists at snapshot time may still have no database. `Tenant.created_at` cannot determine whether its database was created before or after the run began.
- A tenant whose central row is created after the one directory snapshot is absent from the runner’s universe, so the runner cannot count it in `provisioned_during_run`.
- EC-37 combines that absent-new-tenant case with “provisioning against a tenant the iterator is processing”, which is a different state: an existing directory row with an incomplete database.

Physical existence can be checked (`TenancyResolver.php:81-105`), but the design must state a mechanically observable snapshot/counting algorithm. This is an engineering correction, not a fifth owner question.

## MINOR

### m-1 — EC-32c still uses the rejected v0 oracle

The operative rule correctly gives ensure its own `matchesPreWave0b()` predicate (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1257-1268`). EC-32c still says a named role receives additions when it equals “its v0 baseline” and cites the rev-4 equality guard (`:1982`).

That is the exact impossible condition rev 6 claims to have removed: before ensure, a pristine role equals `postA1aGrants`, not `version0 = postA1aGrants ∪ wave0bAdditions`. The change-log assertion that EC-32c was reworded (`:2338`) is therefore false. Replace that phrase with `matchesPreWave0b()`.

### m-2 — The roles schema paragraph contradicts its own legal marker write

The paragraph first says `provisioning_source` and `id` are never written by sync for any role, then says a missing `general_manager` is created with `provisioning_source = 'w-lot-a-1a'` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1892`).

The latter matches the settled single legal write at `:943-949,1086`. The opening sentence must be narrowed to existing roles; a created Eloquent row also necessarily receives an `id`.

### m-3 — The rev-6 `dev` reference is stale

The spec records `dev = febeb89a0` at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:19,23,2350`. Current `dev` is **`aefaf6724`**. Changes since `febeb89a0` are plans/reviews only, so application evidence and lane diff sizes remain unchanged.

## Citation audit

| Claim | Result |
|---|---|
| Document read SHA | **VERIFIED:** `5309e1822`. |
| Declared application base `971528977` | **VERIFIED.** Application paths are byte-identical through `5309e1822`; spec basis at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:7-17`. |
| Rev-6 ref table | **WRONG for `dev`:** spec `febeb89a0` at `:19,23`; real SHA `aefaf6724`. W-LOT, T1 and T2 SHAs/statistics remain exact. |
| W-LOT marker schema | **VERIFIED:** column at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:25-32`; PG CHECK `:34-48`; SQLite guards `:50-63`; partial unique `:64-75`. |
| W-LOT seeder contract | **VERIFIED:** enforcement/delta branch `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:42-49`; marker-preserving branch `:51-54`; permission/grant statics `:86-104`. |
| W-LOT delta lock/order | **VERIFIED:** transaction and writes `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-101`; `name,id` row order `:111-116`; `wlota1a:` advisory key `:131-135`. |
| Rev-6 shared lock is active in wave 0b | **WRONG:** helper ships at spec `:2080-2084`; operator wiring is wave 1 at `:2096`. Real incoming operator is unlocked at lane `RoleController.php:237-264`. |
| “Seven” is the complete pivot-writer census | **WRONG:** spec `:1050-1060`; real omitted runtime writers include `RoleController.php:187-198`, `UserController.php:217-235`, `TenantInitializationService.php:183-192`, and `ResetTenantCommand.php:89-94`. |
| Route census `326 / 177 / 149` | **VERIFIED.** CSV has 1,054 rows: 642 middleware + 68 super-admin + 18 public + 326 uncovered; uncovered decomposes into 177 writes and 149 reads under the exact method rule at spec `:1347-1355`. |
| Post-reclassification ceilings `167 / 148`; post-0a `152 / 142` | **VERIFIED** at spec `:1355-1357,2058-2072`. |
| `authz.self` cannot self-certify | **VERIFIED:** exact allow-list, structural test and classifier use are specified at `:1330-1336`. A developer can intentionally edit the reviewed allow-list, but cannot make a route count merely by adding the middleware. |
| `users` email/password/status claims | **VERIFIED:** original password and unique key at `apps/api/database/migrations/tenant/2025_11_30_000003_create_users_table.php:16-35`; nullable email/partial unique at `2026_03_23_000001_make_user_email_nullable.php:14-23`; status enum at `UserStatus.php:10-15`. |
| Exhaustive existing `users` writer census | **VERIFIED.** No additional writer was found beyond the main census at spec `:240-280` and its later additions. `TenantInitializationService` is correctly excluded as a user-row writer, though it is an omitted role-grant writer. |
| Sanctum storage and TTL | **VERIFIED:** central PAT storage contract at `apps/api/config/database.php:105-135`; model pin at `apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:11-41`; abilities TEXT and `expires_at` at `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`; global TTL at `apps/api/config/sanctum.php:43-53`; per-row override at `AppServiceProvider.php:196-219`. |
| Token-tenant middleware order | **VERIFIED:** tenancy before authentication, team after authentication, then tenant claim at `apps/api/bootstrap/app.php:166-188`. |
| Data-model completeness | **VERIFIED except m-2:** both additive tenant migrations, column type/null/default/index/FK/enum and PG CHECKs are specified at spec `:1837-1900`; no PostgreSQL enum alteration or central migration is proposed. |
| PHPStan precedent and level | **VERIFIED:** level 8 and rule registration at `apps/api/phpstan.neon:5-8,33-47`; precedent at `apps/api/app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php:40-90`. |
| Locale coverage targets | **VERIFIED:** `apps/web/src/locales/en/common.json`, `fr/common.json`, and `ar/common.json` exist; test contract at spec `:1412-1416`. |
| Generated TS union versus rule 7 | **VERIFIED:** generated union at spec `:1420-1428`; backend-generated-type obligation at `CLAUDE.md:33-34`. |
| Last-admin writer list and effective endpoint | **VERIFIED:** all reducing paths are enumerated at spec `:1460-1476`; effective endpoint is self-or-`roles.view`, never bare `users.assign-roles`, at `:1480-1496`. |
| Audit-chain event naming | **VERIFIED:** immutable `.v1` sync event at `:1544-1565`; tenant-wide audit write path at `:1567-1570`. |
| PermissionSeeder deletion ordering | **VERIFIED:** production caller at `apps/api/database/seeders/ProductionSeeder.php:68-75`; deletion correctly follows declaration of `credit-notes.cancel` at spec `:1809-1823,2062,2080`. |
| EC-32c predicate | **WRONG:** live edge row at spec `:1982` says v0; real operative predicate is `matchesPreWave0b()` at `:1257-1268`. |
| EC-37 observability | **WRONG/underspecified:** spec `:1173,1965`; actual central-row-before-database order is `TenantProvisioningService.php:86-150`. |
| Historical change-log citations | **VERIFIED in their explicitly declared historical SHAs.** They are not interpreted as current `lane/w-lot-a-1a@2fa724c1d` line claims. |
| Vendor paths | **Locally unavailable, pin verified:** Laravel 12.58.0 at `apps/api/composer.lock:2630-2640`; Spatie 6.25.0 at `:7747-7758`. |

No other incorrect current-SHA `path:line` citation was found.

## Rejected false positives

### Token narrowing and bypass matrix

Laravel’s `Authorize` middleware delegates to Gate authorization; AutoERP pins Laravel 12.58.0 at `apps/api/composer.lock:2630-2640`. AutoERP enables Spatie’s Gate callback at `apps/api/config/permission.php:103-107`. The pinned callback calls `checkPermissionTo()`, which dynamically calls the model’s `hasPermissionTo()`; `hasAnyPermission()` iterates that same method ([Spatie `PermissionRegistrar`](https://raw.githubusercontent.com/spatie/laravel-permission/d7d4cb0d58616722f1afc90e0484e4825155b9b3/src/PermissionRegistrar.php), [Spatie `HasPermissions`](https://raw.githubusercontent.com/spatie/laravel-permission/d7d4cb0d58616722f1afc90e0484e4825155b9b3/src/Traits/HasPermissions.php)). AutoERP aliases and overrides the trait methods at `apps/api/app/Modules/Identity/Domain/User.php:56-59,185-212`.

| Idiom | Narrowed? | Proof/result |
|---|---:|---|
| `can:` route middleware | Yes | `Authorize → Gate → PermissionRegistrar::before → checkPermissionTo() → User::hasPermissionTo()`. |
| `$user->can()` | Yes | Same Gate callback. |
| `Gate::authorize()` / `allows()` / `denies()` | Yes | Same Gate callback. |
| Direct `->hasPermissionTo()` | Yes | Calls AutoERP’s override directly. |
| `->hasAnyPermission()` | Yes | Spatie iterates `checkPermissionTo()`, dynamically reaching the override. |
| `->getAllPermissions()` | Subject yes; fresh target intentionally no | Override at `User.php:201-212`. `/auth/me` uses it at `AuthUserData.php:34-55` and `AuthController.php:616-627`. POS PIN calls it on a freshly loaded target at `PosAuthController.php:87-100`, correctly returning the PIN holder’s grants rather than the terminal token’s projection. |
| `->hasRole()` / `hasAnyRole()` | **No** | Bypasses permission resolution. The eight production authorization sites remain at `CreateDraftCountingRequest.php:35`, `InventoryCountingController.php:782,866,905,975,1297,1494`, and `DiscountPermissionResolver.php:39`; conversion before token issuance remains required. |
| Spatie `role:` middleware | **Would bypass**, but absent | No Spatie `role:` middleware attachment exists. `central_admin_role:` is a separate central-operator mechanism and is not tenant-token authorization. |

Thus wiring token scope into `User::hasPermissionTo()` and `getAllPermissions()` covers the permission idioms, but never role-name authorization. Rev 6 states that boundary correctly.

### Other challenged areas

- **W-LOT coexistence fundamentals:** `template_key`, `template_version`, `is_system`, and `customised_at` do not collide with `provisioning_source`. The W-LOT CHECK allows only a tenant-scoped marked `general_manager` (`lane/w-lot-a-1a:...add_provisioning_source_to_roles.php:34-48`). The NULL-team resolver preserves legacy roles without re-homing (`LotActionPermissionDelta.php:104-128`). The concurrency boundary, not the schema, is defective.

- **Admin registry semantics:** changing admin from `Permission::all()` to `PermissionRegistry::activeKeys()` is a deliberate tightening after the W-LOT delta is retired. It does not violate A-1a’s eight-role grant contract; the lane currently sets admin to its declared permission list at `RolesAndPermissionsSeeder.php:93-104`.

- **Ratchet mechanics:** the exact live-router rule at spec `:1347-1358` is mechanically checkable in PHPUnit. `authz.self` is protected by set equality and route-shape tests; it cannot whitelist accidentally through middleware attachment alone.

- **Principals:** the single `users` table design covers email uniqueness/nullability, nullable passwords, `UserStatus`, login/PIN ordering, `pinHolders()`, notification selectors, all six seat counters, plan limits, FormRequests, memberships, `users.manage_location_access`, and the human-only last-admin definition at spec `:211-561,1454-1476,1841-1869`. No unlisted `users` writer remains.

- **Sanctum:** token abilities and expiry are feasible as described. PAT rows are central, not tenant-local, and the configured fallback is product-neutral `autoerp_central`, explicitly not `synerivia_central` (`apps/api/config/database.php:105-135`). Membership-removal revocation can commit the tenant mutation then delete centrally, with the queued retry specified at spec `:505-520`.

- **Lock connection:** `DatabaseTenancyBootstrapper` swaps the default connection (`apps/api/config/tenancy.php:38-42`), so `DB::transaction()` plus an unqualified `DB::select()` after tenancy initialization uses the same tenant PDO. The advisory-lock design does not inherently acquire the lock on central.

- **Static guards:** the PHPStan rule is implementable at level 8, but not without a mass baseline; rev 6 correctly admits the generated shrink-only baseline at spec `:1406`. Enum/manifest set equality is specified at `:823`, label coverage at `:1414`, and the generated TS union is consistent with rule 7 at `:1420-1428`.

- **Role hardening:** the last-admin list covers role removal, user role replacement, deactivate, destroy, membership removal, admin-role permission editing, system-role deletion, impersonated calls, and sync (`:1460-1476`). Assignment and activation cannot reduce the floor. Effective-permission reads are self-or-`roles.view` (`:1480-1496`).

- **Conventions:** convention 09’s wave table supplies second-company, second-location and rerun cases (`:1918-1925`) and gives tenant-global roles/permissions an explicit reason. Convention 10 uses real evidence and MATCH/DEFER/DIVERGE/ALREADY; G3 is now correct (`:66-85`). Convention 11 uses one principal table, one service-account creation service and one operator surface (`:213-327`), while adopting W-LOT’s General manager terminology unchanged.

- **Edge lanes:** PostgreSQL-only concurrency and partial-index cases are assigned to PG and explicitly skip SQLite (`:1961-1965`). The remaining defects are EC-20b’s deployment timing, the missing W-LOT-concurrency case, EC-32c’s stale predicate and EC-37’s unobservable gauge.

- **Wave 0a overlap:** current diffs confirm the six-item list is disjoint except for the settled `feature-lane-manifest.json` counter overlap (`:2043-2072`). T1 remains empty; W-LOT and T2 retain the recorded sizes.

- **Entrypoint safety:** replacing the destructive seeder under `SYNC_PERMISSIONS_ON_BOOT` is safe only after the wave-1 staging soak, durable status reporting and the OQ-4 ruling (`:2094-2100,2185-2188`). It is not safe to rely on the new lock during wave 0b until B-1 is closed.

## Preserve

The fix round must not alter:

- Direction B, D1–D8, or the owner’s first-class principal/token ruling.
- Tenant-scoped roles and membership-based company/location scope.
- D2 exact template deltas, custom/customised-role protection, and explicit reapply.
- `matchesPreWave0b()` for ensure, `matchesVersion0()` for adoption, per-role eligibility snapshots and batch grants.
- Canonical post-rename equality, single-pass canonicalisation, and `rename_targets_are_not_sources`.
- The NULL-team no-re-homing ruling and sole legal `provisioning_source` creation write.
- `admin = PermissionRegistry::activeKeys()`.
- Exactly two sync triggers: provisioning and flagged fleet sync; never migration-triggered sync.
- The `326 / 177 / 149`, `167 / 148`, and post-0a `152 / 142` ratchet figures.
- `roles.view` read shaping and self-or-`roles.view` effective-permission access.
- Human-only last-admin floors.
- Central Sanctum storage, per-token expiry precedence, and owner-grants ∩ token-scope semantics.
- The subject/target split and intentionally unnarrowed POS PIN-holder payload.
- Immutable, versioned `RoleSyncedV1` audit events and audit-chain denial logging.
- The two additive tenant migrations, generated TypeScript union, enum/manifest parity and en/fr/ar coverage.
- Wave 0a’s six-item list and its single settled manifest-counter overlap.
- PermissionSeeder deletion after `credit-notes.cancel` is declared.
- G3’s provisioning-plus-flagged-fleet wording and the one-week staging soak.

## Owner decisions required

Only the four already-declared questions remain:

1. **OQ-1:** whether service accounts count toward the last-admin floor. Code does not decide this policy; the applied default remains human-only (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2168-2172`).
2. **OQ-2:** service-token TTL and the related human-token change (`:2174-2178`). Existing code establishes 30-day global and one-year POS behavior but not the future service-token policy.
3. **OQ-3:** which existing SoD combinations, if any, to retire (`:2180-2183`).
4. **OQ-4:** whether `SYNC_PERMISSIONS_ON_BOOT` defaults to true after the soak and safety preconditions (`:2185-2188`).

No additional owner question is required. The lock census/wave timing and fleet-snapshot defects are engineering-spec corrections under settled rulings.

VERDICT: CHANGES-REQUIRED