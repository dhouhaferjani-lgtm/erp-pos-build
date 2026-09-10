# Codex spec gate r4 — roles & permissions catalogue design rev 4.1 (gpt-5.6-sol, high, read-only, 2026-09-10)

## Rev-1 base SHA

Read at **`43ac8b068`**.

`apps/api/vendor/autoload.php` is absent, so `php artisan route:list --json` could not boot. Ratchet figures were recomputed from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv`.

## Rev-3 closure table

| Rev-3 finding | Rev-4.1 disposition |
|---|---|
| B-1 fresh-tenant sync recreates rename sources | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:830-843,975-998`. The W-LOT delta is no longer invoked by sync. |
| B-2 delta mutates roles before adoption | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:830-840,975-986`. Adoption now precedes grant writes. |
| B-3 `$behavesAs` not stored / SoD example inconsistent | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:566-627,716-734`. |
| B-4 `permissions:ensure` widens customised roles / impossible supersession test | **NOT CLOSED.** Equality guarding is specified at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1127-1134`, but its additions make the subsequent v0 adoption comparison fail; see BLOCKER B-1. |
| M-1 central `SuperAdmin` affected by global scope middleware | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:440-451`. |
| M-2 generic user endpoints form a second service-account surface | **NOT CLOSED.** `UserController` is covered, but `RoleController::assignRole/removeRole` remains a generic service-account mutation surface; see MAJOR M-1. |
| M-3 incomplete user-writer and notification census | **CLOSED in substance**, but three post-create `User::update()` writers remain absent from the claimed exhaustive census; see MINOR m-2. |
| M-4 deprecation cannot revoke grants / `reports.view` omitted | **CLOSED for deprecated-key revocation** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:982-994,1597-1632`. The broader settled add-and-remove template-delta contract is still not implemented; see BLOCKER B-2. |
| M-5 sync cannot emit `RoleUpdated` | **CLOSED** through `RoleSyncedV1` and the tenant-wide audit path at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1389-1421`. Event-name versioning remains internally inconsistent; see MINOR m-3. |
| M-6 deleting `PermissionSeeder` breaks test callers | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1640-1654`. Deletion and the `ProductionSeeder` caller removal now land together in 0b-6. |
| M-7 impossible Channel second-location test | **NOT CLOSED.** Replacing Channel with Batch did not make the test executable because Batch delete/recall does not enforce location access; see MAJOR M-2. |
| M-8 wave-0a overlap based on stale lane tips | **NOT CLOSED.** Both active lane tips moved again and T2 now touches two explicit 0a implementation files; see MAJOR M-3. |
| M-9 impossible `syncRole()` contract | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:951-958`. |
| Minor 1 EC-21b ordering | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1795`. |
| Minor 2 impossible Redis interleaving | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1803`. |
| Minor 3 “table of five” | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1516`. |
| Minor 4 stale step-count comment | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:951,968`. |
| Minor 5 PHPStan rule count | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1224-1227`. |
| Minor 6 OQ-2 “shorten” 30→90 days | **CLOSED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1992-1996`. |
| Minor 7 no DB enum constraint on `template_key` | **CLOSED** by the application-domain test at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1714`. |
| Minor 8 missing edge cases | **CLOSED for the rev-3 cases** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1812-1816`; new rev-4.1 gaps are identified below. |
| Rev-3 rejected findings | **REJECTED-correctly: none.** Rev 4 itself says nothing was rejected at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2108`. |

## BLOCKER

### B-1 — Mandatory rename and ensure mutations invalidate the frozen-v0 adoption oracle

The v0 snapshot is explicitly the lane’s post-A-1a grant map, including its legacy permission spellings, and adoption compares the current grant set directly to that snapshot (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:845-875`). But rename runs first (`:975-980`).

The lane snapshot contains:

- `uom.edit` at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:268`
- `deliveries.edit` at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:288`
- `pos_held_orders.*` at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:495-497`
- `catalog_cart.*` at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:556-561`

Step 1 renames those rows in place before adoption. Consequently, a genuinely pristine marked tenant no longer equals the frozen snapshot when step 4 runs.

Wave 0b makes the mismatch worse: `permissions:ensure` adds new grants to equality-approved template roles (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1121-1134`). Those legitimate 0b additions are absent from the exact post-A-1a v0 snapshot. On first wave-1 sync, the same otherwise-pristine roles are classified customised.

The three tenant types therefore behave as follows:

| Tenant type | Rev-4.1 first-sync result |
|---|---|
| Legacy unmarked | Renames alter the equality input; `manager` also differs by the deliberately missing W-LOT delta. Conservative customisation of `manager` is intended by EC-9c, but other roles can be false-customised solely by rename. Missing `general_manager` is created with the legal marker. |
| Marked by W-LOT | Post-A-1a grants match v0 before sync, but step-1 renames and wave-0b ensured grants make them differ before adoption. Pristine roles become customised and stop receiving D2 deltas. |
| Provisioned after wave 1 | No legacy adoption comparison is needed for newly created roles; repeat sync can be write-idempotent. This does not repair the two upgrade paths above. |

It also makes `PermissionsSyncSupersedesEnsureTest`’s `renamed=0` expectation false: ensure never performs renames (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1121`), so the first sync of a normal legacy tenant must rename existing source rows, contrary to `:1140-1143`.

Fix requirement: define adoption equality over the canonical post-rename representation and account for known equality-approved 0b additions. Do not reintroduce `TemplateMigration` or change the settled sync order.

### B-2 — Step 6 does not implement the settled “add AND remove” template delta

The settled ruling requires template deltas to add and remove grants for uncustomised template-linked roles. The shared `TemplateDeltaApplier` is described as a pure diff against `registry->templateGrants()` at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:958`.

But step 6 revokes only keys marked deprecated and expressly preserves every active key absent from the template (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:982-986`). Thus an active grant removed from a later template remains forever on a supposedly platform-managed pristine role. “Re-apply template” also cannot produce the exact template grant set promised by `:958`.

This is a direct mismatch with the settled rev-4/4.1 contract, not a new policy question.

### B-3 — The migration listener bypasses the boot flag and can execute sync twice

The design simultaneously specifies:

- a flag-controlled `permissions:sync-fleet` block at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1004-1025`;
- an unconditional listener on Stancl’s tenant-migrated event at `:1068`;
- OQ-4 as the decision whether `SYNC_PERMISSIONS_ON_BOOT` defaults true at `:2003-2006`.

The current entrypoint always runs rolling migrations before the flagged permission block (`apps/api/docker/entrypoint.sh:148-170`). Rolling migrations call `tenants:migrate` once per tenant (`apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:80-103,110-133`).

The pinned Stancl tenancy command dispatches `DatabaseMigrated` after each tenant migration command, including no-op migrations; the dependency is pinned in `apps/api/composer.lock:8242-8310`. Therefore:

- flag false still syncs every rolling-migrated tenant through the listener;
- flag true syncs through the listener and then again through `permissions:sync-fleet`;
- listener failure is not represented by `/var/run/autoerp/permissions-sync.status`, whose two arms exist only inside the flag block.

OQ-4 cannot govern production execution under this design, and staging auto-deploy has two competing sync entrypoints. A single explicit trigger and corresponding health/status contract are required.

### B-4 — `coupons.view` is classified dead while it still gates the frontend

`coupons.view` is in the authoritative 23-key deprecation set (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1597-1632`). Step 6 revokes deprecated keys from uncustomised templates, and admin is replaced with active keys only (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:982-995`).

The current application still uses the key:

- coupons route guard: `apps/web/src/routes/index.tsx:3051-3059`
- permission alias: `apps/web/src/hooks/usePermissions.ts:111-113`
- generated permission map: `apps/web/src/hooks/permissionsMap.generated.ts:48`

The API coupon reads remain ungated at `apps/api/app/Modules/Coupon/Presentation/routes.php:10-16`, and wave 0a adds only coupon write gates (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1881-1890`). No replacement read permission is specified.

After wave-1 sync, even admin loses `coupons.view`, so the coupons page becomes unreachable. The mandatory post-0a/0b `permissions:report-dead` gate at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1616` must consider frontend guards, not backend routes alone.

## MAJOR

### M-1 — Role assignment remains an unrestricted generic service-account write path

Rev 4.1 says service accounts are mutable only through `/service-accounts/*` and generic user writes return typed 422 (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:317-333`).

Role assignment is not a `UserController` method. The existing generic endpoints remain:

- routes: `apps/api/app/Modules/Identity/routes.php:77-80`
- assignment: `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:350-359`
- removal: `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:390-398`

A caller with `users.assign-roles` can therefore change a service principal’s owner grants without any `service-accounts.*` permission. The spec names no dedicated service-account role mutation route at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:274-285,333`.

### M-2 — `BatchWritePermissionsSecondLocationTest` cannot prove location denial

The replacement Convention-09 test expects a location-A principal to be refused when deleting or recalling a batch whose stock is at location B (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1749-1754`).

At HEAD, both writes resolve a batch and mutate it without consulting `LocationScopeResolver`:

- delete: `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:176-187`
- recall: `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:193-205`

The resolver is used only by an expiry-list read at `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:240-254`.

The W-LOT lane has the same gap: delete/recall are at `lane/w-lot-a-1a:apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:251-280`, while `BatchActionAccess` checks only permission activation, not location ownership, at `lane/w-lot-a-1a:apps/api/app/Modules/BatchExpiry/Presentation/Middleware/BatchActionAccess.php:16-26`.

The promised second-location denial is not an existing property of the write path.

### M-3 — Wave 0a is no longer disjoint at the current lane tips

Current measurements:

| Ref | Current SHA | Current diff from `dev` |
|---|---:|---:|
| `dev` | `d418a2656` | — |
| `lane/w-lot-a-1a` | `2fa724c1d` | 81 files, +4,390/−646 |
| `lane/t1-transfers-edge` | `86273346a` | empty |
| `lane/t2-receipt-spine` | `93b106461` | 86 files, +5,979/−423 |

T2 now modifies both:

- `apps/api/app/Http/Middleware/RequireAnyPermission.php:21-26`, including the exact message changed by 0a-9;
- `apps/api/app/Modules/Inventory/Presentation/routes.php:98-129`, which 0a-5 also claims for the counting-item gate.

It also changes `.github/workflows/ci.yml` and `apps/api/tests/feature-lane-manifest.json`.

Accordingly, the “no lane touches Inventory routes” and single accepted manifest-overlap statements at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1838-1888` are false at the current refs. The accepted 0a-1 manifest ceiling overlap remains settled, but it is no longer the only overlap.

### M-4 — “Every counter zero” conflicts with persistent-state counters

Step 3 always reports the current orphan count (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:979`). Step 6 counts every skipped customised role in `templates_skipped_customised` (`:982-984`). Both appear in the marker schema at `:924-933`.

Nevertheless, the design repeatedly requires a second run to report every counter zero (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:975,998,1143,1753,1816`). `:998` even says skipped-customised is “reported but unchanged”, which cannot simultaneously mean a zero counter when customised roles exist.

Zero database writes is mechanically testable and is the correct idempotency invariant. The result-marker contract must distinguish mutation counters from persistent state gauges, or redefine those two counters.

## MINOR

### m-1 — EC-9c overstates what the retained lane seeder does

EC-9c says the lane’s own seeder is a no-op after sync writes the marker (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1812`), and the same claim appears at `:838-843,894-895`.

In the actual lane seeder, the enforcement-enabled branch invokes the delta before marker preservation is checked (`lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:42-54`). The delta has no marker early return and can recreate old permission names at `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:65-90`.

The settled deployment remains safe because wave 1 replaces the seeder and never calls the delta. The marker alone, however, does not make the retained lane implementation a no-op.

### m-2 — Three `users` writers remain outside the exhaustive census

The principal census includes the three Parapharmacy `User::create()` calls, but not their subsequent PIN updates:

- `apps/api/database/seeders/ParapharmacySeeder.php:1448`
- `apps/api/database/seeders/ParapharmacySeeder.php:1483`
- `apps/api/database/seeders/ParapharmacySeeder.php:1519`

They remain safe because each updates a newly created human user, but the claimed “every writer” census at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:236-266` is incomplete.

### m-3 — `RoleSyncedV1` has an unversioned audit event name

The class is `RoleSyncedV1`, but its persisted name is `identity.role.synced` at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1393-1409`; the document simultaneously promises immutable, versioned event names at `:1412,2115`.

Repository convention says published event names are immutable/versioned at `CLAUDE.md:36-37`; an existing example pairs `RewardRedeemedV2` with a `.v2` name at `apps/api/app/Modules/Loyalty/Domain/Events/RewardRedeemedV2.php:34-36`. Choose one end-to-end versioned spelling before implementation.

### m-4 — Retired-symbol change-log claim is literally false

The operational design has correctly removed `TemplateMigration` and the public `syncRole()` API. But the change log says all surviving references are gone at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2080`.

The document still contains `TemplateMigration` at `:830,834,843,2039,2046,2080`, `syncRole` at `:956,1780,1914`, and `template_migrations` at `:2046,2080`. These are mostly historical explanations, so this is editorial rather than an implementation defect. The assertion should say “no operational design reference”.

A second stale internal anchor remains at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1776`, which calls after-commit cache “step 10”; it is step 9 at `:951,975-996`.

## Citation audit

All other inspected citations resolve to the claimed code shape. Wrong or stale citations are:

| Claim | Result and real line |
|---|---|
| §0 lane SHAs and diff sizes at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:15-24` | **WRONG/stale.** Current refs are W-LOT `2fa724c1d`, 81 files/+4,390/−646; T1 `86273346a`, empty; T2 `93b106461`, 86 files/+5,979/−423. |
| W-LOT `permissionNames()` at lane seeder `:80-83` | **WRONG/stale.** Real method is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:86-90`. |
| W-LOT `rolePermissionGrants()` at lane seeder `:86-97` | **WRONG/stale.** Real method is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:93-104`; admin/manager/general-manager are `:96-98`. |
| W-LOT rename-source lines `uom.edit :260`, `deliveries.edit :280`, `pos_held_orders :487-489`, `catalog_cart :548-553` at spec `:832` | **WRONG/stale.** Real lines are `:268`, `:288`, `:495-497`, and `:556-561` respectively. |
| W-LOT delta invocation “`:31-44 → :36`” at spec `:838` | **WRONG endpoint.** The invocation is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:42-49`, call at `:43`. |
| W-LOT unmarked branch `:47-49`, legacy creation `:632-641` | **WRONG/stale.** Real lines are `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:55-58,640-648`. |
| W-LOT marker predicate `:64-70` | **WRONG/stale.** Real predicate is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:71-76`. |
| Marker preservation branch `:44-51` | **WRONG/stale and incomplete.** It is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:51-54`, after the enforcement branch at `:42-49`. |
| §6 Batch citation `BatchController.php:40,252` proves location-bound writes | **WRONG inference.** Those lines cover injection/read scope. Actual writes at HEAD are `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:176-205`, with no location check. |
| §8 says T2 does not touch Inventory routes and only the manifest overlap remains | **WRONG/currently stale.** T2 now includes `apps/api/app/Modules/Inventory/Presentation/routes.php:98-129` and `apps/api/app/Http/Middleware/RequireAnyPermission.php:21-26`. |
| “All W-LOT-only files are always branch-qualified” at spec `:2105` | **WRONG.** The migration and delta are cited without the `lane/w-lot-a-1a:` qualifier at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:810,813`; those files do not exist at HEAD. |
| Current destructive boot block | **VERIFIED**, but the spec’s `entrypoint.sh:162-170` omits the immediately preceding unconditional rolling migration. Full relevant range is `apps/api/docker/entrypoint.sh:148-170`. |
| Ratchet baseline `326 / 177 / 149`, ceilings `167 / 148` | **VERIFIED.** CSV classification yields 642 `MIDDLEWARE` + 68 `SUPERADMIN_ONLY` gated, 18 `PUBLIC`, and 326 uncovered; uncovered split is 177 writes/149 reads. Removing four tombstones plus six self-service writes and one self-service read gives 167/148. Source classification is documented at `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.md:32-45`; spec rule is `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1198-1207`. |
| `PermissionSeeder` deletion sequencing | **VERIFIED.** It is now 0b-6, after the `credit-notes.cancel` declaration, and includes removal of the `ProductionSeeder` caller at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1638-1654,1905-1917`. |
| Role lookup tolerates legacy NULL-team roles without re-homing | **VERIFIED.** The resolver and collision rule are specified at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:735-782,865-895`; the existing Spatie role uniqueness is `apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:43-47`. |
| `admin = registry active keys`, not `Permission::all()` | **VERIFIED** at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:995`. This intentionally supersedes the current broad seeder assignment at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:568`. |

## Rejected false positives

### Token narrowing paths

The token-scope model is sound for permission-based checks once the proposed `User` overrides and middleware are installed. No additional permission idiom bypass was found.

| Idiom | Result |
|---|---|
| `can:` route middleware | **Narrowed.** Laravel authorization reaches Gate; Spatie registers a Gate `before` callback because `register_permission_check_method` is enabled at `apps/api/config/permission.php:103-107`. Spatie 6.25 is pinned at `apps/api/composer.lock:7747-7820`; its callback invokes the subject’s permission check, which reaches the proposed `User::hasPermissionTo()` override at `apps/api/app/Modules/Identity/Domain/User.php:185-198`. |
| `$user->can()` | **Narrowed** through the same Gate callback. |
| `Gate::authorize()` / `allows()` / `denies()` | **Narrowed for permission abilities** through Gate. Policy-method abilities are separate authorization logic and are not permission-scope claims. |
| Direct `->hasPermissionTo()` | **Narrowed** by `apps/api/app/Modules/Identity/Domain/User.php:185-198`. |
| `->hasAnyPermission()` | **Narrowed** because Spatie iterates its permission checker, ultimately invoking the override. |
| `->getAllPermissions()` | **Narrowed for the authenticated subject** by `apps/api/app/Modules/Identity/Domain/User.php:201-212`. `/auth/me` uses it at `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:616-628` through `apps/api/app/Modules/Identity/Application/Data/AuthUserData.php:34-55`. |
| POS PIN permission payload | **Intentionally unnarrowed target read.** `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100` loads the PIN holder as a target rather than evaluating the current token subject. The spec preserves this distinction. |
| `->hasRole()` | **Bypasses token narrowing.** Existing authorization uses include `apps/api/app/Modules/Inventory/Presentation/Requests/CreateDraftCountingRequest.php:35` and the POS discount resolver. The spec correctly makes converting role-name authorization a prerequisite to scoped token issuance at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:384-402,1940-1948`. |
| Spatie `role:` middleware | **No use found.** The only role-named route middleware is the separate central `super_admin` mechanism registered at `apps/api/bootstrap/app.php:114-123`. |

Thus “every existing permission call site inherits narrowing” is supportable only with the spec’s subject/target distinction and after its explicitly listed role-name conversions. It is not true of `hasRole()` by itself, which the spec already acknowledges.

### Ratchet and `authz.self`

The exact classification is mechanically checkable in a PHPUnit architecture test by enumerating the live router, normalising middleware, and performing exact baseline comparison. The `authz.self` design requires both an exact route-name allow-list and a matching self-route structural shape at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1181-1187`; merely adding the middleware to an unrelated route cannot whitelist it. A developer could deliberately edit both test-controlled lists, but that is a visible reviewed baseline change, not an accidental escape hatch.

### Principals, Sanctum, schema, guards and hardening

No additional blocker was found in these areas:

- Reusing `users` is feasible with nullable email/password, human-only partial uniqueness, active-only service status, PIN/login exclusions, notification predicates, six seat counters, a separate service-account ceiling, membership/location support, and `TenantInitializationService`/FormRequest census. Existing user shape is at `apps/api/database/migrations/tenant/2025_11_29_231748_create_users_table.php:16-34`; email uniqueness is already topology-aware at `apps/api/database/migrations/tenant/2026_03_23_120000_make_user_email_unique_per_tenant.php:14-23`. The only missed writers are MINOR m-2.
- Sanctum token storage is correctly central: `personal_access_tokens` includes nullable TEXT abilities and `expires_at` at `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`; global TTL is `apps/api/config/sanctum.php:43-53`; per-row expiry precedence is `apps/api/app/Providers/AppServiceProvider.php:196-219`; central resolution is `apps/api/app/Models/CentralPersonalAccessToken.php:11-41` and `apps/api/app/Http/Middleware/ResolveTenancy.php:116-134`. `EnforceTokenTenantClaim` ordering is established at `apps/api/bootstrap/app.php:174-188`. Membership-removal revocation through central context is feasible as specified.
- §5 columns have explicit type, null/default, index/FK/domain constraints at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1668-1731`. Migrations are additive and tenant-scoped; no PostgreSQL enum-typed column is altered. The service CHECK is valid for PostgreSQL, with explicit SQLite trigger handling.
- PHPStan level 8 and custom-rule precedent exist at `apps/api/phpstan.neon:5-8,33-47` and `apps/api/phpstan/Rules/ForbidFloatCastOnDecimalProperty.php:40-88`. The permission-literal rule is implementable, but necessarily ships with the documented initial baseline; claiming “without a mass baseline” would be false. Enum⊆manifest, locale label coverage, and generated TypeScript-union tests at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1240-1275` are mutually consistent with rule 7.
- The last-admin floor enumerates role removal, user update/destroy/deactivate, membership removal, system-role edits/deletion, sync/admin replacement, and impersonation at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1307-1323`. Effective-permission reads are self-only or `roles.view`, with shaped output at `:1327-1345`.
- EC-9c’s conservative classification of a genuinely never-delta’d `manager` is acceptable. The defect is its assertion about what makes the old lane seeder a no-op, not the conservative adoption outcome.

## Preserve

The fix round must preserve:

- Direction B and settled D1–D8, including tenant-scoped roles, D2 protection, the shrink-only ratchet, `roles.view`, kebab-resource naming, no general user overrides, sequencing after W-LOT, and audit events.
- First-class human/service principals with effective permissions equal to owner grants ∩ token scope.
- Sync never invoking W-LOT’s delta; the lane deploying first; v0 remaining the post-A-1a eight-role map; and the fixed order rename → create → orphans → adopt → create roles → template deltas → replacements → admin → after-commit cache.
- `syncRole()` remaining deleted and `TemplateDeltaApplier` remaining the shared role-template implementation.
- The NULL-team no-re-homing ruling and the single legal `provisioning_source='w-lot-a-1a'` write when sync creates missing `general_manager`.
- `permissions:ensure` always granting admin and equality-guarding named template roles.
- Subject/target separation, intentional POS PIN target behaviour, global token-scope attachment, and central `personal_access_tokens`.
- Ratchet figures `326 / 177 / 149` and ceilings `167 / 148`, including the exact `authz.self` allow-list.
- Human-only deterministic last-admin floors, D4 payload shaping, and the tenant-wide audit-chain write path.
- The additive tenant schema, topology-aware indexes, generated TypeScript union, locale coverage tests, enum/manifest parity, and static literal guards.
- The accepted rev-4.1 0a-1 feature-lane-manifest ceiling overlap. Current additional T2 overlaps must be remeasured without reopening that accepted exception.
- EC-9c’s conservative outcome.
- `PermissionSeeder` deletion staying in 0b-6 with its production and test caller dispositions.

## Owner decisions required

No new owner question is required; the findings above are code/spec consistency defects within settled policy.

| Question | Status |
|---|---|
| OQ-1 — whether a service administrator may satisfy the last-admin floor | **Genuinely open policy decision.** |
| OQ-2 — service-token and human-token default TTLs | **Genuinely open policy decision.** Existing global/per-row behaviour is correctly documented. |
| OQ-3 — which baselined SoD combinations, if any, should be retired | **Genuinely open policy decision.** The shrink-only baseline can ship unchanged. |
| OQ-4 — production default for `SYNC_PERMISSIONS_ON_BOOT` | **Genuinely open policy decision, but not safely exercisable until BLOCKER B-1 and B-3 are fixed.** |

VERDICT: CHANGES-REQUIRED