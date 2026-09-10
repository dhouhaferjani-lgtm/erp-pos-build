# Codex spec gate r7 — roles & permissions catalogue design rev 7 (gpt-5.6-sol, high, read-only, 2026-09-10)

## Rev-1 base SHA

- SHA read: **`c5b488410`**
- Declared application-code base: **`971528977`** (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:7`)
- `git diff --quiet 971528977..c5b488410 -- apps/api apps/web apps/pos packages/shared` succeeds: the application tree is unchanged.
- Current refs: `dev@5dbb7e1ee`, `lane/w-lot-a-1a@2fa724c1d`, `lane/t1-transfers-edge@86273346a`, `lane/t2-receipt-spine@93b106461`.
- Artisan could not boot because `apps/api/vendor/` is absent. Ratchet figures were recomputed from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv`, as the spec permits (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1395-1403`).

## Rev-6 closure table

| Rev-6 finding | Rev-7 disposition |
|---|---|
| B-1: W-LOT delta and catalogue writers used different advisory keys and row orders; seven runtime controller writers were deferred | **CLOSED at rev-7 anchors.** The design adopts exactly `'wlota1a:'.$tenantId`, `hashtextextended(?, 0)`, and `roles.name, roles.id` ordering (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1034-1057`), matching the lane at `LotActionPermissionDelta.php:111-116,131-135`. The seven existing controller writers move to 0b-14 (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2132`). |
| B-2: writer census was incomplete | **NOT CLOSED in full.** The mechanical census is now complete and the two tenant migrations are included (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1059-1096`), but the stated initialization-only invariant is false for `ResetTenantCommand`; see MAJOR M-2. |
| M-1: one directory snapshot could not produce the promised provisioning gauge | **NOT CLOSED.** The second read makes the gauge observable, but the replacement algorithm treats an existing live tenant left behind by a failed rolling migration as an informational skip and permits a successful fleet result; see BLOCKER B-1. |
| minor 1: EC-32c used the rejected v0 oracle | **CLOSED.** `matchesPreWave0b()` is now distinct from `matchesVersion0()` and EC-32c uses the former (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1303-1318,2030`). |
| minor 2: roles schema text contradicted legal `general_manager.provisioning_source` creation | **CLOSED.** The prohibition is correctly limited to existing roles, with the legal creation path explicit (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1938`). |
| minor 3: stale `dev` ref | **CLOSED.** `dev@5dbb7e1ee` and all three lane SHAs/diffstats are current (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:21-30`). |

The rev-6 → rev-7 change log is accurate for the adopted lock, row order, controller wiring, expanded writer inventory, predicate correction, schema correction, and ref updates (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2406-2418`). Its claimed fleet and initialization-only closures are not operationally valid for the reasons below.

## BLOCKER

### B-1 — The fleet runner can report success after a live tenant’s migration failed

The four-step algorithm classifies every directory tenant whose database is absent **or whose migrations are not current** as `gauges.skipped_incomplete`; that gauge does not affect exit status (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1214-1220`).

That state is not specific to a tenant currently being provisioned. It also describes an established tenant whose rolling migration just failed:

- Rolling migrations run immediately before permission sync (`apps/api/docker/entrypoint.sh:138-150`).
- Their non-zero result is caught and boot continues (`apps/api/docker/entrypoint.sh:150-154`).
- Permission sync then observes that established tenant as migrations-behind.
- Rev 7 turns that into an informational gauge and can exit zero, causing the entrypoint to write `permissions_sync=ok` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1165-1175,1216-1220`).

The same ambiguity exists for directory rows. Provisioning writes the directory row first, then creates/migrates the database, then initializes it (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:86-107,145-150,222-224`). A row in the end-of-run set difference is evidence only that the row appeared during the run, not that provisioning completed or that its provisioning sync succeeded. Therefore the assertion “Those tenants were synced by their own provisioning trigger” is not established (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1217`).

EC-37a actively encodes the unsafe result: migrations-behind inside the starting snapshot is skipped with exit zero (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2012`).

Required correction: distinguish an observably active provisioning attempt from an established or failed tenant. If no reliable discriminator exists, a start-snapshot tenant that fails either readiness predicate must contribute `FAILED`/`BLOCKED` and the non-zero fleet result. `provisioned_during_run` must remain a population gauge and must not assert successful provisioning or successful sync without observable evidence.

Until corrected, OQ-4’s stated prerequisite that every failed tenant makes the fleet runner non-zero is false (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2235`).

## MAJOR

### M-1 — `permissions:ensure` has neither a specified Spatie team boundary nor an executable fleet contract

The design correctly brackets `PermissionSyncService::sync()` with `PermissionRegistrar::setPermissionsTeamId($tenantId)` and restores the previous value in `finally` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1102-1117`). `permissions:sync-fleet` calls that service directly, so fleet sync inherits the boundary (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1207-1210`).

W-LOT does the same in actual lane code: it sets the team before the transaction, explicitly resolves legacy NULL-team or tenant-team roles, and restores the previous team in `finally` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:45-56,96-100,111-128`).

`permissions:ensure` does not specify either mechanism. Its contract begins with Spatie writes and named-role locking (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1291-1298`), but never requires:

- setting/restoring `PermissionRegistrar` for the tenant;
- the NULL-team-or-current-team role predicate; or
- passing the tenant/team explicitly to every role lookup.

That omission matters because HTTP gets its team context from `SetPermissionsTeam` (`apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22-29`), while console jobs do not. The settled NULL-team ruling explicitly identifies console and queued contexts as lacking that middleware (`docs/handoff/CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md:3`). W-LOT’s new `general_manager` is tenant-team-scoped, while the legacy catalogue roles stay NULL-team (`docs/handoff/CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md:6-8`). A default-context `Role::findByName()` can therefore miss `general_manager` or resolve a different topology than the lock query.

The deploy instruction is also not executable as written: the command signature has no tenant selector, yet the runbook says to execute it “across every tenant” and vaguely invokes “the same fleet mechanism” (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1283-1298,2134-2140`). Using `tenants:run` would inherit the discarded-child-status defect the spec already rejects for sync (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1192-1198`).

Required correction: define one concrete per-tenant runner, including option forwarding and aggregate failure semantics, and require the same set/restore team boundary or explicit NULL-or-tenant role resolution for the whole `ensure` invocation. Add a marked-tenant case with NULL-team `admin`/`manager` and tenant-team `general_manager`.

### M-2 — `ResetTenantCommand` does not satisfy the settled initialization-only exemption

The design states that every initialization-only writer runs only where “no operator” can be concurrent, and places `ResetTenantCommand` in that class (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1078-1083`).

Actual code provides no such exclusion:

- It is disabled only in production (`apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:27-35`).
- `--force` bypasses confirmation (`apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:21-23,54-63`).
- It drops and recreates the live schema without a maintenance/drain or authorization-write exclusion (`apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:65-75`).
- It then performs `assignRole('admin')` (`apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:89-94`).

A staging or development tenant can have active operators. “The operator has already lost more than a grant” is an acknowledgement of destructive concurrency, not proof that concurrency cannot happen.

This does not require reopening the settled LOCKED/INITIALIZATION-ONLY partition. To retain `ResetTenantCommand` in INITIALIZATION-ONLY, the design must state and enforce its exclusivity precondition—maintenance/drain or equivalent—before schema destruction. `PermissionWriteLockCoverageTest` must verify that precondition rather than accepting the current rationale as proof.

## MINOR

### m-1 — The five identical row-lock obligations are impossible for `RoleController::store`

The design requires every writer to lock its affected `roles` rows before writing (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1057`). `RoleController::store` creates a role that does not yet exist, then grants it permissions (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:187-198`). There is no role row to lock before creation.

Clarify the store-specific contract: begin transaction, acquire the shared advisory lock first, create the role, and apply its initial grant set in the same transaction. A post-insert `FOR UPDATE` adds no serialization beyond the already-held per-tenant advisory lock.

### m-2 — EC-20c states the wrong outcome after W-LOT commits first

EC-20c says that when the W-LOT delta commits first, `ensure` sees the delta’s post-state and `matchesPreWave0b()` decides against it, sending the role to `admin_only=` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2011`).

The settled predicate says the opposite: it returns true exactly when current grants equal `postA1aGrants(role)` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1303-1312`). The lane delta produces that post-A-1a state by adding missing grants and removing only legacy `batches.recall` from manager (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:163-181`).

Correct expected behavior:

- W-LOT first: pristine manager/general-manager match `postA1aGrants`; `ensure` grants the complete 0b batch.
- `ensure` first: W-LOT’s additive delta and single recall revocation preserve the unrelated 0b grants.
- Both orders serialize without deadlock and end with the intended W-LOT state plus eligible 0b additions.

The PG test must assert survival of the full eligible 0b batch, not merely that `batches.recall.request` survives.

## Citation audit

All resolvable application and lane citations were opened at their stated revisions. No stale numeric `path:line` coordinate was found in rev 7. The failures below are semantic claims contradicted by the cited code or by the design’s own defined predicate. Vendor paths could not be opened because dependencies are absent; pins were confirmed at `apps/api/composer.lock:2630-2640,7747-7758`.

| Claim | Result and real line |
|---|---|
| Current read SHA/application base | **VERIFIED.** HEAD is `c5b488410`; application bytes are unchanged from declared base `971528977` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:7-19`). |
| Current refs/diffstats | **VERIFIED.** `dev@5dbb7e1ee`; W-LOT 81 files/4,390+/646−; T1 empty; T2 86 files/5,979+/423−, matching `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:21-30`. |
| W-LOT advisory key and role-row order | **VERIFIED.** `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:111-116,131-135`. |
| W-LOT permission-team set/restore boundary | **VERIFIED.** Real range is `LotActionPermissionDelta.php:45-50,96-100`. |
| W-LOT legacy NULL-or-tenant role resolution | **VERIFIED.** Real range is `LotActionPermissionDelta.php:111-128`. |
| Seven current controller runtime writers are wired in 0b-14 | **VERIFIED as a design deliverable.** Current call sites are `RoleController.php:191,197,246,298,358,398` and `UserController.php:234,384`; rev-7 placement is `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2132`. |
| Exhaustive direct pivot-writer grep | **VERIFIED for membership of the set.** The expanded inventory at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1059-1096` contains every direct `syncPermissions`, `givePermissionTo`, `revokePermissionTo`, `assignRole`, `syncRoles`, `removeRole`, role/permission create/firstOrCreate, and role-delete cascade found under `apps/api/app` and `apps/api/database`. |
| “Every INITIALIZATION-ONLY writer has no concurrent operator” | **WRONG.** `ResetTenantCommand` only rejects production at `apps/api/app/Modules/Tenant/Application/Commands/ResetTenantCommand.php:27-35`; it exposes `--force` at `:21-23`, drops the schema at `:65-71`, and assigns roles at `:89-94`. |
| “Migrations-behind is informational and exit-unaffected” | **WRONG for established tenants.** The entrypoint catches rolling migration failure at `apps/api/docker/entrypoint.sh:150-154`; rev 7 then classifies the resulting behind tenant as a harmless gauge at `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1215-1220`. |
| “Every tenant in `provisioned_during_run` was synced by provisioning” | **WRONG/unproven.** Directory creation precedes DB creation, migration, and initialization at `TenantProvisioningService.php:86-107,145-150,222-224`; the set difference at spec `:1217` observes only row appearance. |
| `permissions:ensure` is safe in console team context | **NOT ESTABLISHED.** Its contract at spec `:1291-1298` lacks the team boundary used by actual W-LOT code at `LotActionPermissionDelta.php:45-50,96-100`; HTTP-only setup is `SetPermissionsTeam.php:22-29`. |
| EC-20c says post-W-LOT state fails `matchesPreWave0b()` | **WRONG.** The predicate’s actual contract at spec `:1303-1312` defines post-A-1a equality as true; W-LOT produces that state at lane `LotActionPermissionDelta.php:163-181`. |
| Token-narrowing override ranges | **VERIFIED.** Aliases at `apps/api/app/Modules/Identity/Domain/User.php:56-59`; `hasPermissionTo()` at `:185-198`; `getAllPermissions()` at `:201-212`; token extraction at `:216-241`. |
| `/auth/me` effective permission path | **VERIFIED.** `AuthController::me` calls the DTO at `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:616-628`; DTO fields are `AuthUserData.php:27-28` and permission calculation is `:34-43`. |
| POS PIN payload uses a freshly loaded target principal | **VERIFIED.** Payload calls are at `apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100,148-156,218-225`; target permissions remain intentionally unnarrowed. |
| Personal access token storage/TTL | **VERIFIED.** `abilities` and `expires_at` are at `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:17-21`; global TTL at `apps/api/config/sanctum.php:43-53`; central model override at `apps/api/app/Providers/AppServiceProvider.php:196-219`. |
| Central token topology | **VERIFIED.** PATs are central through `CentralPersonalAccessToken`; the connection defaults to `autoerp_central`, not `synerivia_central` (`apps/api/config/database.php:105-135`). Rev 7 does not make the latter mistake. |
| Principal schema descriptions | **VERIFIED.** Existing email partial uniqueness is `apps/api/database/migrations/tenant/2026_03_23_000001_make_user_email_nullable.php:14-23`; original password/unique declarations are `2025_11_30_000003_create_users_table.php:16-38`; `UserStatus` includes the four stated values at `apps/api/app/Modules/Identity/Domain/Enums/UserStatus.php:10-15`. |
| Static-rule precedent and PHPStan level | **VERIFIED.** Level 8 and registered rule/service structure are `apps/api/phpstan.neon:5-8,33-47`; AST precedent exists in `apps/api/app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php:40-90`. |
| Data model is two additive tenant migrations | **VERIFIED.** All proposed columns specify type/null/default/index/FK/enum at spec `:1883-1947`; no enum-typed column is altered and no central migration is proposed. |
| `PermissionSeeder` deletion order | **VERIFIED.** `credit-notes.cancel` is declared first in 0b-1 and deletion is 0b-6 (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2128`); the only production caller is `apps/api/database/seeders/ProductionSeeder.php:68-75`. |
| Wave-0a no-overlap claim | **VERIFIED.** The listed 0a files are absent from W-LOT and T2 diffs except the settled `apps/api/tests/feature-lane-manifest.json` overlap; T1 is empty. Current task list is spec `:2120-2122`. |

## Rejected false positives

### Token narrowing

The core claim is correct for permission-based idioms:

| Idiom | Result |
|---|---|
| `can:` middleware | Narrowed: Laravel authorization reaches Gate, Spatie’s registered Gate callback, `checkPermissionTo()`, and dynamic dispatch to `User::hasPermissionTo()` (`apps/api/config/permission.php:103-107`; `apps/api/app/Modules/Identity/Domain/User.php:185-198`). |
| `$user->can()` / `cant()` | Narrowed through the same Gate path. |
| `Gate::authorize()` / `allows()` / `denies()` | Narrowed for permission abilities; ordinary policy methods remain policy calls. |
| `hasPermissionTo()` | Narrowed directly by the override. |
| `hasAnyPermission()` | Narrowed because Spatie tests each candidate through `checkPermissionTo()`/dynamic `hasPermissionTo()`. |
| `getAllPermissions()` | Narrowed for the authenticated subject whose model carries the current token. A freshly loaded target has no current token and is intentionally unnarrowed (`User.php:201-241`). |
| `hasRole()` / `hasAnyRole()` / `hasAllRoles()` | Bypasses narrowing. Eight production sites remain: `CreateDraftCountingRequest.php:35`, `InventoryCountingController.php:782,866,905,975,1297,1494`, and `DiscountPermissionResolver.php:39`. Their conversion remains a token-issuance entry condition. |
| Spatie `role:` middleware | None is registered or used. `central_admin_role` is a distinct platform guard (`apps/api/bootstrap/app.php:114-123`). |

`AuthUserData` correctly uses the subject path, while the POS PIN-holder payload correctly uses the target path. This is not a bypass defect.

### W-LOT coexistence

The catalogue design can coexist with A-1a:

- A-1a’s `provisioning_source` constraint, marker preservation, NULL-team legacy roles, and tenant-team `general_manager` are compatible with the additive `template_key`, `template_version`, `is_system`, and `customised_at` columns.
- Sync’s role resolver preserves existing `tenant_id` and marker values and creates marked `general_manager` only on the settled legal path (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1153,1938`).
- A-1a’s delta adds missing grants and revokes only manager’s old `batches.recall`, so later additive 0b grants survive (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:163-181`).
- `admin = PermissionRegistry::activeKeys()` intentionally supersedes `Permission::all()` and does not violate A-1a’s preservation contract.

### Ratchet

CSV recomputation under the exact classifier produced:

- total routes: **1,054**
- gated middleware: **642**
- super-admin/central routes: **68**
- public: **18**
- uncovered: **326**
- uncovered writes: **177**
- uncovered reads: **149**
- layer breakdown: `AUTH_ONLY=142`, `CONTROLLER=130`, `FORMREQUEST=46`, `POLICY=8`

Removing four write tombstones and classifying the six named write self-service routes plus `/auth/me` produces generation ceilings **167 writes / 148 reads**. Wave 0a closes 15 writes and 6 reads, producing **152 / 142** (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1395-1403,2120`).

This is mechanically checkable in PHPUnit by booting the live router. Merely attaching `authz.self` cannot whitelist a route: self-service classification also requires exact `(method, uri)` membership in the allow-list (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1395-1399`). A developer could deliberately edit the allow-list, but that is a reviewed/protected-baseline change, not an accidental middleware escape.

### Principals, Sanctum, and data model

No existing `users` writer is absent from rev 7’s census. It includes self-registration, provisioning, all user-management mutations, POS PIN writes, login metadata, the data migration, every demo/bootstrap create or update, verification writers, and permission-derived notification recipient queries (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:242-280`).

The reuse constraints are accounted for: nullable tenant-unique email, nullable password with kind check before hashing, `UserStatus`, Sanctum guard, PIN/login/impersonation exclusion, `pinHolders()` exclusion, notification filtering, human-only seat counts, service-account cap, memberships/location access, tenant initialization, and human-only last-admin floor.

Sanctum token storage is central and feasible. Per-row `expires_at` precedence over `sanctum.expiration`, middleware ordering, and best-effort central revocation after tenant membership removal are correctly described. The next-request fail-closed protection depends on the proposed globally attached scope middleware, which the spec explicitly includes.

The data model has complete column contracts, valid PostgreSQL CHECK expressions, additive tenant-only migrations, no PostgreSQL enum-column alteration, and no procedural backfill beyond sync/default application (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1883-1947`).

### Static guards, hardening, conventions, and waves

The permission-literal PHPStan rule is implementable at level 8 using the repository’s existing AST-rule pattern. The design acknowledges the existing baseline and constrains the new rule to targeted call/string contexts, so it need not introduce a mass generic PHPStan baseline (`apps/api/phpstan.neon:5-8,33-47`; spec `:1418-1455`).

Enum-subset-manifest, locale coverage for `en/fr/ar`, and generated TypeScript union tests are coherent. `Permission` already exists as `keyof typeof PERMISSIONS` at `apps/web/src/hooks/permissionsMap.generated.ts:312`; wave 2 removes the temporary UI-alias widening (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1464-1474`).

The last-admin floor covers every removal path: role permission replacement/deletion/removal, `UserController::update`, deactivate/delete, membership removal, service-account role removal, and sync’s admin repair. Assignment paths cannot remove the last admin. Impersonation performs no role mutation. The effective-permissions endpoint is correctly self-or-`roles.view`, not `users.assign-roles` (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1500-1542`).

Audit events use the tenant audit chain, immutable event classes, and versioned names for mutable schemas (`RoleSyncedV1` and denial events), consistent with rule 8 (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:1548-1648`).

Convention 09’s second-company, real second-location, rerun-idempotency, and tenant-only uniqueness obligations are present per wave. Convention 10 rows contain concrete evidence and dispositions. Convention 11 keeps one table/write path/operator surface per concept and adopts A-1a’s General manager terminology rather than creating a synonym.

Aside from the fleet, console-team, reset-exclusivity, and EC-20c cases recorded above, the edge register decides its expected outcomes and assigns PostgreSQL-only concurrency/constraint cases to PG rather than SQLite.

Wave 0a remains dispatchable today:

- **0a-1** tenant-scoped permission cache
- **0a-2** route-coverage ratchet and baseline, without the CI environment line
- **0a-3** `authz.self`, alias, and exact allow-list
- **0a-4** the four identity read gates
- **0a-5** 15 existing-key write gates plus the two coupon reads
- **0a-8** documentation

These remain disjoint from the active lanes except the settled additive manifest-ceiling overlap (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2116-2122`). Wave 0b and the wave-1 entrypoint replacement are not dispatchable until the findings above are corrected.

## Preserve

The fix round must preserve:

- Direction B and settled D1–D8.
- First-class human and service principals with effective permissions equal to owner grants intersected with token scope.
- Tenant-scoped roles, legacy NULL-team no-re-homing, and A-1a marker preservation.
- Exact template deltas for pristine system roles; no automatic template changes to custom or customised roles.
- Separate `matchesPreWave0b()` and `matchesVersion0()` predicates.
- The adopted W-LOT advisory key, hash function, and `roles.name, roles.id` order.
- The settled LOCKED/INITIALIZATION-ONLY partition; the Reset fix must enforce its claimed exclusivity rather than silently creating an unruled third class.
- `admin = PermissionRegistry::activeKeys()`.
- Exactly two automatic sync triggers and no migration listener.
- Ratchet figures `326 / 177 / 149`, generation ceilings `167 / 148`, and post-0a ceilings `152 / 142`.
- `roles.view` on role/permission reads and self-or-`roles.view` on effective-permission reads.
- Human-only last-admin floors.
- Central Sanctum token storage and per-token `expires_at`.
- Subject/target permission separation and the intentionally unnarrowed POS PIN-holder payload.
- Additive tenant migrations, generated TypeScript union, and en/fr/ar label coverage.
- PermissionSeeder deletion only after `credit-notes.cancel` is declared through the surviving catalogue.
- Wave 0a’s six dispatchable items and its single settled manifest overlap.

## Owner decisions required

No additional owner question is required. The findings above are engineering corrections under settled rulings.

| Question | Status |
|---|---|
| **OQ-1** — whether service accounts count toward the last-admin floor (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2216`) | Genuinely open product policy. |
| **OQ-2** — service-account token TTL (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2222`) | Genuinely open security/operations policy; code establishes only current global and POS behavior. |
| **OQ-3** — whether to retire any baselined SoD combinations (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2228`) | Genuinely open product-role policy. |
| **OQ-4** — production default for `SYNC_PERMISSIONS_ON_BOOT` after soak (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:2233`) | Genuinely open, but not safely answerable until BLOCKER B-1 restores truthful fleet failure semantics. |

VERDICT: CHANGES-REQUIRED